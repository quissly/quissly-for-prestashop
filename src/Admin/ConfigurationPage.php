<?php

declare(strict_types=1);

namespace Quissly\PrestaShop\Admin;

use Quissly\PrestaShop\Catalog\ProductLookup;
use Quissly\PrestaShop\Credentials;
use Quissly\PrestaShop\Features;
use Quissly\PrestaShop\MostLoved;
use Quissly\PrestaShop\Panel\QChatDirectory;
use Quissly\PrestaShop\Platform\Settings;
use Quissly\PrestaShop\Platform\Shop;
use Quissly\PrestaShop\Platform\Text;
use Quissly\PrestaShop\SearchSuggestions;
use Quissly\PrestaShop\Setup\PlanGate;
use Quissly\PrestaShop\Setup\StoreBilling;
use Quissly\PrestaShop\Showcase\ShowcaseRunner;
use Quissly\PrestaShop\Sync\CatalogFeatures;
use Quissly\PrestaShop\Sync\DbSyncStore;

/**
 * Quissly > Configuration: the settings in sections that fold, as the WooCommerce and Magento
 * plugins' Configuration (Features open, the rest closed, the page remembers which ones the
 * merchant left open - views/js/configuration.js), drawn with PrestaShop's own controls
 * (switches, help text under each control). Search Examples / Search Suggestions / Most Loved /
 * Catalog data are the CS-Cart add-on's editors (SuggestionsView, MostLovedView, CatalogView,
 * views/js/config.js + most-loved-picker.js); one Save saves them all (save()).
 */
final class ConfigurationPage
{
    /** The Features section's settings, in the other plugins' order. */
    private const FEATURES = ['search_interception', 'enable_overlay', 'overlay_mount_selector', 'enable_quick', 'quick_style', 'enable_voice', 'enable_image', 'enable_qchat', 'enable_events'];

    /** The settings drawn as switches; the rest of Settings::DEFAULTS are text, lists or selects. */
    private const SWITCHES = ['search_interception', 'enable_overlay', 'enable_quick', 'enable_voice', 'enable_image', 'enable_qchat', 'enable_events', 'overlay_suggestions', 'overlay_recent', 'most_loved'];

    /** @var ?array<string, string> the switches the store's plan leaves out, while the page is drawn */
    private static $locked = null;

    /** @param string $action the form's address (the page itself, with its token) */
    public static function render(string $action, string $searchUrl): string
    {
        $store = new DbSyncStore();
        $current = self::remoteSuggestions($store);
        // What the plan includes, asked afresh: a plan changed in Billing shows at once.
        $credentials = Credentials::fromRegistry();
        if ($credentials->isConfigured()) {
            PlanGate::remember($store, (new StoreBilling($credentials->bearerToken(), Shop::httpGet(5), Shop::httpPost(5)))->subscriptions(), time());
        }
        self::$locked = Features::locked();

        $features = '';
        foreach (self::FEATURES as $id) {
            $features .= self::row($id);
        }

        return '<form method="post" action="' . self::e($action) . '" class="q-config form-horizontal" id="quissly_configuration">'
            . '<input type="hidden" name="submitQuisslyConfiguration" value="1">'
            . self::section('features', Text::t('quissly_search.setting_label.features_header'), $features, true)
            . self::section('suggestions', Text::t('quissly_search.setting_label.suggestions_header'), self::wide(SuggestionsView::examples(
                $current,
                Shop::languages(),
                ($current['generated'] ?? []) !== [] ? $current['generated'] : ShowcaseRunner::generated($store),
                false
            )))
            . self::section('buttons', Text::t('quissly_search.setting_label.buttons_header'), self::row('overlay_suggestions')
                . self::wide(SuggestionsView::buttons($current, Shop::languages(), SearchSuggestions::localButtons($store)))
                . self::row('overlay_recent'))
            . self::section('most_loved', Text::t('quissly_search.setting_label.most_loved_header'), self::mostLoved($searchUrl))
            . self::section('catalog', Text::t('quissly_search.setting_label.catalog_header'), self::wide(CatalogView::render(CatalogFeatures::catalog(), CatalogFeatures::choices($store))))
            . self::section('updates', Text::t('quissly_search.setting_label.updates_header'), self::row('update_check_every'))
            . '<div class="panel-footer q-config__footer"><button type="submit" class="btn btn-primary pull-right" name="submitQuisslyConfiguration">'
            . '<i class="process-icon-save"></i> ' . self::e(Text::t('quissly_search.save')) . '</button></div>'
            . '</form>';
    }

    /**
     * The form, posted: the settings, then the editors (written to Quissly only when changed).
     *
     * @return list<string> what went wrong, for the page's error notices ([] = saved)
     */
    public static function save(): array
    {
        $errors = [];
        // A switch the plan leaves out is drawn disabled and posts nothing: what was saved stays.
        $locked = Features::locked();
        foreach (Settings::DEFAULTS as $id => $default) {
            if (isset($locked[$id])) {
                continue;
            }
            if (in_array($id, self::SWITCHES, true)) {
                Settings::set($id, \Tools::getValue($id) === '1' ? 'Y' : 'N');
            } elseif (\Tools::getIsset($id)) {
                $value = trim((string) \Tools::getValue($id));
                if ($id === 'most_loved_shown' || $id === 'most_loved_backups') {
                    $value = implode("\n", MostLoved::codes($value, $id === 'most_loved_shown' ? MostLoved::SHOWN : MostLoved::BACKUPS));
                } elseif ($id === 'quick_style' && !in_array($value, ['rows', 'carousel'], true)) {
                    $value = 'rows';
                } elseif ($id === 'update_check_every' && !in_array($value, ['5m', '30m', '1h', '6h', '12h', '1d', 'never'], true)) {
                    $value = '30m';
                }
                Settings::set($id, $value);
            }
        }

        $store = new DbSyncStore();
        $credentials = Credentials::fromRegistry();
        $serviceId = QChatDirectory::cachedSearchServiceId($store);

        // Search Examples: kept in Quissly, per language; written back only when they changed.
        if (\Tools::getIsset('quissly_suggestions_present') && $credentials->isConfigured()) {
            $main = Shop::mainLanguage();
            $lists = Shop::postedLists('quissly_examples');
            $current = SearchSuggestions::read($serviceId, Shop::httpGet(10));
            if ($lists !== null && $current !== null) {
                $queries = $lists[$main] ?? [];
                unset($lists[$main]);
                $enabled = \Tools::getValue('quissly_typing_enabled') === 'Y';
                $error = '';
                foreach (array_merge([$queries], array_values($lists)) as $list) {
                    $error = $error !== '' ? $error : SearchSuggestions::validate($list);
                }
                if ($error === '' && ($current['enabled'] !== $enabled || $current['queries'] !== $queries || $current['by_language'] !== array_filter($lists))) {
                    $error = SearchSuggestions::save($store, $credentials, $serviceId, $enabled, $queries, $lists, $main, Shop::httpPost(10), Shop::httpGet(10), Shop::httpPut(10));
                }
                if ($error !== '') {
                    $errors[] = Text::t($error);
                }
            }
        }

        // Search Suggestions: the buttons' choice and Manual lists, kept in Quissly (and a copy here).
        if (\Tools::getIsset('quissly_buttons_present')) {
            $mode = \Tools::getValue('quissly_chips_mode') === 'manual' ? 'manual' : 'automatic';
            $lists = Shop::postedLists('quissly_chips_manual') ?? SearchSuggestions::localButtons($store)['lists'];
            $error = '';
            foreach ($lists as $list) {
                $error = $error !== '' ? $error : SearchSuggestions::validateButtons($list);
            }
            if ($error === '' && $credentials->isConfigured()) {
                $current = SearchSuggestions::read($serviceId, Shop::httpGet(10));
                $local = SearchSuggestions::localButtons($store);
                $unchanged = $current !== null && $current['chips_mode'] === $mode && $current['manual_chips'] === array_filter($lists);
                if (!$unchanged || $local['mode'] !== $mode || $local['lists'] !== array_filter($lists)) {
                    $error = SearchSuggestions::saveButtons($store, $credentials, $serviceId, $mode, $lists, Shop::httpPost(10), Shop::httpGet(10), Shop::httpPut(10));
                }
            }
            if ($error !== '') {
                $errors[] = Text::t($error);
            }
        }

        // Catalog data: a changed checklist re-sends the whole catalog with the new features.
        if (\Tools::getIsset('quissly_catalog_offered')
            && CatalogFeatures::save($store, (array) \Tools::getValue('quissly_catalog_offered'), (array) \Tools::getValue('quissly_catalog_features', []))
            && \Quissly\PrestaShop\Sync\Sync::startFull() !== null
        ) {
            SetupGate::notify(Text::t('quissly_search.catalog_resync'));
        }

        return $errors;
    }

    /** What Quissly holds for the store's search (lists, buttons), null while not connected. */
    private static function remoteSuggestions(DbSyncStore $store): ?array
    {
        if (!Credentials::fromRegistry()->isConfigured()) {
            return null;
        }
        try {
            return SearchSuggestions::read(QChatDirectory::cachedSearchServiceId($store), Shop::httpGet(10));
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Most Loved Products: the switch, the two lists with their pickers, what needs fixing. */
    private static function mostLoved(string $searchUrl): string
    {
        $shown = MostLoved::codes((string) Settings::get('most_loved_shown'), MostLoved::SHOWN);
        $backups = MostLoved::codes((string) Settings::get('most_loved_backups'), MostLoved::BACKUPS);
        $enabled = Settings::on('most_loved');
        $picks = [];
        foreach ([[$shown, false], [$backups, true]] as list($codes, $backup)) {
            foreach ($codes as $code) {
                $picks[] = ['code' => $code, 'backup' => $backup] + ProductLookup::state($code);
            }
        }
        $state = static function (string $code): array {
            return ProductLookup::state($code);
        };

        return self::row('most_loved')
            . self::row('most_loved_shown', self::picker('shown', MostLoved::SHOWN, $shown, $searchUrl))
            . self::row('most_loved_backups', self::picker('backups', MostLoved::BACKUPS, $backups, $searchUrl))
            . self::wide(MostLovedView::render($enabled, $picks, $enabled ? MostLoved::problems($shown, $backups, $state) : null));
    }

    /** @param list<string> $codes the list's picks */
    private static function picker(string $list, int $max, array $codes, string $searchUrl): string
    {
        $count = static function (string $key): string {
            return str_replace(['[count]', '[max]'], ['%1', '%2'], Text::t($key));
        };
        $config = [
            'field'     => '#' . Settings::key('most_loved_' . $list),
            'list'      => $list,
            'max'       => $max,
            'searchUrl' => $searchUrl,
            'items'     => array_map([ProductLookup::class, 'item'], $codes),
            'text'      => [
                'placeholder' => Text::t('quissly_search.most_loved_search'),
                'remove'      => Text::t('quissly_search.most_loved_remove'),
                'move'        => Text::t('quissly_search.most_loved_move'),
                'moveHint'    => Text::t('quissly_search.most_loved_move_hint'),
                'searching'   => Text::t('quissly_search.most_loved_searching'),
                'none'        => Text::t('quissly_search.most_loved_none'),
                'error'       => Text::t('quissly_search.most_loved_search_error'),
                'picked'      => Text::t('quissly_search.most_loved_picked'),
                'inOther'     => Text::t($list === 'shown' ? 'quissly_search.most_loved_in_backups' : 'quissly_search.most_loved_in_shown'),
                'count'       => $count('quissly_search.most_loved_count'),
                'full'        => $count('quissly_search.most_loved_full'),
            ],
        ];

        return '<div class="q-picker" data-q-picker data-config="' . self::e((string) json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '"></div>';
    }

    /**
     * One setting: its label in PrestaShop's label column, its control (switch, field, list or
     * select) with the description under it.
     */
    private static function row(string $id, string $after = ''): string
    {
        $name = self::e($id);
        $key = self::e(Settings::key($id));
        $value = (string) Settings::get($id);
        if (in_array($id, self::SWITCHES, true)) {
            // Not in the store's plan (PlanGate): shown off and disabled, with where to add it.
            $lockedBy = (string) ((self::$locked ?? [])[$id] ?? '');
            $on = $value === 'Y' && $lockedBy === '';
            $disabled = $lockedBy !== '' ? ' disabled' : '';
            $control = '<span class="switch prestashop-switch fixed-width-lg' . ($lockedBy !== '' ? ' q-config__locked' : '') . '">'
                . '<input type="radio" name="' . $name . '" id="' . $key . '_on" value="1"' . ($on ? ' checked' : '') . $disabled . '>'
                . '<label for="' . $key . '_on">' . self::e(Text::t('quissly_search.yes')) . '</label>'
                . '<input type="radio" name="' . $name . '" id="' . $key . '_off" value="0"' . ($on ? '' : ' checked') . $disabled . '>'
                . '<label for="' . $key . '_off">' . self::e(Text::t('quissly_search.no')) . '</label>'
                . '<a class="slide-button btn"></a></span>';
            if ($lockedBy !== '') {
                $after .= '<p class="help-block q-config__plan">' . self::e(Text::t('quissly_search.plan_lacks_' . $lockedBy))
                    . ' <a href="' . self::e(Shop::adminUrl('AdminQuisslyBilling')) . '">' . self::e(Text::t('quissly_search.plan_add_in_billing')) . '</a></p>';
            }
        } elseif ($id === 'quick_style' || $id === 'update_check_every') {
            $options = $id === 'quick_style' ? ['rows', 'carousel'] : ['5m', '30m', '1h', '6h', '12h', '1d', 'never'];
            $control = '<select name="' . $name . '" id="' . $key . '" class="fixed-width-xxl">';
            foreach ($options as $option) {
                $control .= '<option value="' . $option . '"' . ($value === $option ? ' selected' : '') . '>'
                    . self::e(Text::t('quissly_search.setting_variant.' . $id . '.' . $option)) . '</option>';
            }
            $control .= '</select>';
        } elseif ($id === 'most_loved_shown' || $id === 'most_loved_backups') {
            $control = '<textarea name="' . $name . '" id="' . $key . '" rows="5" class="q-config__codes">' . self::e($value) . '</textarea>';
        } else {
            $control = '<input type="text" name="' . $name . '" id="' . $key . '" value="' . self::e($value) . '" class="fixed-width-xxl">';
        }
        $help = Text::t('quissly_search.setting_tooltip.' . $id);
        $help = $help === 'quissly_search.setting_tooltip.' . $id ? '' : '<p class="help-block">' . self::e($help) . '</p>';

        return '<div class="form-group">'
            . '<label class="control-label col-lg-3" for="' . $key . '">' . self::e(Text::t('quissly_search.setting_label.' . $id)) . '</label>'
            . '<div class="col-lg-9">' . $control . $after . $help . '</div></div>';
    }

    /** An editor in the control column, under the labels' column like every other setting. */
    private static function wide(string $html): string
    {
        return '<div class="form-group"><div class="col-lg-9 col-lg-offset-3">' . $html . '</div></div>';
    }

    private static function section(string $key, string $title, string $body, bool $open = false): string
    {
        return '<details class="panel q-section" data-q-section="' . self::e($key) . '"' . ($open ? ' open' : '') . '>'
            . '<summary class="panel-heading q-section__title">' . self::e($title) . '<span class="q-section__chevron" aria-hidden="true"></span></summary>'
            . '<div class="q-section__body">' . $body . '</div></details>';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function __construct()
    {
    }
}
