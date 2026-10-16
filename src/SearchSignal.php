<?php

declare(strict_types=1);

namespace Quissly\PrestaShop;

/**
 * What happened to this request's product search (port of the Magento plugin's
 * Model/Search/SearchSignal): Quissly answered (hit), Quissly failed and CS-Cart's own
 * search rendered the page (fallback, with a code), or the add-on stepped aside before
 * calling Quissly (skipped, with a reason). A hit or a fallback always wins over a skip.
 * Read by SearchOrigin for the X-Quissly-Search header and the ?quissly_debug=1 badge.
 *
 * Carries no shopper data - an outcome word, two counts, fixed words; never the query
 * text. Static: one request, one search.
 */
final class SearchSignal
{
    public const HIT = 'hit';
    public const FALLBACK = 'fallback';
    public const SKIPPED = 'skipped';

    /** @var string|null */
    private static $outcome = null;
    /** @var int */
    private static $ids = 0;
    /** @var int */
    private static $total = 0;
    /** @var string */
    private static $code = '';
    /** @var string */
    private static $reason = '';

    /** Quissly answered (a real empty answer included) and its results are rendered. */
    public static function recordHit(int $ids, int $total): void
    {
        self::$outcome = self::HIT;
        self::$ids = $ids;
        self::$total = $total;
    }

    /** Quissly did not answer; CS-Cart's own search (or, page 2+, a notice) is shown. */
    public static function recordFallback(string $code): void
    {
        self::$outcome = self::FALLBACK;
        self::$code = self::token($code);
    }

    /** The add-on stepped aside (search-off, no-term, not-configured, gate-closed). */
    public static function recordSkip(string $reason): void
    {
        if (self::$outcome !== null) {
            return;
        }
        self::$outcome = self::SKIPPED;
        self::$reason = self::token($reason);
    }

    public static function skipReason(): ?string
    {
        return self::$outcome === self::SKIPPED ? self::$reason : null;
    }

    public static function servedByQuissly(): bool
    {
        return self::$outcome === self::HIT;
    }

    /** Header value, or null when no product search ran on this request. */
    public static function headerValue(): ?string
    {
        if (self::$outcome === self::HIT) {
            return sprintf('hit; ids=%d; total=%d', self::$ids, self::$total);
        }
        if (self::$outcome === self::FALLBACK) {
            return sprintf('fallback; code=%s', self::$code);
        }
        if (self::$outcome === self::SKIPPED) {
            return sprintf('skipped; reason=%s', self::$reason);
        }

        return null;
    }

    /** The fallback code for a failed call: transport_error, not_configured or http_<status>. */
    public static function codeFor(Exception\QuisslyException $e): string
    {
        if ($e instanceof Exception\TransportException) {
            return 'transport_error';
        }
        if ($e instanceof Exception\ConfigurationException) {
            return 'not_configured';
        }

        return $e->getHttpStatus() > 0 ? 'http_' . $e->getHttpStatus() : 'error';
    }

    /** Forget this request's outcome (tests). */
    public static function reset(): void
    {
        self::$outcome = null;
        self::$ids = 0;
        self::$total = 0;
        self::$code = '';
        self::$reason = '';
    }

    private static function token(string $value): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_.:-]/', '', $value);
    }
}
