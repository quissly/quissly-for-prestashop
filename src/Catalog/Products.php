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

    private static function shopId(): int
    {
        return (int) \Context::getContext()->shop->id;
    }

    private function __construct()
    {
    }
}
