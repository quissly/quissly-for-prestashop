<?php

declare(strict_types=1);

namespace Quissly\PrestaShop\Sync;

use Quissly\PrestaShop\Platform\Db;

/**
 * {@see SyncStore} on the add-on's own tables (created by addon.xml on install):
 * quissly_search_queue, quissly_search_synced, quissly_search_operations,
 * quissly_search_state, quissly_search_log.
 */
final class DbSyncStore implements SyncStore
{
    /** The log keeps this many newest lines. */
    private const LOG_KEEP = 2000;

    public function enqueue(int $productId, string $operation, int $now): void
    {
        if ($productId <= 0) {
            return;
        }
        $operation = $operation === self::OP_DELETE ? self::OP_DELETE : self::OP_UPSERT;
        // Keep the original created_at (the oldest wait), take the newest operation
        // (a product disabled then re-enabled must end up upserted), reset retries.
        Db::query(
            'INSERT INTO ?:quissly_search_queue (product_id, operation, created_at, attempts) VALUES (?i, ?s, ?i, 0)'
            . ' ON DUPLICATE KEY UPDATE operation = VALUES(operation), attempts = 0',
            $productId,
            $operation,
            $now
        );
    }

    public function claim(int $limit): array
    {
        $rows = Db::all(
            'SELECT product_id, operation, attempts FROM ?:quissly_search_queue ORDER BY created_at ASC, product_id ASC LIMIT ?i',
            $limit
        );

        return array_map(static function (array $r): array {
            return [
                'product_id' => (int) $r['product_id'],
                'operation'  => (string) $r['operation'],
                'attempts'   => (int) $r['attempts'],
            ];
        }, $rows ?: []);
    }

    public function remove(array $productIds): void
    {
        if ($productIds !== []) {
            Db::query('DELETE FROM ?:quissly_search_queue WHERE product_id IN (?n)', $productIds);
        }
    }

    public function bumpAttempts(array $productIds): void
    {
        if ($productIds !== []) {
            Db::query('UPDATE ?:quissly_search_queue SET attempts = attempts + 1 WHERE product_id IN (?n)', $productIds);
        }
    }

    public function countQueued(): int
    {
        return (int) Db::field('SELECT COUNT(*) FROM ?:quissly_search_queue');
    }

    public function isSynced(int $productId): bool
    {
        return (bool) Db::field('SELECT 1 FROM ?:quissly_search_synced WHERE product_id = ?i', $productId);
    }

    public function markSynced(array $productIds, int $now): void
    {
        foreach (array_unique($productIds) as $id) {
            Db::query('REPLACE INTO ?:quissly_search_synced (product_id, synced_at) VALUES (?i, ?i)', $id, $now);
        }
    }

    public function clearSynced(array $productIds): void
    {
        if ($productIds !== []) {
            Db::query('DELETE FROM ?:quissly_search_synced WHERE product_id IN (?n)', $productIds);
        }
    }

    public function syncedIds(): array
    {
        return array_map('intval', Db::fields('SELECT product_id FROM ?:quissly_search_synced'));
    }

    public function countSynced(): int
    {
        return (int) Db::field('SELECT COUNT(*) FROM ?:quissly_search_synced');
    }

    public function saveQuisslyIds(array $map): void
    {
        foreach ($map as $quisslyId => $productId) {
            Db::query('REPLACE INTO ?:quissly_search_ids (quissly_id, product_id) VALUES (?s, ?i)', strtolower((string) $quisslyId), (int) $productId);
        }
    }

    public function productIdsFor(array $quisslyIds): array
    {
        $quisslyIds = array_values(array_unique(array_map('strtolower', $quisslyIds)));
        if ($quisslyIds === []) {
            return [];
        }
        $rows = Db::hash('SELECT quissly_id, product_id FROM ?:quissly_search_ids WHERE quissly_id IN (?a)', ['quissly_id', 'product_id'], $quisslyIds);

        return array_map('intval', $rows ?: []);
    }

    public function addOperation(string $operationId, string $kind, array $productIds, int $now): void
    {
        Db::query(
            'REPLACE INTO ?:quissly_search_operations (operation_id, kind, product_ids, sent_at) VALUES (?s, ?s, ?s, ?i)',
            $operationId,
            $kind,
            (string) json_encode(array_values(array_map('intval', $productIds))),
            $now
        );
    }

    public function operations(int $limit): array
    {
        $rows = Db::all('SELECT operation_id, kind, product_ids, sent_at FROM ?:quissly_search_operations ORDER BY sent_at ASC LIMIT ?i', $limit);

        return array_map(static function (array $r): array {
            return [
                'operation_id' => (string) $r['operation_id'],
                'kind'         => (string) $r['kind'],
                'product_ids'  => array_map('intval', (array) json_decode((string) $r['product_ids'], true)),
                'sent_at'      => (int) $r['sent_at'],
            ];
        }, $rows ?: []);
    }

    public function removeOperation(string $operationId): void
    {
        Db::query('DELETE FROM ?:quissly_search_operations WHERE operation_id = ?s', $operationId);
    }

    public function waiting(): array
    {
        // Distinct products: one re-saved while its earlier send is unconfirmed is in two operations.
        $ids = [];
        $oldest = null;
        foreach (Db::all('SELECT product_ids, sent_at FROM ?:quissly_search_operations') ?: [] as $row) {
            foreach ((array) json_decode((string) $row['product_ids'], true) as $id) {
                $ids[(int) $id] = true;
            }
            $oldest = $oldest === null ? (int) $row['sent_at'] : min($oldest, (int) $row['sent_at']);
        }

        return ['products' => count($ids), 'oldest_sent_at' => $oldest];
    }

    public function getState(string $name): ?array
    {
        $value = Db::field('SELECT value FROM ?:quissly_search_state WHERE name = ?s', $name);
        $decoded = is_string($value) ? json_decode($value, true) : null;

        return is_array($decoded) ? $decoded : null;
    }

    public function setState(string $name, ?array $value): void
    {
        if ($value === null) {
            Db::query('DELETE FROM ?:quissly_search_state WHERE name = ?s', $name);

            return;
        }
        Db::query('REPLACE INTO ?:quissly_search_state (name, value) VALUES (?s, ?s)', $name, (string) json_encode($value));
    }

    public function log(string $message, int $now): void
    {
        Db::query('INSERT INTO ?:quissly_search_log (created_at, message) VALUES (?i, ?s)', $now, mb_substr($message, 0, 1000));
        $id = (int) Db::field('SELECT MAX(log_id) FROM ?:quissly_search_log');
        if ($id > self::LOG_KEEP && $id % 100 === 0) {
            Db::query('DELETE FROM ?:quissly_search_log WHERE log_id <= ?i', $id - self::LOG_KEEP);
        }
    }

    public function recentLog(int $limit): array
    {
        $rows = Db::all('SELECT created_at, message FROM ?:quissly_search_log ORDER BY log_id DESC LIMIT ?i', $limit);

        return array_map(static function (array $r): array {
            return ['at' => (int) $r['created_at'], 'message' => (string) $r['message']];
        }, $rows ?: []);
    }
}
