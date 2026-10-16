<?php

declare(strict_types=1);

namespace Quissly\PrestaShop\Media;

use Quissly\PrestaShop\Platform\Db;

/** {@see TokenStore} on the add-on's quissly_search_tokens table. */
final class DbTokenStore implements TokenStore
{
    public function put(array $ids, int $now): string
    {
        Db::query('DELETE FROM ?:quissly_search_tokens WHERE created_at < ?i', $now - self::TTL_SECONDS);
        $token = self::uuid();
        Db::query(
            'INSERT INTO ?:quissly_search_tokens (token, product_ids, created_at) VALUES (?s, ?s, ?i)',
            $token,
            (string) json_encode(array_values(array_map('intval', $ids))),
            $now
        );

        return $token;
    }

    public function get(string $token, int $now): ?array
    {
        if (!preg_match('/^[0-9a-f-]{36}$/', $token)) {
            return null;
        }
        $row = Db::row('SELECT product_ids, created_at FROM ?:quissly_search_tokens WHERE token = ?s', $token);
        if (empty($row) || (int) $row['created_at'] < $now - self::TTL_SECONDS) {
            return null;
        }

        return array_map('intval', (array) json_decode((string) $row['product_ids'], true));
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
