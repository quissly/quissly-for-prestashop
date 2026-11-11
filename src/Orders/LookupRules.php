<?php

declare(strict_types=1);

namespace Quissly\PrestaShop\Orders;

/**
 * The rules of QChat's order lookup, with no PrestaShop in them (OrderLookup applies them):
 *
 *  - verify(): only Quissly's signed request is read - the raw body signed with Quissly's order lookup
 *    key (Ed25519; the public halves are KEYS, by key id), naming this store's project, at most
 *    CLOCK_SKEW old, with a nonce (used once - the controller keeps it);
 *  - sameNumber() / contactMatches(): the shopper's order reference or id, and email or phone - the
 *    middleware's rules for Shopify orders, so every platform answers alike;
 *  - statuses(): a PrestaShop order state in the engine's (Shopify's) words.
 */
final class LookupRules
{
    /** Quissly's order lookup public keys (Ed25519, base64), by key id. */
    public const KEYS = [
        'k1' => '4oqUKgUeo6tWq0wECjgNycyb8E9LRWPEijAkJ/xyYfU=',
    ];

    public const MAX_BODY = 2048;
    public const CLOCK_SKEW = 300;
    public const MIN_PHONE = 7;

    /**
     * The signed request, or null when it is not Quissly's for this store, or too old.
     *
     * @param array<string, string> $keys public keys by id
     * @return ?array{order_number:string, contact:string, nonce:string}
     */
    public static function verify(string $body, string $signature, string $keyId, string $projectId, int $now, array $keys = self::KEYS): ?array
    {
        if ($body === '' || strlen($body) > self::MAX_BODY || $projectId === '' || !isset($keys[$keyId])
            || !function_exists('sodium_crypto_sign_verify_detached')) {
            return null;
        }
        $sig = base64_decode($signature, true);
        $key = base64_decode($keys[$keyId], true);
        if ($sig === false || $key === false || strlen($sig) !== 64 || strlen($key) !== 32) {
            return null;
        }
        try {
            if (!sodium_crypto_sign_verify_detached($sig, $body, $key)) {
                return null;
            }
        } catch (\Throwable $e) {
            return null;
        }
        $ask = json_decode($body, true);
        if (!is_array($ask)) {
            return null;
        }
        $project = is_string($ask['project_id'] ?? null) ? $ask['project_id'] : '';
        $number = is_string($ask['order_number'] ?? null) ? trim($ask['order_number']) : '';
        $contact = is_string($ask['contact'] ?? null) ? trim($ask['contact']) : '';
        $nonce = is_string($ask['nonce'] ?? null) ? $ask['nonce'] : '';
        $at = is_int($ask['timestamp'] ?? null) ? $ask['timestamp'] : 0;
        if (!hash_equals(strtolower($projectId), strtolower($project)) || abs($now - $at) > self::CLOCK_SKEW
            || !preg_match('/^[A-Za-z0-9-]{8,64}$/', $nonce)
            || $number === '' || strlen($number) > 64 || $contact === '' || strlen($contact) > 254) {
            return null;
        }

        return ['order_number' => $number, 'contact' => $contact, 'nonce' => $nonce];
    }

    /** Whether two order references (or ids) are the same: letters and digits only, any case. */
    public static function sameNumber(string $given, string $number): bool
    {
        $a = strtolower((string) preg_replace('/[^0-9A-Za-z]/', '', $given));

        return $a !== '' && strtolower((string) preg_replace('/[^0-9A-Za-z]/', '', $number)) === $a;
    }

    /**
     * Whether the shopper's email or phone is the order's own: an email equal in any case; a phone whose
     * digits (at least MIN_PHONE) are the end of the other's ("+995 555 12 34 56" = "555123456").
     *
     * @param string[] $emails
     * @param string[] $phones
     */
    public static function contactMatches(string $contact, array $emails, array $phones): bool
    {
        $contact = strtolower(trim($contact));
        if (strpos($contact, '@') !== false) {
            foreach ($emails as $email) {
                $email = strtolower(trim((string) $email));
                if ($email !== '' && $email === $contact) {
                    return true;
                }
            }

            return false;
        }
        $given = (string) preg_replace('/\D/', '', $contact);
        if (strlen($given) < self::MIN_PHONE) {
            return false;
        }
        foreach ($phones as $phone) {
            $known = (string) preg_replace('/\D/', '', (string) $phone);
            if (strlen($known) >= self::MIN_PHONE && (self::endsWith($known, $given) || self::endsWith($given, $known))) {
                return true;
            }
        }

        return false;
    }

    /**
     * An order's current state in the engine's words, from the state's own flags (an order state a
     * merchant made carries them too) and which of PrestaShop's states it is.
     *
     * @return array{fulfillment_status:string, financial_status:string, cancelled:bool}
     */
    public static function statuses(bool $paid, bool $shipped, bool $delivered, bool $canceled, bool $refunded): array
    {
        if ($delivered || $shipped) {
            $fulfillment = 'FULFILLED';
        } elseif ($paid) {
            $fulfillment = 'IN_PROGRESS';
        } else {
            $fulfillment = 'UNFULFILLED';
        }
        if ($refunded) {
            $financial = 'REFUNDED';
        } elseif ($paid) {
            $financial = 'PAID';
        } else {
            $financial = 'PENDING';
        }

        return ['fulfillment_status' => $fulfillment, 'financial_status' => $financial, 'cancelled' => $canceled];
    }

    /** The carrier's tracking address for a number: its URL with "@" replaced, http(s) only. */
    public static function trackingUrl(string $carrierUrl, string $number): string
    {
        $url = str_replace('@', rawurlencode($number), trim($carrierUrl));

        return preg_match('#^https?://#i', $url) ? $url : '';
    }

    private static function endsWith(string $haystack, string $needle): bool
    {
        return $needle !== '' && substr($haystack, -strlen($needle)) === $needle;
    }

    private function __construct()
    {
    }
}
