<?php

declare(strict_types=1);

namespace Quissly\PrestaShop\Catalog;

use Quissly\PrestaShop\Platform\Db;

/**
 * The shop's catalog as Quissly gets it: the products a shopper can find - enabled, and visible
 * somewhere (not "nowhere") - in the current shop.
 */
final class Products
{
    public static function countActive(): int
    {
        return (int) Db::field(
            "SELECT COUNT(*) FROM ?:product_shop WHERE id_shop = ?i AND active = 1 AND visibility != 'none'",
            self::shopId()
        );
    }

    /** @return list<int> */
    public static function activeIds(): array
    {
        return array_map('intval', Db::fields(
            "SELECT id_product FROM ?:product_shop WHERE id_shop = ?i AND active = 1 AND visibility != 'none' ORDER BY id_product",
            self::shopId()
        ));
    }

    /**
     * The combinations of the active products, as the catalog sync names them (the variant ids
     * "<id_product>-<id_product_attribute>", Events\EventBody::variantId).
     *
     * @return list<string>
     */
    public static function activeCombinationIds(): array
    {
        $ids = [];
        foreach (Db::all(
            "SELECT pas.id_product, pas.id_product_attribute FROM ?:product_attribute_shop pas"
            . " JOIN ?:product_shop ps ON ps.id_product = pas.id_product AND ps.id_shop = pas.id_shop"
            . " WHERE pas.id_shop = ?i AND ps.active = 1 AND ps.visibility != 'none' ORDER BY pas.id_product, pas.id_product_attribute",
            self::shopId()
        ) as $row) {
            $ids[] = (int) $row['id_product'] . '-' . (int) $row['id_product_attribute'];
        }

        return $ids;
    }

    private static function shopId(): int
    {
        return (int) \Context::getContext()->shop->id;
    }

    private function __construct()
    {
    }
}
