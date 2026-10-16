<?php

declare(strict_types=1);

namespace Quissly\PrestaShop\Media;

use Quissly\PrestaShop\Platform\Db;

/** {@see RateLimiter} on the add-on's quissly_search_rate table (one row per bucket). */
final class DbRateLimiter implements RateLimiter
{
    public function allow(string $bucket, int $max, int $windowSeconds, int $now): bool
    {
        $key = substr(hash('sha256', $bucket), 0, 40);
        $row = Db::row('SELECT window_start, hits FROM ?:quissly_search_rate WHERE bucket = ?s', $key);
        if (empty($row) || (int) $row['window_start'] <= $now - $windowSeconds) {
            Db::query('REPLACE INTO ?:quissly_search_rate (bucket, window_start, hits) VALUES (?s, ?i, 1)', $key, $now);
            if (random_int(1, 50) === 1) {
                Db::query('DELETE FROM ?:quissly_search_rate WHERE window_start < ?i', $now - 3600);
            }

            return true;
        }
        if ((int) $row['hits'] >= $max) {
            return false;
        }
        Db::query('UPDATE ?:quissly_search_rate SET hits = hits + 1 WHERE bucket = ?s', $key);

        return true;
    }
}
