<?php

declare(strict_types=1);

namespace Quissly\PrestaShop;

/**
 * A store-wide limit per anonymous storefront endpoint (event, quick, voice, image, the chat cart's
 * update), on top of each endpoint's own per-visitor (REMOTE_ADDR) limit and its storefront token:
 * many addresses together - a bot network rotating proxies - still cannot write or spend more than
 * this. The CS-Cart add-on's limits (a client's review, 2026-11-03).
 */
final class StorefrontGuard
{
    /** Per endpoint: [requests, window seconds], for the whole shop. */
    public const LIMITS = [
        'event'       => [1200, 60],
        'quick'       => [1200, 60],
        'cart_adjust' => [600, 60],
        'voice'       => [60, 600],
        'image'       => [60, 600],
    ];

    /**
     * Whether the shop-wide limit lets one more request to $endpoint through.
     *
     * @param callable $allow (string $bucket, int $max, int $window): bool - the rate limiter
     */
    public static function allowed(string $endpoint, callable $allow): bool
    {
        if (!isset(self::LIMITS[$endpoint])) {
            return true;
        }
        list($max, $window) = self::LIMITS[$endpoint];

        return (bool) $allow('store|' . $endpoint, $max, $window);
    }

    /** The shop-wide limit on the module's own rate table, now. */
    public static function allowedNow(string $endpoint): bool
    {
        $limiter = new Media\DbRateLimiter();

        return self::allowed($endpoint, static function (string $bucket, int $max, int $window) use ($limiter): bool {
            return $limiter->allow($bucket, $max, $window, time());
        });
    }

    private function __construct()
    {
    }
}
