<?php

declare(strict_types=1);

namespace Quissly\PrestaShop\Platform;

/**
 * The module's texts: texts/<language>.php (en, ka, ru - the CS-Cart add-on's language pack, the
 * same keys, e.g. quissly_search.su_title) and texts/prestashop.<language>.php (the module's
 * own), in the shop language of the current page, English
 * where a language has no text. Parameters replace their placeholder ('[count]' => '3').
 *
 * Outside PrestaShop (the unit tests) a key comes back as itself followed by its values - what
 * the views' tests check.
 */
final class Text
{
    /** @var array<string, array<string, string>> language => texts, as loaded */
    private static $loaded = [];

    /** @var string|null the language in use; null = from PrestaShop's context */
    private static $language;

    /** @param array<string, string|int> $params */
    public static function t(string $key, array $params = []): string
    {
        $language = self::language();
        if ($language === null) {
            return $key . ($params === [] ? '' : ' ' . implode(' ', $params));
        }
        $text = self::texts($language)[$key] ?? self::texts('en')[$key] ?? $key;

        return $params === [] ? $text : strtr($text, array_map('strval', $params));
    }

    /** Test seam / a page that renders in a given language. */
    public static function useLanguage(?string $iso): void
    {
        self::$language = $iso;
    }

    private static function language(): ?string
    {
        if (self::$language !== null) {
            return self::$language;
        }
        if (!class_exists('Context')) {
            return null;
        }
        $language = \Context::getContext()->language ?? null;
        $iso = is_object($language) ? strtolower((string) $language->iso_code) : 'en';

        return in_array($iso, ['en', 'ka', 'ru'], true) ? $iso : 'en';
    }

    /** @return array<string, string> */
    private static function texts(string $language): array
    {
        if (!isset(self::$loaded[$language])) {
            // The CS-Cart add-on's texts, then the PrestaShop module's own (texts/prestashop.<lang>.php).
            $texts = [];
            foreach ([$language . '.php', 'prestashop.' . $language . '.php'] as $name) {
                $file = dirname(__DIR__, 2) . '/texts/' . $name;
                $more = is_file($file) ? require $file : [];
                $texts = is_array($more) ? $more + $texts : $texts;
            }
            self::$loaded[$language] = $texts;
        }

        return self::$loaded[$language];
    }

    private function __construct()
    {
    }
}
