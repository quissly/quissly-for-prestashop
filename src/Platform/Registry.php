<?php

declare(strict_types=1);

namespace Quissly\PrestaShop\Platform;

/**
 * The values the ported classes read from CS-Cart's Registry, answered from PrestaShop:
 *
 *   config.quissly_*                the manual setup's constants (QUISSLY_BEARER_TOKEN, ...,
 *                                   defined in config/defines_custom.inc.php), '' when not set -
 *                                   one-click Connect stores credentials in the database instead
 *   config.crypt_key                _COOKIE_KEY_ (encrypts the stored credentials)
 *   config.dir.root / .addons / .var  the shop root, modules/ and var/ (trailing slash)
 *   addons.quissly_search.<id>      the module's settings (Settings)
 *   runtime.controller              the front controller's page (php_self), '' elsewhere
 *
 * Anything else is null.
 */
final class Registry
{
    /** @return mixed */
    public static function get(string $key)
    {
        if (strpos($key, 'config.quissly_') === 0) {
            $constant = 'QUISSLY_' . strtoupper(substr($key, strlen('config.quissly_')));

            return defined($constant) ? (string) constant($constant) : '';
        }
        if (strpos($key, 'addons.quissly_search.') === 0) {
            return Settings::get(substr($key, strlen('addons.quissly_search.')));
        }
        switch ($key) {
            case 'config.crypt_key':
                return defined('_COOKIE_KEY_') ? (string) _COOKIE_KEY_ : '';
            case 'config.dir.root':
                return rtrim((string) _PS_ROOT_DIR_, '/') . '/';
            case 'config.dir.addons':
                return rtrim((string) _PS_MODULE_DIR_, '/') . '/';
            case 'config.dir.var':
                return rtrim((string) _PS_ROOT_DIR_, '/') . '/var/';
            case 'runtime.controller':
                $controller = \Context::getContext()->controller ?? null;

                return is_object($controller) && isset($controller->php_self) ? (string) $controller->php_self : '';
        }

        return null;
    }

    private function __construct()
    {
    }
}
