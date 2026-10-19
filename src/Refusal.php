<?php

declare(strict_types=1);

namespace Quissly\PrestaShop;

/**
 * What the merchant is told when Quissly refuses the store's searches (401 / 402 / 403):
 * the reason by status, Quissly's own words, and - only for a key from
 * config/defines_custom.inc.php - where to check it. It used to be one text for all three that
 * sent every merchant to "config.local.php" (the CS-Cart add-on's file), also a store Setup
 * connected whose plan Quissly did not have (403 "No active quota grant for this service", a plan
 * chosen for manual invoice). As the CS-Cart add-on's Refusal (found testing it, 2026-10-19).
 */
final class Refusal
{
    /**
     * @param callable $t a text lookup: (string $key, array $params): string (Text::t)
     * @param string $detail Quissly's reason ('' when it gave none)
     * @param string $source where the key comes from (Credentials::source())
     */
    public static function text(int $status, string $detail, string $source, callable $t): string
    {
        $key = in_array($status, [401, 402, 403], true) ? 'quissly_search.refused_' . $status : 'quissly_search.reason_auth_error';
        $parts = [$t($key, [])];
        if ($detail !== '') {
            $parts[] = $t('quissly_search.refused_said', ['[detail]' => $detail]);
        }
        if ($status === 401 && $source === Credentials::SOURCE_CONFIG) {
            $parts[] = $t('quissly_search.refused_check_config', []);
        }

        return implode(' ', $parts);
    }

    /** The merchant's text, in the back office's language. */
    public static function forMerchant(int $status, string $detail, string $source): string
    {
        return self::text($status, $detail, $source, function (string $key, array $params): string {
            return \Quissly\PrestaShop\Platform\Text::t($key, $params);
        });
    }

    private function __construct()
    {
    }
}
