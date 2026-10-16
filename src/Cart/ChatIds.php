<?php

declare(strict_types=1);

namespace Quissly\PrestaShop\Cart;

/**
 * Quissly's own product ids, as the chat widget uses them: uuid5 of the qsearch
 * service's quissly_service_link and our product id (uuid5(link, "247") is the chat's id
 * for product 247). Pure; the same
 * derivation the WooCommerce plugin and the Magento plugin use.
 */
final class ChatIds
{
    public const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public static function uuid5(string $namespace, string $name): string
    {
        $hash = sha1((string) hex2bin(str_replace('-', '', strtolower($namespace))) . $name);

        return sprintf(
            '%s-%s-%04x-%04x-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            (hexdec(substr($hash, 12, 4)) & 0x0fff) | 0x5000,
            (hexdec(substr($hash, 16, 4)) & 0x3fff) | 0x8000,
            substr($hash, 20, 12)
        );
    }

    /**
     * The given Quissly ids that belong to one of $productIds - or, for a product with
     * combinations, to one of them: the chat names the combination a shopper picked by its
     * variant id ("1-2" = product 1, combination 2), the way the catalog sync sent it.
     *
     * @param list<string> $quisslyIds     lower-case UUIDs
     * @param list<int>    $productIds
     * @param list<string> $combinationIds "<id_product>-<id_product_attribute>"
     * @return array<string, int|string> uuid => id_product, or "<id_product>-<id_product_attribute>"
     */
    public static function resolve(string $namespace, array $quisslyIds, array $productIds, array $combinationIds = []): array
    {
        $wanted = array_flip($quisslyIds);
        $out = [];
        foreach ($productIds as $id) {
            $uuid = self::uuid5($namespace, (string) (int) $id);
            if (isset($wanted[$uuid])) {
                $out[$uuid] = (int) $id;
            }
        }
        foreach ($combinationIds as $id) {
            $uuid = self::uuid5($namespace, (string) $id);
            if (isset($wanted[$uuid]) && !isset($out[$uuid])) {
                $out[$uuid] = (string) $id;
            }
        }

        return $out;
    }

    /**
     * A cart request's product: "7" (its default combination) or "1-2" (that combination).
     *
     * @return array{0: int, 1: int}|null [id_product, id_product_attribute - 0 = the default]
     */
    public static function cartTarget(string $value): ?array
    {
        if (!preg_match('/^([1-9]\d{0,9})(?:-([1-9]\d{0,9}))?$/', trim($value), $m)) {
            return null;
        }

        return [(int) $m[1], isset($m[2]) ? (int) $m[2] : 0];
    }

    private function __construct()
    {
    }
}
