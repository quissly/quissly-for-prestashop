<?php

declare(strict_types=1);

namespace Quissly\PrestaShop\Storefront;

use Quissly\PrestaShop\Catalog\MostLovedCards;
use Quissly\PrestaShop\Credentials;
use Quissly\PrestaShop\Features;
use Quissly\PrestaShop\Panel\QChatDirectory;
use Quissly\PrestaShop\Platform\Settings;
use Quissly\PrestaShop\Platform\Shop;
use Quissly\PrestaShop\Platform\Text;
use Quissly\PrestaShop\SearchSuggestions;
use Quissly\PrestaShop\Showcase\SuggestionsEnrich;
use Quissly\PrestaShop\Storefront;
use Quissly\PrestaShop\Sync\DbSyncStore;
use Quissly\PrestaShop\Sync\SyncWorker;

/**
 * A storefront page's Quissly parts (the CS-Cart add-on's fn_quissly_search_storefront): the
 * facts for Storefront::build, from PrestaShop - once per request. The module's hooks put them
 * on the page: displayHeader the styles and scripts, displayAfterBodyOpeningTag the markup
 * (markup(), before any script runs).
 */
final class Page
{
    /** @var array|null */
    private static $built;

    /** @return array{overlay: bool, overlay_json: string, media: bool, media_json: string, quick: bool, quick_json: string, qchat_agent_id: string, qchat_script: string, cart_json: string, events: bool, events_json: string} */
    public static function data(): array
    {
        if (self::$built !== null) {
            return self::$built;
        }
        $context = \Context::getContext();
        $link = $context->link;
        $credentials = Credentials::fromRegistry();
        $store = new DbSyncStore();
        $configured = $credentials->isConfigured();
        $serviceId = $configured ? QChatDirectory::cachedSearchServiceId($store) : '';
        $language = SuggestionsEnrich::languageKey((string) $context->language->iso_code);
        $main = Shop::mainLanguage();
        $shopName = trim((string) \Configuration::get('PS_SHOP_NAME'));

        self::$built = Storefront::build([
            'configured'     => $configured,
            'gate_open'      => $configured && SyncWorker::isGateOpen($store),
            'features'       => Features::all(),
            'agent_id'       => $configured ? QChatDirectory::cached($store) : '',
            'urls'           => [
                'voice'        => $link->getModuleLink('quissly', 'media', ['kind' => 'voice']),
                'image'        => $link->getModuleLink('quissly', 'media', ['kind' => 'image']),
                'results'      => $link->getPageLink('search'),
                'cart_add'     => $link->getModuleLink('quissly', 'cart', ['op' => 'update']),
                'cart_adjust'  => $link->getModuleLink('quissly', 'cart', ['op' => 'update']),
                'cart_resolve' => $link->getModuleLink('quissly', 'cart', ['op' => 'resolve']),
                'event'        => $link->getModuleLink('quissly', 'event'),
                'quick'        => $link->getModuleLink('quissly', 'quick'),
            ],
            'security_hash'  => (string) \Tools::getToken(false),
            'event'          => $configured ? \Quissly\PrestaShop\Events\Recorder::pageEvent() : null,
            'mount_selector' => (string) Settings::get('overlay_mount_selector'),
            // Search bar suggestions for the shopper's language: the cached service id only - a
            // storefront page never looks it up.
            'suggestions'    => $configured ? SearchSuggestions::forStorefront($store, $serviceId, Shop::httpGet(5), time(), $language, $main) : [],
            'chips'          => SearchSuggestions::buttonsForStorefront($store, $serviceId, Shop::httpGet(5), time(), $language, $main),
            'most_loved'     => $configured ? MostLovedCards::forShopper($store) : [],
            'overlay_layout' => Features::overlayLayout(),
            'quick_style'    => Features::quickStyle(),
            'i18n'           => [
                'search'             => $context->getTranslator()->trans('Search', [], 'Shop.Theme.Catalog'),
                'clear'              => Text::t('quissly_search.sf_clear'),
                'seeAll'             => Text::t('quissly_search.sf_see_all'),
                'placeholder'        => Text::t('quissly_search.sf_placeholder'),
                'suggestions'        => $shopName !== '' ? Text::t('quissly_search.sf_suggestions', ['[store]' => $shopName]) : Text::t('quissly_search.sf_suggestions_plain'),
                'mostLoved'          => $shopName !== '' ? Text::t('quissly_search.sf_most_loved', ['[store]' => $shopName]) : Text::t('quissly_search.sf_most_loved_plain'),
                'rating'             => Text::t('quissly_search.sf_rating'),
                'recent'             => Text::t('quissly_search.sf_recent'),
                'removeRecent'       => Text::t('quissly_search.sf_remove_recent'),
                'voice'              => Text::t('quissly_search.sf_voice'),
                'image'              => Text::t('quissly_search.sf_image'),
                'listening'          => Text::t('quissly_search.sf_listening'),
                'searching'          => Text::t('quissly_search.sf_searching'),
                'micUnsupported'     => Text::t('quissly_search.sf_mic_unsupported'),
                'micInsecure'        => Text::t('quissly_search.sf_mic_insecure'),
                'micDenied'          => Text::t('quissly_search.sf_mic_denied'),
                'failed'             => Text::t('quissly_search.sf_failed'),
                'voiceFallbackQuery' => Text::t('quissly_search.sf_voice_fallback'),
                'imageFallbackQuery' => Text::t('quissly_search.sf_image_fallback'),
            ],
        ]);

        return self::$built;
    }

    /** The scripts and styles this page needs (none when nothing of Quissly's is on it). */
    public static function assets(\FrontController $controller, string $moduleUri): void
    {
        $data = self::data();
        if (!$data['overlay'] && !$data['media'] && !$data['quick'] && !$data['events'] && $data['qchat_agent_id'] === '') {
            return;
        }
        $base = 'modules/quissly/views/';
        if ($data['overlay'] || $data['media'] || $data['quick']) {
            $controller->registerStylesheet('quissly-storefront', $base . 'css/storefront.css', ['media' => 'all', 'priority' => 150]);
        }
        $scripts = ['overlay' => $data['overlay'], 'media' => $data['media'], 'quick' => $data['quick'], 'events' => $data['events'], 'cart' => $data['qchat_agent_id'] !== ''];
        foreach ($scripts as $name => $on) {
            if ($on) {
                $controller->registerJavascript('quissly-' . $name, $base . 'js/' . $name . '.js', ['position' => 'bottom', 'priority' => 150]);
            }
        }
        if ($data['qchat_agent_id'] !== '') {
            $controller->registerJavascript('quissly-qchat', $data['qchat_script'], ['server' => 'remote', 'position' => 'bottom', 'priority' => 160, 'attributes' => 'async']);
        }
    }

    /** The config elements the scripts read, and the chat widget's element. */
    public static function markup(): string
    {
        $data = self::data();
        $e = static function (string $value): string {
            return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        };
        $html = '';
        if ($data['overlay']) {
            $html .= '<div data-quissly-overlay data-quissly-config="' . $e($data['overlay_json']) . '" hidden></div>';
        }
        if ($data['media']) {
            $html .= '<div data-quissly-media data-quissly-config="' . $e($data['media_json']) . '" hidden></div>';
        }
        if ($data['quick']) {
            $html .= '<div data-quissly-quick data-quissly-config="' . $e($data['quick_json']) . '" hidden></div>';
        }
        if ($data['events']) {
            $html .= '<div data-quissly-events data-quissly-config="' . $e($data['events_json']) . '" hidden></div>';
        }
        if ($data['qchat_agent_id'] !== '') {
            $html .= '<ai-chatbot agent-id="' . $e($data['qchat_agent_id']) . '"></ai-chatbot>'
                . '<div data-quissly-cart data-quissly-config="' . $e($data['cart_json']) . '" hidden></div>';
        }

        return $html;
    }

    private function __construct()
    {
    }
}
