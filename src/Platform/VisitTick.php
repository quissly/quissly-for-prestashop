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
 */
final class VisitTick
{
    public const EVERY_SECONDS = 300;
    /** When a visit last asked (a Configuration value: read from memory on every page). */
    public const KEY = 'QUISSLY_VISIT_TICK';

    /** Whether a visit at $now should ask, the last one having asked at $lastTick. */
    public static function due(int $lastTick, int $now): bool
    {
        return $now - $lastTick >= self::EVERY_SECONDS;
    }

    /** On a storefront page: ask the cron address in the background when due. */
    public static function maybe(): void
    {
        $now = time();
        if (!self::due((int) \Configuration::getGlobalValue(self::KEY), $now)) {
            return;
        }
        \Configuration::updateGlobalValue(self::KEY, $now);
        $url = Cron::url(['visit' => 1]);
        register_shutdown_function(static function () use ($url) {
            // The shopper has the page already: there is time to send the request properly.
            $sent = function_exists('fastcgi_finish_request') && fastcgi_finish_request();
            self::spawn($url, $sent ? 3000 : 500);
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
        curl_close($ch);
    }

    private function __construct()
    {
    }
}
