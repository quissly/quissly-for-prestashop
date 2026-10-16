<?php

declare(strict_types=1);

namespace Quissly\PrestaShop\Platform;

/**
 * HTTP with the API the ported classes were written against (CS-Cart's Tygh\Http): static
 * get/post/put/delete returning the body or false, then getStatus()/getError() for the last
 * call. Built on curl, which PrestaShop requires. Options: headers (list of "Name: value"),
 * timeout, connection_timeout, execution_timeout (seconds; the shortest overall limit wins).
 */
final class Http
{
    /** @var int */
    private static $status = 0;

    /** @var string */
    private static $error = '';

    /** @param array<string, mixed>|string $data query parameters */
    public static function get(string $url, $data = [], array $extra = [])
    {
        if (is_array($data) && $data !== []) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($data);
        }

        return self::request('GET', $url, null, $extra);
    }

    /** @param array<string, mixed>|string $data the body (an array is sent as a form) */
    public static function post(string $url, $data = '', array $extra = [])
    {
        return self::request('POST', $url, $data, $extra);
    }

    public static function put(string $url, $data = '', array $extra = [])
    {
        return self::request('PUT', $url, $data, $extra);
    }

    public static function delete(string $url, $data = '', array $extra = [])
    {
        return self::request('DELETE', $url, $data, $extra);
    }

    public static function getStatus(): int
    {
        return self::$status;
    }

    public static function getError(): string
    {
        return self::$error;
    }

    /** @return string|false */
    private static function request(string $method, string $url, $data, array $extra)
    {
        self::$status = 0;
        self::$error = '';
        $curl = curl_init($url);
        if ($curl === false) {
            self::$error = 'curl_init failed';

            return false;
        }
        $limits = array_filter([
            (float) ($extra['timeout'] ?? 0),
            (float) ($extra['execution_timeout'] ?? 0),
        ]);
        $total = $limits === [] ? 30.0 : min($limits);
        $connect = (float) ($extra['connection_timeout'] ?? min($total, 10.0));
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT_MS     => (int) round($total * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) round($connect * 1000),
            CURLOPT_HTTPHEADER     => array_values((array) ($extra['headers'] ?? [])),
            CURLOPT_USERAGENT      => 'quissly-for-prestashop',
        ]);
        if ($data !== null && $method !== 'GET') {
            curl_setopt($curl, CURLOPT_POSTFIELDS, is_array($data) ? http_build_query($data) : (string) $data);
        }
        $body = curl_exec($curl);
        self::$status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        if ($body === false) {
            self::$error = (string) curl_error($curl);
        }
        curl_close($curl);

        return $body === false ? false : (string) $body;
    }

    private function __construct()
    {
    }
}
