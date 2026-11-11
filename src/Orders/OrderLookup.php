<?php

declare(strict_types=1);

namespace Quissly\PrestaShop\Orders;

/**
 * QChat's order lookup for one verified request (controllers/front/orderlookup.php checked the
 * signature): this shop's order with that reference or id, and - only when the email or phone is the
 * order's own - its status, items and tracking in the shape the engine reads for Shopify orders.
 * Anything else is `not_found`, never saying which part did not match.
 */
final class OrderLookup
{
    public const MAX_ITEMS = 50;

    /** @return array<string, mixed> */
    public static function answer(string $number, string $contact): array
    {
        $order = self::find($number);
        if ($order === null) {
            return ['status' => 'not_found'];
        }
        $customer = new \Customer((int) $order->id_customer);
        $phones = [];
        foreach ([(int) $order->id_address_invoice, (int) $order->id_address_delivery] as $id) {
            $address = new \Address($id);
            if (\Validate::isLoadedObject($address)) {
                $phones[] = (string) $address->phone;
                $phones[] = (string) $address->phone_mobile;
            }
        }
        if (!LookupRules::contactMatches($contact, [(string) $customer->email], $phones)) {
            return ['status' => 'not_found'];
        }

        $state = $order->getCurrentOrderState();
        $current = (int) $order->current_state;
        $statuses = LookupRules::statuses(
            $state ? (bool) $state->paid : false,
            $state ? (bool) $state->shipped : false,
            $state ? (bool) $state->delivered : false,
            in_array($current, [(int) \Configuration::get('PS_OS_CANCELED'), (int) \Configuration::get('PS_OS_ERROR')], true),
            $current === (int) \Configuration::get('PS_OS_REFUND')
        );
        $items = [];
        foreach ((array) $order->getProducts() as $line) {
            if (count($items) >= self::MAX_ITEMS) {
                break;
            }
            $items[] = ['title' => strip_tags((string) ($line['product_name'] ?? '')), 'quantity' => (int) ($line['product_quantity'] ?? 0)];
        }
        $tracking = [];
        foreach ((array) $order->getShipping() as $row) {
            $trackingNumber = trim((string) ($row['tracking_number'] ?? ''));
            if ($trackingNumber !== '') {
                $tracking[] = [
                    'company' => (string) ($row['carrier_name'] ?? ''),
                    'number'  => $trackingNumber,
                    'url'     => LookupRules::trackingUrl((string) ($row['url'] ?? ''), $trackingNumber),
                ];
            }
        }
        $link = \Context::getContext()->link;

        return [
            'status' => 'found',
            'order'  => [
                'order_number'       => (string) $order->reference,
                'created_at'         => self::utc((string) $order->date_add),
                'cancelled'          => $statuses['cancelled'],
                'fulfillment_status' => $statuses['fulfillment_status'],
                'financial_status'   => $statuses['financial_status'],
                'tracking'           => $tracking,
                'items'              => $items,
                // The shopper's own order page; a guest's is the guest tracking form.
                'status_page_url'    => $customer->is_guest
                    ? (string) $link->getPageLink('guest-tracking', true)
                    : (string) $link->getPageLink('order-detail', true, null, ['id_order' => (int) $order->id]),
            ],
        ];
    }

    /** This shop's order: by its reference ("XKBKNABJK"), or by its id when the shopper gave digits. */
    private static function find(string $number): ?\Order
    {
        $clean = (string) preg_replace('/[^0-9A-Za-z]/', '', $number);
        if ($clean === '') {
            return null;
        }
        $shop = (int) \Context::getContext()->shop->id;
        $candidates = [];
        if (ctype_digit($clean) && strlen($clean) <= 10) {
            $candidates[] = new \Order((int) $clean);
        }
        if (\Validate::isReference($clean)) {
            foreach (\Order::getByReference(strtoupper($clean)) as $order) {
                $candidates[] = $order;
            }
        }
        foreach ($candidates as $order) {
            $own = (string) $order->reference === strtoupper($clean) || (string) (int) $order->id === ltrim($clean, '0');
            if (\Validate::isLoadedObject($order) && (int) $order->id_shop === $shop && $own) {
                return $order;
            }
        }

        return null;
    }

    /** A PrestaShop date (the shop's time zone) in UTC, ISO 8601. */
    private static function utc(string $date): string
    {
        try {
            $zone = new \DateTimeZone((string) \Configuration::get('PS_TIMEZONE') ?: 'UTC');
            $at = new \DateTime($date, $zone);
            $at->setTimezone(new \DateTimeZone('UTC'));

            return $at->format('Y-m-d\TH:i:s\Z');
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function __construct()
    {
    }
}
