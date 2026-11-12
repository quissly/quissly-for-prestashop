<?php

declare(strict_types=1);

namespace Quissly\PrestaShop\Platform;

/**
 * The cron's work for a shop whose server cron is not set up (WordPress's WP-Cron way): at most
 * once every EVERY_SECONDS, a storefront page asks the module's own cron address in the
 * background (controllers/front/cron.php, with `visit=1`), after the page has gone to the
 * browser where PHP-FPM allows it, without waiting for the answer. The cron address does nothing
 * on such a call while the real cron is running. Product changes, shopping activity, the plan
 * and updates then move whenever shoppers visit - the Dashboard still recommends the cron line,
 * since visits are not a schedule.
 *
 * As the CS-Cart add-on after a client's review (2026-11-03): nothing is asked while the real cron
 * has run in the last CRON_FRESH_SECONDS, nor when the merchant switched it off (setting
 * enable_visit_tick: a shop with the cron, or one whose server cannot reach its own public address -
 * behind Cloudflare, say). Only a full GET page asks, never an AJAX request, and the page's answer
 * is never cut short: fastcgi_finish_request is not called; the page waits at most SPAWN_TIMEOUT_MS
 * to hand the address its request.
 */
final class VisitTick
{
    public const EVERY_SECONDS = 300;
    /** The real cron stands for this long: a visit asks nothing meanwhile. */
    public const CRON_FRESH_SECONDS = 600;
    /** The longest a page waits to hand the address its request (the address runs on without it). */
    public const SPAWN_TIMEOUT_MS = 500;
    /** When a visit last asked (a Configuration value: read from memory on every page). */
    public const KEY = 'QUISSLY_VISIT_TICK';

    /** Whether a visit at $now should ask, the last one having asked at $lastTick. */
    public static function due(int $lastTick, int $now): bool
    {
        return $now - $lastTick >= self::EVERY_SECONDS;
    }

    /** Whether the real cron ran at $lastCron recently enough that visits need not ask. */
    public static function cronFresh(int $lastCron, int $now): bool
    {
        return $lastCron >= $now - self::CRON_FRESH_SECONDS;
    }

    /** Whether this request may ask at all: the switch on, a GET, not an AJAX request. */
    public static function wanted(bool $switchedOn, string $method, bool $ajax): bool
    {
        return $switchedOn && strtoupper($method) === 'GET' && !$ajax;
    }

    /** On a full storefront page: ask the cron address in the background when due. */
    public static function maybe(): void
    {
        $ajax = (bool) \Tools::getValue('ajax') || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
        if (!self::wanted(Settings::on('enable_visit_tick'), (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), $ajax)) {
            return;
        }
        $now = time();
        if (!self::due((int) \Configuration::getGlobalValue(self::KEY), $now)) {
            return;
        }
        \Configuration::updateGlobalValue(self::KEY, $now);
        if (self::cronFresh((int) ((new \Quissly\PrestaShop\Sync\DbSyncStore())->getState('last_cron')['at'] ?? 0), $now)) {
            return;
        }
        $url = Cron::url(['visit' => 1]);
        register_shutdown_function(static function () use ($url) {
            self::spawn($url, self::SPAWN_TIMEOUT_MS);
        });
    }

    /** Start a request and leave without its answer (the cron address keeps running). */
    public static function spawn(string $url, int $timeoutMs): void
    {
        if (!function_exists('curl_init')) {
            return;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_NOSIGNAL          => true,
            CURLOPT_CONNECTTIMEOUT_MS => $timeoutMs,
            CURLOPT_TIMEOUT_MS        => $timeoutMs,
            CURLOPT_USERAGENT         => 'Quissly visit tick',
        ]);
        curl_exec($ch);
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch); // a no-op since 8.0, deprecated in 8.5
        }
    }

    private function __construct()
    {
    }
}
