<?php

declare(strict_types=1);

namespace Quissly\PrestaShop\Events;

use Quissly\PrestaShop\Credentials;
use Quissly\PrestaShop\Platform\Db;
use Quissly\PrestaShop\Platform\Settings;
use Quissly\PrestaShop\Search\SearchProvider;
use Quissly\PrestaShop\Shopper;

/**
 * The Quissly Shopify app's five shopping events, queued from PrestaShop (the CS-Cart add-on's
 * fn_quissly_search_event_* functions): view_PDP and search from the browser (views/js/events.js
 * -> controllers/front/event.php), add_to_cart and add_to_wishlist from the module's hooks,
 * purchase_successful when an order first reaches a paid status. The cron sends them
 * (EventSender). Only while "Shopping activity" is on and the store is connected; a shopper's
 * event carries the search's user_id. A combination is its product's variant, as
 * "<id_product>-<id_product_attribute>" (EventBody::variantId) - the catalog sync names variants
 * the same way. Never throws.
 */
final class Recorder
{
    public static function enabled(): bool
    {
        return Settings::on('enable_events') && Credentials::fromRegistry()->isConfigured();
    }

    /** The current shopper's event (a storefront request). */
    public static function shopper(string $type, array $metadata): bool
    {
        try {
            if (!self::enabled()) {
                return false;
            }
            $userId = SearchProvider::userId();
            if ($userId === null) {
                return false;
            }
            $shopper = Shopper::deviceAndOs((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
            (new DbEventQueue())->add(EventBody::envelope($type, $metadata, $userId, $shopper, self::shop(), EventBody::SOURCE_STOREFRONT, Shopper::newUuid(), time()), time());

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** An order the first time it reaches a paid status ("paid" in Orders > Statuses). */
    public static function order(\Order $order, \OrderState $state): bool
    {
        try {
            if (!self::enabled() || !\Validate::isLoadedObject($order) || empty($state->paid) || self::paidBefore((int) $order->id)) {
                return false;
            }
            $lines = [];
            foreach ((array) $order->getProducts() as $line) {
                $lines[] = [
                    'product_id' => (int) $line['product_id'],
                    'variant_id' => (int) $line['product_attribute_id'],
                    'name'       => (string) $line['product_name'],
                    'quantity'   => (float) $line['product_quantity'],
                    'price'      => (float) $line['unit_price_tax_incl'],
                ];
            }
            $currency = new \Currency((int) $order->id_currency);
            $metadata = EventBody::purchase((string) $order->reference, (float) $order->total_paid_tax_incl, (string) $currency->iso_code, $lines);
            $customer = (int) $order->id_customer;
            (new DbEventQueue())->add(EventBody::envelope(EventBody::PURCHASE, $metadata, $customer > 0 ? Shopper::customerId($customer) : null, [], self::shop(), EventBody::SOURCE_ORDER, Shopper::newUuid(), time()), time());

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** The store reporting on itself (the version report): no shopper, no user id. */
    public static function store(string $type, array $metadata): bool
    {
        try {
            if (!Credentials::fromRegistry()->isConfigured()) {
                return false;
            }
            (new DbEventQueue())->add(EventBody::envelope($type, $metadata, null, [], self::shop(), EventBody::SOURCE_STORE, Shopper::newUuid(), time()), time());

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * This storefront page's own event for events.js: a product page's view, or a search results
     * page's search (its first page only; voice/image by their token).
     *
     * @return array<string, string>|null
     */
    public static function pageEvent(): ?array
    {
        $controller = \Context::getContext()->controller ?? null;
        $page = is_object($controller) ? (string) ($controller->php_self ?? '') : '';
        if ($page === 'product') {
            $id = (int) \Tools::getValue('id_product');

            return $id > 0 ? ['type' => EventBody::VIEW, 'product_id' => (string) $id] : null;
        }
        if ($page !== 'search' || (int) \Tools::getValue('page', 1) > 1) {
            return null;
        }
        if ((string) \Tools::getValue(\Quissly\PrestaShop\Media\MediaEndpoint::QUERY_VAR_VOICE) !== '') {
            return ['type' => EventBody::SEARCH, 'query' => '', 'modality' => 'voice'];
        }
        if ((string) \Tools::getValue(\Quissly\PrestaShop\Media\MediaEndpoint::QUERY_VAR_IMAGE) !== '') {
            return ['type' => EventBody::SEARCH, 'query' => '', 'modality' => 'image'];
        }
        $query = trim((string) \Tools::getValue('s'));

        return $query !== '' ? ['type' => EventBody::SEARCH, 'query' => $query, 'modality' => 'text'] : null;
    }

    /**
     * Whether the order had a paid status before the one it just reached. PrestaShop runs
     * actionOrderStatusPostUpdate before it writes the new status's history row, so every row
     * here is an earlier status ("Shipped" and "Delivered" count as paid too).
     */
    private static function paidBefore(int $orderId): bool
    {
        return (int) Db::field(
            'SELECT COUNT(*) FROM ?:order_history oh JOIN ?:order_state os ON os.id_order_state = oh.id_order_state WHERE oh.id_order = ?i AND os.paid = 1',
            $orderId
        ) > 0;
    }

    /** The store's host ("shop.example.com") - Shopify sends its myshopify domain. */
    private static function shop(): string
    {
        return strtolower((string) preg_replace('/:\d+$/', '', (string) \Tools::getShopDomainSsl(false)));
    }

    private function __construct()
    {
    }
}
