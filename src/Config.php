<?php

declare(strict_types=1);

namespace Quissly\PrestaShop;

/**
 * Static configuration for the Quissly QSearch integration.
 *
 * The endpoint PATH lives here in exactly one place. It is part of the signed
 * string, so it MUST match byte-for-byte between the request URL and the
 * signature — a mismatch returns 401 with no other symptom.
 *
 * These are non-secret transport constants. Secrets (bearer token, private key,
 * environment) are read from config.local.php via {@see Credentials}.
 */
final class Config
{
    /** API base URL (no trailing slash). */
    public const BASE_URL = 'https://api.quissly.com';

    /** Endpoint path — part of the signed string. One source of truth. */
    public const QSEARCH_PATH = '/v2beta/qsearch';

    /** HTTP method, also part of the signed string. */
    public const METHOD = 'POST';

    /** Default environment the credential is registered under. */
    public const DEFAULT_ENVIRONMENT = 'prod';

    /** Hard timeout budget for a QSearch call, in seconds. */
    public const TIMEOUT_SECONDS = 2.5;

    /** Minimum trimmed query length before we intercept. */
    public const MIN_QUERY_LENGTH = 2;

    /** Maximum query length we will send (Quissly 400s on overflow). */
    public const MAX_QUERY_LENGTH = 150;

    /** Channel sent in the request body. */
    public const CHANNEL = 'web';

    /**
     * X-Platform (header only, NOT signed), in ONE constant.
     *
     * 'prestashop' - NOT accepted by Quissly yet (2026-10-12): its enum is 'shopify',
     * 'woocommerce', 'magento', 'internal', 'cs-cart', so every call answers 422 until Quissly
     * adds it (the CS-Cart add-on's lesson: the exact spelling matters - 'cscart' was refused).
     */
    public const PLATFORM = 'prestashop';

    /** Fallback page size when PrestaShop hands us a zero/empty results-per-page. */
    public const DEFAULT_PAGE_SIZE = 20;

    /** Voice (qsearch + audio) and image (qimage) paths; both v2-signed like text search. */
    public const QIMAGE_PATH = '/v2beta/qimage';

    /** Quick Recommendations (v2-signed like text search): product ids for the typed text. */
    public const QUICK_PATH = '/v2beta/quick';

    /** Voice/image: Quissly transcribes or embeds first, so far slower than a text search. */
    public const MEDIA_TIMEOUT_SECONDS = 15;

    /** Quissly's console (account services: the admin panel's sign-in). No signature. */
    public const CONSOLE_URL = 'https://console.quissly.com';

    /** The Quissly admin panel embedded on the add-on's "Quissly Admin Panel" page. */
    public const PANEL_URL = 'https://admin.quissly.com';

    /**
     * The platform sent to the CONSOLE (provisioning, the admin panel's store sign-in): the same
     * spelling as X-Platform, once Quissly accepts it there too.
     */
    public const CONSOLE_PLATFORM = 'prestashop';

    /** Full request URL. */
    public static function url(): string
    {
        return self::BASE_URL . self::QSEARCH_PATH;
    }

    private function __construct()
    {
    }
}
