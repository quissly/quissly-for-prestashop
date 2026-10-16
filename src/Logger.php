<?php

declare(strict_types=1);

namespace Quissly\PrestaShop;

/**
 * Thin logging facade.
 *
 * Routes through PrestaShopLogger so entries appear in PrestaShop's log
 * (Advanced Parameters > Logs), and ALWAYS mirrors to error_log() so
 * diagnostics survive when the database cannot be written.
 *
 * NEVER pass secrets or query strings here: no private key, bearer token,
 * signature, or shopper query text.
 * Pass status codes, counts, and stable identifiers only.
 */
final class Logger
{
    /**
     * @param array<string, scalar> $context safe, secret-free context
     */
    public static function error(string $message, array $context = []): void
    {
        self::write($message, $context);
    }

    /**
     * Operational/diagnostic signal (not a failure). Same routing as error().
     *
     * @param array<string, scalar> $context
     */
    public static function notice(string $message, array $context = []): void
    {
        self::write($message, $context);
    }

    /**
     * @param array<string, scalar> $context
     */
    private static function write(string $message, array $context): void
    {
        $suffix = $context !== [] ? ' ' . self::encodeContext($context) : '';
        $line = '[quissly] ' . $message . $suffix;

        // PrestaShop's own log (Advanced Parameters > Logs), as the other plugins use theirs.
        if (class_exists('PrestaShopLogger')) {
            \PrestaShopLogger::addLog(mb_substr($line, 0, 1000), 2, null, 'Quissly', null, true);
        }

        error_log($line);
    }

    /**
     * @param array<string, scalar> $context
     */
    private static function encodeContext(array $context): string
    {
        $encoded = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '' : $encoded;
    }

    private function __construct()
    {
    }
}
