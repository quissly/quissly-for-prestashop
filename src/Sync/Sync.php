<?php

declare(strict_types=1);

namespace Quissly\PrestaShop\Sync;

use Quissly\PrestaShop\Credentials;
use Quissly\PrestaShop\Platform\Db;

/**
 * PrestaShop entry points into the sync: the worker wired to the live pieces, a full sync,
 * and what the module's hooks call when the catalog changes. The hooks only QUEUE - they
 * never call Quissly inside a save; the cron, an admin page's end and "Sync now" send.
 *
 * What queues a product (quissly.php's hooks): a product saved, added or deleted (also its
 * status toggled in the list, a bulk action, an import - all save the product), one of its
 * combinations or images added, changed or deleted, a specific price (a sale) for it added,
 * changed or deleted, and a quantity change that moves it between in and out of stock
 * (ProductSource::stockChanged - every order changes quantities, and only a flip changes what
 * Quissly holds), and an order whose status starts or stops counting as a sale (salesChanged -
 * PrestaShop's product_sale, the popularity Quissly sorts by). What does not: a catalog price
 * rule, a renamed category or feature - the next full sync ("Sync now") sends those.
 */
final class Sync
{
    /** "Sync now" in the admin: an admin waiting on a page. */
    public const ADMIN_BUDGET_SECONDS = 25;

    /** One cron run (cron is expected every 5 minutes). */
    public const CRON_BUDGET_SECONDS = 240;

    /** An admin page's end, when the cron is not set up. */
    public const FALLBACK_BUDGET_SECONDS = 10;

    public static function worker(?Credentials $credentials = null): SyncWorker
    {
        $credentials = $credentials ?? Credentials::fromRegistry();
        $source = new ProductSource();

        return new SyncWorker(
            new DbSyncStore(),
            new CatalogClient($credentials, new LiveTransport()),
            [$source, 'load']
        );
    }

    /** Queue every product the sync sends (and removals of what is no longer sent); null before Connect. */
    public static function startFull(): ?int
    {
        if (!Credentials::fromRegistry()->isConfigured()) {
            return null;
        }

        return self::worker()->startFullSync((new ProductSource())->activeIds());
    }

    /** Send what is queued, within the budget; null when the store is not connected. */
    public static function run(int $budgetSeconds, string $source): ?array
    {
        $credentials = Credentials::fromRegistry();
        if (!$credentials->isConfigured()) {
            return null;
        }
        $store = new DbSyncStore();
        if ($store->countQueued() === 0) {
            return null;
        }

        return self::worker($credentials)->run($budgetSeconds, $source);
    }

    /** A product was created, saved or deleted (the worker deletes a disabled or missing one). */
    public static function productChanged(int $productId): void
    {
        self::queue($productId, SyncStore::OP_UPSERT);
    }

    public static function productDeleted(int $productId): void
    {
        self::queue($productId, SyncStore::OP_DELETE);
    }

    /** A quantity changed: only a move between in and out of stock re-sends the product. */
    public static function quantityChanged(int $productId): void
    {
        if ($productId > 0 && Credentials::fromRegistry()->isConfigured() && (new ProductSource())->stockChanged($productId)) {
            self::queue($productId, SyncStore::OP_UPSERT);
        }
    }

    /**
     * An order's status is about to change (actionOrderStatusUpdate, before PrestaShop applies it):
     * when the order starts or stops counting as a sale - PrestaShop's own rule, the status's
     * "Consider the associated order as validated" (logable) flipping, which is when it adds the
     * order to or takes it from product_sale - its products are queued, so Quissly gets their new
     * sales count. Once per payment or cancellation, not per status: shipped and delivered do not
     * flip it. The queue holds a product once, however many orders queue it.
     */
    public static function salesChanged(int $orderId, ?bool $wasSale, bool $isSale): void
    {
        if ($orderId <= 0 || !self::saleFlips($wasSale, $isSale) || !Credentials::fromRegistry()->isConfigured()) {
            return;
        }
        foreach (Db::fields('SELECT DISTINCT product_id FROM ?:order_detail WHERE id_order = ?i', $orderId) as $productId) {
            self::queue((int) $productId, SyncStore::OP_UPSERT);
        }
    }

    /** Whether an order moving from status $wasSale (null: no status yet) to $isSale changes product_sale. */
    public static function saleFlips(?bool $wasSale, bool $isSale): bool
    {
        return (bool) $wasSale !== $isSale;
    }

    private static function queue(int $productId, string $operation): void
    {
        // Nothing to send before the store is connected; connecting starts a full sync.
        if ($productId > 0 && Credentials::fromRegistry()->isConfigured()) {
            (new DbSyncStore())->enqueue($productId, $operation, time());
        }
    }

    private function __construct()
    {
    }
}
