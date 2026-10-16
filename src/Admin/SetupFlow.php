<?php

declare(strict_types=1);

namespace Quissly\PrestaShop\Admin;

use Quissly\PrestaShop\Catalog\Products;
use Quissly\PrestaShop\Config;
use Quissly\PrestaShop\Connect\Connector;
use Quissly\PrestaShop\Credentials;
use Quissly\PrestaShop\Panel\QChatDirectory;
use Quissly\PrestaShop\Platform\Db;
use Quissly\PrestaShop\Platform\Http;
use Quissly\PrestaShop\Platform\Registry;
use Quissly\PrestaShop\Platform\Settings;
use Quissly\PrestaShop\Platform\Shop;
use Quissly\PrestaShop\Platform\Text;
use Quissly\PrestaShop\Setup\DescriptionDraft;
use Quissly\PrestaShop\Setup\Onboarding;
use Quissly\PrestaShop\Setup\SetupInput;
use Quissly\PrestaShop\Setup\PlanGate;
use Quissly\PrestaShop\Setup\StoreBilling;
use Quissly\PrestaShop\Sync\DbSyncStore;
use Quissly\PrestaShop\Sync\SyncWorker;

/**
 * Quissly Setup - the Shopify app's three-step onboarding, as the WooCommerce, Magento and
 * CS-Cart plugins have it (SetupPage, views/js/setup.js + views/css/setup.css, the same files):
 * tell us about your store (Connect creates the Quissly account), choose a plan and how to pay
 * (Manual invoice / Credit card), go live once the catalog has synced. The CS-Cart add-on's
 * handlers, line for line, with PrestaShop's data; each endpoint answers JSON (the controller).
 */
final class SetupFlow
{
    public static function onboarding(): Onboarding
    {
        return new Onboarding(new DbSyncStore(), Credentials::fromRegistry()->isConfigured(), time());
    }

    /**
     * @param array<string, string> $endpoints connect, plan, status, finish => address
     */
    public static function page(string $reload, array $endpoints, string $logoUrl): string
    {
        $onboarding = self::onboarding();
        $step = $onboarding->step();
        $credentials = Credentials::fromRegistry();
        $billing = $step === Onboarding::STEP_DETAILS ? null : self::billing();
        $employee = self::employee();
        $shopName = trim((string) \Configuration::get('PS_SHOP_NAME'));
        $text = static function (string $key): string {
            return Text::t('quissly_search.' . $key);
        };
        $endpoint = static function (string $url): array {
            return ['url' => $url, 'params' => new \stdClass()];
        };

        return SetupPage::render([
            'step'          => $step,
            'admin_email'   => $employee['email'],
            'account_email' => $credentials->accountEmail(),
            'store_name'    => SetupInput::suggestName($shopName),
            'description'   => $step === Onboarding::STEP_DETAILS ? self::draft($shopName) : '',
            'domain'        => self::domain(),
            'plans'         => $billing !== null ? $billing->plans() : null,
            'subscriptions' => $billing !== null ? $billing->subscriptions() : null,
            'logo_url'      => $logoUrl,
            'locked'        => $step === Onboarding::STEP_GOLIVE && $onboarding->isPlanLocked(),
            'services'      => $onboarding->services(),
            'config'        => [
                'reload'    => $reload,
                'csrf'      => new \stdClass(), // the admin token is in each address
                'endpoints' => array_map($endpoint, $endpoints),
                'text'      => [
                    'connecting'    => $text('su_connecting'),
                    'saving'        => $text('su_saving'),
                    'goingLive'     => $text('su_going_live'),
                    'pickPlan'      => $text('su_pick_plan'),
                    'invoiceNote'   => $text('su_invoice_note'),
                    'saveYear'      => $text('su_save_year'),
                    'payTimeout'    => $text('su_pay_timeout'),
                    'payNotYet'     => $text('su_pay_not_yet'),
                    'error'         => $text('su_error'),
                    'compare'       => $text('su_compare'),
                    'compareBack'   => $text('su_compare_back'),
                    'emailRequired' => $text('su_email_required'),
                    'emailInvalid'  => $text('su_email_invalid'),
                    'emailTooLong'  => $text('su_email_too_long'),
                    'emailPublic'   => $text('su_email_public'),
                    'nameInvalid'   => $text('su_name_invalid'),
                    'blockers'      => [
                        'emailMissing' => $text('su_block_email_missing'),
                        'emailInvalid' => $text('su_block_email_invalid'),
                        'nameMissing'  => $text('su_block_name_missing'),
                        'nameInvalid'  => $text('su_block_name_invalid'),
                        'sellMissing'  => $text('su_block_sell_missing'),
                        'none'         => $text('su_nothing_charged'),
                    ],
                    'chipWorking'   => $text('su_setting_up'),
                    'chipReady'     => $text('su_ready'),
                    'states'        => [
                        'done'    => $text('su_done'),
                        'running' => $text('su_running'),
                        'pending' => $text('su_pending'),
                        'failed'  => $text('su_failed'),
                    ],
                    'intro'         => [
                        'building'  => $text('su_intro_building'),
                        'importing' => $text('su_intro_importing'),
                        'failed'    => $text('su_intro_failed'),
                        'ready'     => $text('su_intro_ready'),
                    ],
                    'body'          => [
                        'building' => $text('su_body_building'),
                        'built'    => $text('su_body_built'),
                    ],
                    'note'          => [
                        'building' => $text('su_note_building'),
                        'waiting'  => $text('su_note_waiting'),
                        'ready'    => $text('su_note_ready'),
                    ],
                ],
            ],
        ]);
    }

    /** Step 1: create the Quissly account (one-click Connect) for this store. */
    public static function connect(): array
    {
        $email = (string) \Tools::getValue('email');
        $name = trim((string) \Tools::getValue('store_name'));
        $description = trim((string) \Tools::getValue('description'));
        $problem = SetupInput::emailProblem($email);
        if ($problem !== '') {
            return ['ok' => false, 'message' => Text::t('quissly_search.su_email_' . $problem)];
        }
        if ($name === '' || $description === '') {
            return ['ok' => false, 'message' => Text::t('quissly_search.su_required_both')];
        }
        if (!SetupInput::isNameValid($name)) {
            return ['ok' => false, 'message' => Text::t('quissly_search.su_name_chars')];
        }
        if (mb_strlen($description) > 500) {
            return ['ok' => false, 'message' => Text::t('quissly_search.su_description_long')];
        }
        @set_time_limit(Connector::TIMEOUT_SECONDS + 60);
        $onboarding = self::onboarding();
        $inSetup = !$onboarding->isComplete();
        $employee = self::employee();
        $environment = trim((string) Registry::get('config.quissly_environment'));
        $result = (new Connector(Credentials::credentialStore(), self::consolePost(Connector::TIMEOUT_SECONDS)))->connect($email, [
            'domain'            => self::domain(),
            'name'              => mb_substr($name, 0, 100),
            'first_name'        => $employee['firstname'] !== '' ? $employee['firstname'] : null,
            'last_name'         => $employee['lastname'] !== '' ? $employee['lastname'] : null,
            'environment'       => $environment !== '' ? $environment : Config::DEFAULT_ENVIRONMENT,
            'already_connected' => Credentials::fromRegistry()->source() === Credentials::SOURCE_CONFIG,
            'description'       => $description,
        ]);
        if (!$result['ok']) {
            return ['ok' => false, 'message' => self::message($result['message'])];
        }
        self::refreshChat(new DbSyncStore(), Credentials::fromRegistry());
        if ($inSetup) {
            self::onboarding()->connected();
        }

        return ['ok' => true];
    }

    /** Step 2: the plan - checkout, a paid one by manual invoice, or a free one. */
    public static function plan(): array
    {
        if (!Credentials::fromRegistry()->isConfigured()) {
            return ['ok' => false, 'message' => Text::t('quissly_search.su_connect_first')];
        }
        $onboarding = self::onboarding();
        if ($onboarding->isPlanLocked()) {
            return ['ok' => false, 'message' => Text::t('quissly_search.su_plan_locked')];
        }
        $billing = self::billing();
        $op = (string) \Tools::getValue('op');
        $family = (string) \Tools::getValue('family');

        if ($op === 'checkout') {
            $checkout = $billing->checkout((string) \Tools::getValue('plan_id'), (string) \Tools::getValue('billing_cycle', 'monthly'));
            if (!$checkout['ok']) {
                return ['ok' => false, 'message' => self::message($checkout['message'])];
            }
            if ($checkout['kind'] === 'free_activated') {
                $onboarding->planChosen([$family !== '' ? $family : 'qsearch']);

                return ['ok' => true, 'kind' => 'active'];
            }

            return ['ok' => true, 'kind' => 'pay', 'pay_url' => $checkout['pay_url']];
        }
        if ($op === 'check' || $op === 'keep') {
            $subscriptions = $billing->subscriptions();
            PlanGate::remember(new DbSyncStore(), $subscriptions, time());
            if ($subscriptions === null) {
                return ['ok' => false, 'message' => self::message(StoreBilling::message(0, ''))];
            }
            $live = StoreBilling::livePlans($subscriptions);
            $active = $family !== '' ? isset($live[$family]) : $live !== [];
            if ($active) {
                $onboarding->planChosen(array_keys($live));
            }

            return ['ok' => true, 'active' => $active, 'message' => $active ? '' : Text::t('quissly_search.su_pay_not_yet')];
        }
        // A paid plan paid by manual invoice: no checkout, recorded, on to Go live. Quissly's store
        // billing API has no invoice option yet, so the choice stays in the store (and its log).
        if ($op === 'invoice') {
            $planId = (string) \Tools::getValue('plan_id');
            $plan = null;
            foreach ((array) (($billing->plans() ?? [])['plans'] ?? []) as $row) {
                if ((string) ($row['id'] ?? '') === $planId && empty($row['is_free'])) {
                    $plan = $row;
                }
            }
            if ($plan === null) {
                return ['ok' => false, 'message' => Text::t('quissly_search.su_pick_plan')];
            }
            $planFamily = (string) ($plan['family'] ?? 'qsearch');
            $name = SetupPage::planName($planFamily, (string) ($plan['tier'] ?? ''));
            $cycle = \Tools::getValue('billing_cycle') === 'annual' ? 'annual' : 'monthly';
            $onboarding->planChosen([$planFamily]);
            $store = new DbSyncStore();
            $store->setState('setup_invoice', ['plan_id' => $planId, 'plan' => $name, 'family' => $planFamily, 'cycle' => $cycle, 'at' => time()]);
            $store->log(sprintf('Plan chosen for manual invoice: %s, billed %s.', $name, $cycle), time());

            return ['ok' => true, 'kind' => 'invoice'];
        }
        // Only while the plans cannot be loaded, so a Quissly outage never strands a merchant.
        if ($op === 'later' && $billing->plans() === null) {
            $onboarding->planChosen();

            return ['ok' => true];
        }

        return ['ok' => false, 'message' => Text::t('quissly_search.su_pick_plan')];
    }

    /** Step 3's progress: the account's services, then the catalog import. */
    public static function status(): array
    {
        $onboarding = self::onboarding();
        if (\Tools::getValue('retry') === '1' && $onboarding->syncFailed()) {
            (new DbSyncStore())->setState(SyncWorker::STATE_REFUSAL, null);
            self::startFullSync();
        }
        if (!$onboarding->isComplete()) {
            self::advance($onboarding);
        }
        $status = self::onboarding()->status(!empty((new DbSyncStore())->getState(QChatDirectory::STATE)['agent_id']));
        foreach ($status['rows'] as $key => $row) {
            $status['rows'][$key]['detail'] = $row['detail'] === null ? '' : Text::t($row['detail'][0], $row['detail'][1]);
        }
        $status['technical'] = $status['technical'] === null ? '' : Text::t($status['technical'][0], $status['technical'][1]);

        return ['ok' => true] + $status;
    }

    /**
     * Finish Setup: what the plan includes goes live; "Do this later": done, nothing switched on.
     *
     * @return array the answer; 'redirect' (the Dashboard) is added by the controller
     */
    public static function finish(): array
    {
        $onboarding = self::onboarding();
        $store = new DbSyncStore();
        if (\Tools::getValue('later') === '1') {
            if (!$onboarding->canSaveForLater()) {
                return ['ok' => false, 'message' => Text::t('quissly_search.su_still_building')];
            }
            self::advance($onboarding);
            $onboarding->complete();
            $store->log('Quissly Setup saved without going live; QSearch stays off until it is switched on.', time());

            return ['ok' => true, 'notice' => Text::t('quissly_search.su_saved_later')];
        }
        if (!$onboarding->isReady() || $onboarding->holdRemaining() > 0) {
            return ['ok' => false, 'message' => Text::t('quissly_search.su_not_ready')];
        }
        // Search (also with no plan known) and, with a chat plan, the assistant once its agent is known.
        $services = $onboarding->services();
        $search = $services === [] || in_array('qsearch', $services, true);
        if ($search) {
            Settings::set('search_interception', 'Y');
        }
        if (in_array('qchat', $services, true) && !empty($store->getState(QChatDirectory::STATE)['agent_id'])) {
            Settings::set('enable_qchat', 'Y');
        }
        $onboarding->complete();
        $store->log('Quissly Setup finished; ' . ($services === [] ? 'qsearch' : implode(' and ', $services)) . ' is live.', time());

        return ['ok' => true, 'notice' => Text::t($search ? 'quissly_search.su_live_now' : 'quissly_search.su_live_chat_now')];
    }

    /** The store billing API (plans, checkout, subscriptions), as the store's own key. */
    public static function billing(): StoreBilling
    {
        $credentials = Credentials::fromRegistry();

        // Not connected: no key, so Quissly answers 401 and the pages show their "not connected" state.
        return new StoreBilling($credentials->isConfigured() ? $credentials->bearerToken() : '', Shop::httpGet(15), Shop::httpPost(15));
    }

    /**
     * The first sync once the new account has settled, then a few seconds of it per status poll:
     * while Setup is open its polling keeps the first sync moving, cron or not.
     */
    private static function advance(Onboarding $onboarding): void
    {
        if ($onboarding->claimFirstSync()) {
            Settings::set('search_interception', 'N');
            self::startFullSync();
            $store = new DbSyncStore();
            if (empty($store->getState(QChatDirectory::STATE)['agent_id'])) {
                self::refreshChat($store, Credentials::fromRegistry());
            }
        }
        if (!empty((new DbSyncStore())->getState(SyncWorker::STATE_PROGRESS)['running'])) {
            @set_time_limit(60);
            \Quissly\PrestaShop\Sync\Sync::run(8, 'setup');
        }
    }

    /** The whole catalog queued for Quissly (the cron and Setup's status polls send it). */
    private static function startFullSync(): void
    {
        \Quissly\PrestaShop\Sync\Sync::startFull();
    }

    private static function refreshChat(DbSyncStore $store, Credentials $credentials): array
    {
        return QChatDirectory::refresh($store, $credentials, self::consolePost(10), self::consoleGet(10), time());
    }

    /** The signed-in employee: what Connect and step 1 prefill. */
    private static function employee(): array
    {
        $employee = \Context::getContext()->employee ?? null;

        return [
            'email'     => is_object($employee) ? (string) $employee->email : '',
            'firstname' => is_object($employee) ? (string) $employee->firstname : '',
            'lastname'  => is_object($employee) ? (string) $employee->lastname : '',
        ];
    }

    /** The shop's own domain (what Quissly registers the store under). */
    private static function domain(): string
    {
        return (string) \Tools::getShopDomainSsl(false);
    }

    /** Step 1's "What do you sell?", drafted from the catalog (the Shopify app's draft). */
    private static function draft(string $shopName): string
    {
        try {
            $context = \Context::getContext();
            $count = Products::countActive();
            $collections = [];
            $prices = null;
            if ($count > 0) {
                foreach (Db::all(
                    'SELECT cl.name AS title, cl.link_rewrite AS handle, COUNT(DISTINCT cp.id_product) AS product_count'
                    . ' FROM ?:category c'
                    . ' JOIN ?:category_lang cl ON cl.id_category = c.id_category AND cl.id_lang = ?i AND cl.id_shop = ?i'
                    . ' JOIN ?:category_product cp ON cp.id_category = c.id_category'
                    . ' JOIN ?:product_shop ps ON ps.id_product = cp.id_product AND ps.id_shop = ?i AND ps.active = 1'
                    . ' WHERE c.active = 1 AND c.is_root_category = 0 AND c.id_parent > 0 GROUP BY c.id_category',
                    (int) $context->language->id,
                    (int) $context->shop->id,
                    (int) $context->shop->id
                ) as $row) {
                    $collections[] = ['title' => (string) $row['title'], 'handle' => (string) $row['handle'], 'product_count' => (int) $row['product_count']];
                }
                $range = Db::row('SELECT MIN(price) AS lo, MAX(price) AS hi FROM ?:product_shop WHERE id_shop = ?i AND active = 1', (int) $context->shop->id);
                if (!empty($range['hi'])) {
                    $prices = ['min' => (float) $range['lo'], 'max' => (float) $range['hi'], 'currency' => (string) $context->currency->iso_code];
                }
            }

            return (string) DescriptionDraft::compose([
                'shop_name'        => $shopName,
                'meta_description' => null,
                'product_count'    => $count,
                'product_kinds'    => [],
                'collections'      => $collections,
                'location'         => [
                    'city'    => (string) \Configuration::get('PS_SHOP_CITY'),
                    'country' => (string) \Country::getNameById((int) $context->language->id, (int) \Configuration::get('PS_SHOP_COUNTRY_ID')),
                ],
                'prices'           => $prices,
                'vendors'          => [],
            ]);
        } catch (\Throwable $e) {
            return '';
        }
    }

    private static function message(string $message): string
    {
        return strpos($message, 'quissly_search.') === 0 ? Text::t($message) : $message;
    }

    private static function consolePost(int $timeout): callable
    {
        return static function (string $url, string $body) use ($timeout): ?array {
            $response = Http::post($url, $body, ['headers' => ['Content-Type: application/json'], 'timeout' => $timeout, 'connection_timeout' => min($timeout, 20)]);
            $status = Http::getStatus();

            return $response === false || $status === 0 ? null : ['status' => $status, 'body' => (string) $response];
        };
    }

    private static function consoleGet(int $timeout): callable
    {
        return Shop::httpGet($timeout);
    }

    private function __construct()
    {
    }
}
