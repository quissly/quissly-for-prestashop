<?php

declare(strict_types=1);

namespace Quissly\PrestaShop\Platform;

/**
 * The module's cron: an address the server's cron calls every few minutes
 * (controllers/front/cron.php), guarded by a secret token made on install - the CS-Cart add-on's
 * cron dispatch, as PrestaShop modules do it.
 */
final class Cron
{
    public const TOKEN = 'QUISSLY_CRON_TOKEN';

    /** The token, made the first time it is asked for. */
    public static function token(): string
    {
        $token = (string) \Configuration::getGlobalValue(self::TOKEN);
        if (strlen($token) !== 32) {
            $token = bin2hex(random_bytes(16));
            \Configuration::updateGlobalValue(self::TOKEN, $token);
        }

        return $token;
    }

    public static function valid(string $given): bool
    {
        return $given !== '' && hash_equals(self::token(), $given);
    }

    public static function url(): string
    {
        return \Context::getContext()->link->getModuleLink('quissly', 'cron', ['token' => self::token()], true);
    }

    private function __construct()
    {
    }
}
