<?php

declare(strict_types=1);

namespace Quissly\PrestaShop\Platform;

use Quissly\PrestaShop\SearchSuggestions;
use Quissly\PrestaShop\Showcase\SuggestionsEnrich;

/**
 * What the ported classes ask of the shop (the CS-Cart add-on's fn_quissly_search_* helpers),
 * answered by PrestaShop: HTTP calls in the shape the classes take, the shop's languages, a
 * posted list editor's value, admin links.
 */
final class Shop
{
    /** @return callable(string, array): ?array{status: int, body: string} */
    public static function httpGet(int $timeout): callable
    {
        return static function (string $url, array $headers = []) use ($timeout): ?array {
            return self::answer(Http::get($url, [], ['headers' => $headers, 'timeout' => $timeout]));
        };
    }

    /** @return callable(string, string, array=): ?array{status: int, body: string} headers optional */
    public static function httpPost(int $timeout): callable
    {
        return static function (string $url, string $body, array $headers = []) use ($timeout): ?array {
            return self::answer(Http::post($url, $body, ['headers' => array_merge(['Content-Type: application/json'], $headers), 'timeout' => $timeout]));
        };
    }

    /** @return callable(string, string, array=): ?array{status: int, body: string} headers optional */
    public static function httpPut(int $timeout): callable
    {
        return static function (string $url, string $body, array $headers = []) use ($timeout): ?array {
            return self::answer(Http::put($url, $body, ['headers' => array_merge(['Content-Type: application/json'], $headers), 'timeout' => $timeout]));
        };
    }

    /** The shop's default language, as a suggestions key ('en', 'ka', ...). */
    public static function mainLanguage(): string
    {
        $iso = (string) \Language::getIsoById((int) \Configuration::get('PS_LANG_DEFAULT'));

        return SuggestionsEnrich::languageKey($iso !== '' ? $iso : 'en');
    }

    /** @return array<string, string> key => name, the main language first, then every active one */
    public static function languages(): array
    {
        $main = self::mainLanguage();
        $out = [$main => $main];
        foreach (\Language::getLanguages(true) as $language) {
            $out[SuggestionsEnrich::languageKey((string) $language['iso_code'])] = (string) $language['name'];
        }

        return $out;
    }

    /**
     * A list editor's posted value (one JSON field: {language: [entries]}), cleaned; null when
     * it is not JSON.
     *
     * @return array<string, list<string>>|null
     */
    public static function postedLists(string $field): ?array
    {
        $decoded = json_decode((string) \Tools::getValue($field, ''), true);
        if (!is_array($decoded)) {
            return null;
        }
        $out = [];
        foreach ($decoded as $language => $entries) {
            $out[SuggestionsEnrich::languageKey((string) $language)] = SearchSuggestions::clean($entries);
        }

        return $out;
    }

    /** An admin page of the module (or any admin controller), with its token. */
    public static function adminUrl(string $controller, array $params = []): string
    {
        return \Context::getContext()->link->getAdminLink($controller, true, [], $params);
    }

    /**
     * A CS-Cart dispatch the ported views link to, as the PrestaShop admin page that does it
     * (the dispatch itself outside PrestaShop - what the views' tests check).
     */
    public static function dispatchUrl(string $dispatch): string
    {
        if (!class_exists('Context')) {
            return $dispatch;
        }
        $map = [
            'quissly_search.setup'           => ['AdminQuisslySetup', []],
            'quissly_search.dashboard'       => ['AdminQuisslyDashboard', []],
            'quissly_search.test_connection' => ['AdminQuisslyDashboard', ['quissly_do' => 'test']],
            'quissly_search.panel'           => ['AdminQuisslyPanel', []],
            'quissly_search.billing'         => ['AdminQuisslyBilling', []],
        ];
        $route = $map[strtok($dispatch, '?')] ?? ['AdminQuisslyConfiguration', []];

        return self::adminUrl($route[0], $route[1]);
    }

    /** @param string|false $body */
    private static function answer($body): ?array
    {
        $status = Http::getStatus();

        return $body === false || $status === 0 ? null : ['status' => $status, 'body' => (string) $body];
    }

    private function __construct()
    {
    }
}
