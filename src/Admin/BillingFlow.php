<?php

declare(strict_types=1);

namespace Quissly\PrestaShop\Admin;

use Quissly\PrestaShop\Platform\Text;
use Quissly\PrestaShop\Setup\PlanGate;
use Quissly\PrestaShop\Setup\StoreBilling;
use Quissly\PrestaShop\Sync\DbSyncStore;

/**
 * Quissly > Billing: the CS-Cart add-on's BillingPage (plans, usage, invoices in the Shopify
 * app's Settings design, views/js/billing.js + views/css/billing.css) and every plan action of
 * the store billing API (manage()), line for line. An action that is done answers 'notice' -
 * the controller shows it after billing.js reloads the page.
 */
final class BillingFlow
{
    public static function page(string $endpoint, string $reload): string
    {
        $billing = SetupFlow::billing();
        $text = static function (string $key): string {
            return Text::t('quissly_search.' . $key);
        };
        $subscriptions = $billing->subscriptions();
        PlanGate::remember(new DbSyncStore(), $subscriptions, time());

        return BillingPage::render(
            BillingPage::view(
                $billing->plans(),
                $subscriptions,
                static function (string $id) use ($billing): ?array {
                    return $billing->usage($id);
                },
                $billing->invoices()
            ),
            [
                'endpoint' => $endpoint,
                'reload'   => $reload,
                'csrf'     => new \stdClass(),
                'text'     => [
                    'working'       => $text('bi_working'),
                    'error'         => $text('su_error'),
                    'payWaiting'    => $text('bi_pay_waiting'),
                    'payTimeout'    => $text('bi_pay_timeout'),
                    'close'         => $text('bi_close'),
                    'titleChange'   => $text('bi_title_change'),
                    'titleTopup'    => $text('bi_title_topup'),
                    'titleCancel'   => $text('bi_title_cancel'),
                    'titlePay'      => $text('bi_title_pay'),
                    'titleError'    => $text('bi_title_error'),
                    'confirmCancel' => $text('bi_cancel_plan'),
                    'titleRefund'   => $text('bi_request_refund'),
                    'titleClaim'    => $text('bi_claim_title'),
                    'confirmRefund' => $text('bi_refund'),
                    'sendClaim'     => $text('bi_send_request'),
                    'claimLength'   => $text('bi_claim_length'),
                    'claimCount'    => '%1 / %2',
                    'topupRange'    => $text('bi_at_range'),
                ],
            ]
        );
    }

    /** One plan action, posted by billing.js (op, id, plan_id, ...). */
    public static function manage(): array
    {
        $billing = SetupFlow::billing();
        $op = (string) \Tools::getValue('op');
        $id = (string) \Tools::getValue('id');
        $planId = (string) \Tools::getValue('plan_id');
        $money = static function ($value, string $currency): string {
            $symbols = ['USD' => '$', 'EUR' => '€', 'GBP' => '£'];
            $number = number_format((float) $value, 2);

            return isset($symbols[$currency]) ? $symbols[$currency] . $number : $currency . ' ' . $number;
        };
        $done = static function (array $result, string $notice): array {
            return $result['ok']
                ? ['ok' => true, 'done' => true, 'notice' => $notice]
                : ['ok' => false, 'message' => self::message($result['message'])];
        };
        $fail = static function (array $result): array {
            return ['ok' => false, 'message' => self::message($result['message'])];
        };

        switch ($op) {
            case 'checkout':
                $checkout = $billing->checkout($planId, (string) \Tools::getValue('billing_cycle'), max(0.0, (float) \Tools::getValue('auto_topup_max_usd', 0)));
                if ($checkout['ok'] && $checkout['kind'] === 'free_activated') {
                    return ['ok' => true, 'done' => true, 'notice' => Text::t('quissly_search.bi_free_on')];
                }

                return $checkout['ok'] ? ['ok' => true, 'pay_url' => $checkout['pay_url']] : $fail($checkout);
            case 'check':
                $subscriptions = $billing->subscriptions();
                PlanGate::remember(new DbSyncStore(), $subscriptions, time());
                $active = $subscriptions !== null && isset(StoreBilling::livePlans($subscriptions)[(string) \Tools::getValue('family')]);

                return ['ok' => true, 'active' => $active] + ($active ? ['notice' => Text::t('quissly_search.bi_paid_on')] : []);
            case 'change_preview':
                $result = $billing->manage($op, $id, ['plan_id' => $planId]);
                if (!$result['ok']) {
                    return $fail($result);
                }
                $preview = $result['data'];
                $kind = (string) ($preview['kind'] ?? '');
                if ($kind === 'upgrade') {
                    $summary = Text::t('quissly_search.bi_preview_upgrade', ['[amount]' => $money($preview['charge_now'] ?? 0, (string) ($preview['currency'] ?? 'USD'))]);
                } elseif ($kind === 'downgrade') {
                    $summary = Text::t('quissly_search.bi_preview_downgrade', ['[date]' => BillingPage::date((string) ($preview['effective_at'] ?? ''))]);
                } elseif ($kind === 'trial_swap') {
                    $summary = Text::t('quissly_search.bi_preview_trial');
                } else {
                    $summary = Text::t('quissly_search.bi_preview_other');
                }

                return ['ok' => true, 'summary' => $summary, 'confirm' => Text::t('quissly_search.bi_change_plan')];
            case 'change':
                $result = $billing->manage($op, $id, ['plan_id' => $planId]);
                $kind = (string) ($result['data']['kind'] ?? '');

                return $done($result, $kind === 'scheduled'
                    ? Text::t('quissly_search.bi_done_scheduled', ['[date]' => BillingPage::date((string) ($result['data']['subscription']['current_period_end'] ?? ''))])
                    : Text::t($kind === 'processing' ? 'quissly_search.bi_done_processing' : 'quissly_search.bi_done_changed'));
            case 'topup_preview':
                $result = $billing->manage($op, $id);
                if (!$result['ok']) {
                    return $fail($result);
                }

                return ['ok' => true, 'confirm' => Text::t('quissly_search.bi_buy_now'), 'summary' => Text::t('quissly_search.bi_preview_topup', [
                    '[count]'  => number_format((int) ($result['data']['requests'] ?? 0)),
                    '[amount]' => $money($result['data']['charge_now'] ?? 0, (string) ($result['data']['currency'] ?? 'USD')),
                ])];
            case 'topup':
                $result = $billing->manage($op, $id, ['idempotency_key' => (string) \Tools::getValue('idempotency_key')]);

                return $done($result, ($result['data']['kind'] ?? '') === 'processing'
                    ? Text::t('quissly_search.bi_topup_processing')
                    : Text::t('quissly_search.bi_topup_added', ['[count]' => number_format((int) ($result['data']['requests'] ?? 0))]));
            case 'cancel':
            case 'resume':
            case 'abort':
                return $done($billing->manage($op, $id), Text::t(['cancel' => 'quissly_search.bi_done_cancel', 'resume' => 'quissly_search.bi_done_resume', 'abort' => 'quissly_search.bi_done_abort'][$op]));
            case 'auto_topup':
                $max = max(0.0, round((float) \Tools::getValue('max_usd', 0), 2));

                return $done($billing->manage($op, $id, ['max_usd' => $max]), $max > 0
                    ? Text::t('quissly_search.bi_at_saved_on', ['[price]' => $money($max, 'USD')])
                    : Text::t('quissly_search.bi_at_saved_off'));
            case 'refund_preview':
                $result = $billing->manage($op, $id);

                return $result['ok'] ? ['ok' => true] + BillingPage::refundAnswer($result['data']) : $fail($result);
            case 'refund':
                $result = $billing->manage($op, $id);
                $amount = $money($result['data']['amount'] ?? 0, (string) ($result['data']['currency'] ?? 'USD'));

                return $done($result, Text::t(!empty($result['data']['ends_plan']) ? 'quissly_search.bi_refund_done_ends' : 'quissly_search.bi_refund_done_extras', ['[amount]' => $amount]));
            case 'refund_claim':
                $reason = trim((string) \Tools::getValue('reason'));
                $length = mb_strlen($reason);
                if ($length < BillingPage::CLAIM_MIN || $length > BillingPage::CLAIM_MAX) {
                    return ['ok' => false, 'message' => Text::t('quissly_search.bi_claim_length')];
                }
                $result = $billing->manage($op, $id, ['reason' => $reason]);

                return $done($result, Text::t('quissly_search.bi_claim_sent', [
                    '[ticket]' => (string) ($result['data']['ticket_number'] ?? $result['data']['ticket_id'] ?? ''),
                    '[days]'   => (string) (int) ($result['data']['reply_within_business_days'] ?? 3),
                ]));
            case 'payment_method':
            case 'invoice_pdf':
                $result = $billing->manage($op, $id);

                return $result['ok'] ? ['ok' => true, 'url' => (string) ($result['data']['url'] ?? $result['data']['pay_url'] ?? '')] : $fail($result);
        }

        return ['ok' => false, 'message' => Text::t('quissly_search.bi_unknown')];
    }

    private static function message(string $message): string
    {
        return strpos($message, 'quissly_search.') === 0 ? Text::t($message) : $message;
    }

    private function __construct()
    {
    }
}
