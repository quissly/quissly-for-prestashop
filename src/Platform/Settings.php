<?php

declare(strict_types=1);

namespace Quissly\PrestaShop\Platform;

/**
 * The module's settings, in PrestaShop's Configuration table as QUISSLY_<ID> - the same ids and
 * defaults as the CS-Cart add-on's settings ('Y'/'N' switches), so the ported classes read them
 * unchanged.
 */
final class Settings
{
    public const DEFAULTS = [
        'search_interception'    => 'Y',
        'enable_overlay'         => 'Y',
        'overlay_mount_selector' => '',
        'enable_quick'           => 'N',
        'quick_style'            => 'rows',
        'enable_voice'           => 'N',
        'enable_image'           => 'N',
        'enable_qchat'           => 'N',
        'enable_events'          => 'Y',
        'overlay_suggestions'    => 'Y',
        'overlay_recent'         => 'Y',
        'most_loved'             => 'N',
        'most_loved_shown'       => '',
        'most_loved_backups'     => '',
        'update_check_every'     => '30m',
    ];

    /** @var ?array<string, string> this request's plan lock, read once */
    private static $locked = null;

    public static function key(string $id): string
    {
        return 'QUISSLY_' . strtoupper($id);
    }

    /** The stored value, else the default (null for an id that is not a setting). */
    public static function get(string $id): ?string
    {
        if (!array_key_exists($id, self::DEFAULTS)) {
            return null;
        }
        $value = \Configuration::get(self::key($id));

        return $value === false || $value === null ? self::DEFAULTS[$id] : (string) $value;
    }

    /** A switch the store's plan leaves out (Features::locked()) is off whatever was saved. */
    public static function on(string $id): bool
    {
        if (self::$locked === null) {
            self::$locked = \Quissly\PrestaShop\Features::locked();
        }

        return self::get($id) === 'Y' && !isset(self::$locked[$id]);
    }

    public static function set(string $id, string $value): void
    {
        if (array_key_exists($id, self::DEFAULTS)) {
            \Configuration::updateValue(self::key($id), $value);
        }
    }

    /** Install: every setting at its default (a value already there is kept). */
    public static function installDefaults(): bool
    {
        foreach (self::DEFAULTS as $id => $value) {
            if (\Configuration::get(self::key($id)) === false && !\Configuration::updateValue(self::key($id), $value)) {
                return false;
            }
        }

        return true;
    }

    public static function uninstall(): void
    {
        foreach (array_keys(self::DEFAULTS) as $id) {
            \Configuration::deleteByName(self::key($id));
        }
    }

    private function __construct()
    {
    }
}
