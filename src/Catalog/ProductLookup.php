<?php

declare(strict_types=1);

namespace Quissly\PrestaShop\Catalog;

use Quissly\PrestaShop\Platform\Db;
use Quissly\PrestaShop\Platform\Text;

/**
 * Products by their reference (PrestaShop's product code, what Most loved lists hold): which
 * product a code is, whether the overlay can show it and why not, and the picker's search.
 * A combination's reference names its product (the card shows the product), as the CS-Cart
 * add-on treats a variation's code.
 */
final class ProductLookup
{
    public const PER_PAGE = 20;

    /** @var array<string, int> code => product id (0 = none), for this request */
    private static $ids = [];

    /** The product a reference names, 0 when none. */
    public static function productId(string $code): int
    {
        if (!array_key_exists($code, self::$ids)) {
            $id = (int) Db::field('SELECT id_product FROM ?:product WHERE reference = ?s ORDER BY id_product LIMIT 1', $code);
            if ($id === 0) {
                $id = (int) Db::field('SELECT id_product FROM ?:product_attribute WHERE reference = ?s ORDER BY id_product_attribute LIMIT 1', $code);
            }
            self::$ids[$code] = $id;
        }

        return self::$ids[$code];
    }

    /**
     * A picked product's name, and why the overlay cannot show it ('' when it can): not_found,
     * not_visible (disabled, or visibility "nowhere"), out_of_stock (nothing to buy and
     * orders when out of stock refused).
     *
     * @return array{title: string, reason: string}
     */
    public static function state(string $code): array
    {
        $id = self::productId($code);
        if ($id === 0) {
            return ['title' => $code, 'reason' => 'not_found'];
        }
        $context = \Context::getContext();
        $row = Db::row(
            'SELECT ps.active, ps.visibility, pl.name FROM ?:product_shop ps'
            . ' LEFT JOIN ?:product_lang pl ON pl.id_product = ps.id_product AND pl.id_shop = ps.id_shop AND pl.id_lang = ?i'
            . ' WHERE ps.id_product = ?i AND ps.id_shop = ?i',
            (int) $context->language->id,
            $id,
            (int) $context->shop->id
        );
        $title = (string) ($row['name'] ?? '') !== '' ? (string) $row['name'] : $code;
        if ($row === [] || (int) $row['active'] !== 1 || (string) $row['visibility'] === 'none') {
            return ['title' => $title, 'reason' => 'not_visible'];
        }
        $inStock = (int) \StockAvailable::getQuantityAvailableByProduct($id) > 0
            || \Product::isAvailableWhenOutOfStock((int) \StockAvailable::outOfStock($id));

        return ['title' => $title, 'reason' => $inStock ? '' : 'out_of_stock'];
    }

    /**
     * A pick as the Configuration picker shows it.
     *
     * @return array{sku: string, name: string, image: string, note: string}
     */
    public static function item(string $code): array
    {
        $state = self::state($code);
        $id = self::productId($code);

        return [
            'sku'   => $code,
            'name'  => $state['title'],
            'image' => $id > 0 ? self::image($id) : '',
            'note'  => $state['reason'] === '' ? '' : Text::t('quissly_search.most_loved_reason_' . $state['reason']),
        ];
    }

    /**
     * The picker's search: products whose name (in the admin's language) or reference contains
     * $query - under 2 characters, the whole catalog - by name, PER_PAGE a page, and whether a
     * next page exists. Products with a reference only (a list holds references).
     *
     * @return array{items: list<array{sku: string, name: string, image: string, note: string}>, more: bool}
     */
    public static function search(string $query, int $page = 1): array
    {
        $context = \Context::getContext();
        $query = trim($query);
        $filter = mb_strlen($query) >= 2
            ? Db::quote(' AND (pl.name LIKE ?l OR p.reference LIKE ?l)', '%' . $query . '%', '%' . $query . '%')
            : '';
        $codes = Db::fields(
            'SELECT p.reference FROM ?:product p'
            . ' JOIN ?:product_shop ps ON ps.id_product = p.id_product AND ps.id_shop = ?i'
            . ' LEFT JOIN ?:product_lang pl ON pl.id_product = p.id_product AND pl.id_shop = ps.id_shop AND pl.id_lang = ?i'
            . " WHERE p.reference != '' ?p"
            . ' GROUP BY p.reference ORDER BY MIN(pl.name), p.reference LIMIT ?i, ?i',
            (int) $context->shop->id,
            (int) $context->language->id,
            $filter,
            (max(1, $page) - 1) * self::PER_PAGE,
            self::PER_PAGE + 1
        );

        return [
            'items' => array_map([self::class, 'item'], array_slice($codes, 0, self::PER_PAGE)),
            'more'  => count($codes) > self::PER_PAGE,
        ];
    }

    /** The product's cover image, small, '' when it has none. */
    private static function image(int $productId): string
    {
        $cover = \Product::getCover($productId);
        if (!is_array($cover) || empty($cover['id_image'])) {
            return '';
        }
        $context = \Context::getContext();
        $rewrite = (string) Db::field('SELECT link_rewrite FROM ?:product_lang WHERE id_product = ?i AND id_lang = ?i AND id_shop = ?i', $productId, (int) $context->language->id, (int) $context->shop->id);

        return (string) $context->link->getImageLink($rewrite !== '' ? $rewrite : 'product', (string) $cover['id_image'], \ImageType::getFormattedName('small'));
    }

    private function __construct()
    {
    }
}
