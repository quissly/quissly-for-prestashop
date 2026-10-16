<?php

declare(strict_types=1);

namespace Quissly\PrestaShop\Sync;

use Quissly\PrestaShop\Catalog\Products;
use Quissly\PrestaShop\Platform\Db;

/**
 * PrestaShop glue: which products to sync, and each one in the normalized shape
 * {@see ProductMapper::map()} takes. Everything PrestaShop-specific about reading a product
 * lives here, so the mapper stays pure.
 *
 * Read as a visitor sees the shop: the main language, the default currency, prices with or
 * without tax as the shop shows them to a visitor (the "Visitor" group's price display),
 * specific prices (sales) applied - the price without them is the "list price" - and only
 * products a shopper can find (enabled, visible somewhere: Products::activeIds).
 *
 * Combinations: a product with combinations is the catalog card, and its combinations are
 * what is bought (PrestaShop never sells the product without one). The product goes out with
 * its combinations as `variations`, each with its own options (attribute group public name =>
 * attribute, e.g. Size: S, Color: White), reference, price, stock, images and URL (the product
 * page opened on that combination). A combination's id is its own numbering - combination 5 can
 * belong to product 1 while product 5 exists - and Quissly files every record under one id
 * space, so a combination is sent as "<id_product>-<id_product_attribute>" (the events name it
 * the same way). The product itself is not one of its variants (`parent_is_variant` false), it
 * is in stock when one of its combinations is, and it shows the default combination's price.
 *
 * Stock: buyable when stock management is off, the quantity is above 0, or the product allows
 * orders when out of stock (its own setting, or the shop's default) - and never when it is not
 * "available for order". The in-stock state each load finds is remembered (stockChanged() tells
 * a quantity change that flips it from one that does not).
 *
 * Features: the ones the Catalog data checklist sends (CatalogFeatures; PrestaShop shows every
 * feature on the product page, so all by default), several values of one feature joined; the
 * brand (manufacturer) as "Brand".
 */
final class ProductSource
{
    public const STOCK_STATE = 'stock_sent';

    /**
     * Ids of every product the sync sends. Ascending.
     *
     * @return list<int>
     */
    public function activeIds(): array
    {
        return Products::activeIds();
    }

    public function countActive(): int
    {
        return Products::countActive();
    }

    /** PrestaShop's products are never children of other products. */
    public function parentOf(int $productId): int
    {
        return 0;
    }

    /**
     * The normalized product (whatever its status - the worker decides), or null when
     * PrestaShop no longer has it.
     *
     * @return ?array<string,mixed>
     */
    public function load(int $productId, bool $withVariations = true): ?array
    {
        $context = \Context::getContext();
        $language = (int) \Configuration::get('PS_LANG_DEFAULT');
        $shopId = (int) $context->shop->id;
        $product = new \Product($productId, true, $language, $shopId);
        if (!\Validate::isLoadedObject($product)) {
            return null;
        }
        $active = (bool) $product->active && (string) $product->visibility !== 'none';
        $withTax = !\Group::getPriceDisplayMethod((int) \Configuration::get('PS_UNIDENTIFIED_GROUP'));
        $link = $context->link;
        $rewrite = is_array($product->link_rewrite) ? (string) reset($product->link_rewrite) : (string) $product->link_rewrite;

        $combinations = $withVariations ? $this->combinations($product, $language, $withTax, $rewrite) : [];
        $inStock = $combinations === []
            ? self::buyable($product, 0)
            : in_array(true, array_column($combinations, 'in_stock'), true);
        $this->rememberStock($productId, $inStock);

        return [
            'product_id'        => $productId,
            'record_id'         => (string) $productId,
            'status'            => $active ? 'A' : 'D',
            'vendor_active'     => true,
            'parent_product_id' => 0,
            'parent_is_variant' => false,
            'options'           => [],
            'features'          => self::features($product, $language),
            'variations'        => $combinations,
            'product'           => (string) $product->name,
            'product_code'      => (string) $product->reference,
            'price'             => \Product::getPriceStatic($productId, $withTax, null, 2, null, false, true),
            'list_price'        => \Product::getPriceStatic($productId, $withTax, null, 2, null, false, false),
            'in_stock'          => $inStock,
            'full_description'  => (string) $product->description,
            'short_description' => (string) $product->description_short,
            'category_name'     => self::categoryName((int) $product->id_category_default, $language),
            'category_names'    => self::categoryNames($productId, $language),
            'images'            => self::images($productId, 0, $language, $rewrite),
            'url'               => (string) $link->getProductLink($product, null, null, null, $language, $shopId),
            'company'           => '',
            'bundles'           => [],
        ];
    }

    /**
     * Whether a quantity change moved the product between in and out of stock since it was
     * last read for Quissly (true too when it was never read: send it to be sure).
     */
    public function stockChanged(int $productId): bool
    {
        $product = new \Product($productId, false, (int) \Configuration::get('PS_LANG_DEFAULT'));
        if (!\Validate::isLoadedObject($product)) {
            return false;
        }
        $ids = array_map('intval', Db::fields('SELECT id_product_attribute FROM ?:product_attribute WHERE id_product = ?i', $productId));
        $now = $ids === [] ? self::buyable($product, 0) : false;
        foreach ($ids as $id) {
            if (self::buyable($product, $id)) {
                $now = true;
                break;
            }
        }
        $sent = (new DbSyncStore())->getState(self::STOCK_STATE . '_' . $productId);

        return $sent === null || (bool) ($sent['in'] ?? false) !== $now;
    }

    /**
     * The product's combinations, normalized as variations.
     *
     * @return list<array<string,mixed>>
     */
    private function combinations(\Product $product, int $language, bool $withTax, string $rewrite): array
    {
        $productId = (int) $product->id;
        $rows = Db::all('SELECT id_product_attribute, reference FROM ?:product_attribute WHERE id_product = ?i ORDER BY id_product_attribute', $productId);
        if ($rows === []) {
            return [];
        }
        $options = self::options($productId, $language);
        $link = \Context::getContext()->link;
        $out = [];
        foreach ($rows as $row) {
            $id = (int) $row['id_product_attribute'];
            $reference = (string) $row['reference'];
            $out[] = [
                'product_id'        => $productId,
                'record_id'         => $productId . '-' . $id,
                'status'            => 'A',
                'parent_product_id' => $productId,
                'options'           => $options[$id] ?? [],
                'features'          => [],
                'product'           => (string) $product->name,
                'product_code'      => $reference !== '' ? $reference : (string) $product->reference,
                'price'             => \Product::getPriceStatic($productId, $withTax, $id, 2, null, false, true),
                'list_price'        => \Product::getPriceStatic($productId, $withTax, $id, 2, null, false, false),
                'in_stock'          => self::buyable($product, $id),
                'full_description'  => '',
                'short_description' => '',
                'category_name'     => '',
                'category_names'    => [],
                'images'            => self::images($productId, $id, $language, $rewrite),
                'url'               => (string) $link->getProductLink($product, null, null, null, $language, null, $id, false, false, true),
                'company'           => '',
                'bundles'           => [],
            ];
        }

        return $out;
    }

    /**
     * Each combination's options, attribute group public name => attribute name, in the
     * groups' order.
     *
     * @return array<int, array<string, string>>
     */
    private static function options(int $productId, int $language): array
    {
        $rows = Db::all(
            'SELECT pac.id_product_attribute, agl.public_name AS name, al.name AS value'
            . ' FROM ?:product_attribute pa'
            . ' JOIN ?:product_attribute_combination pac ON pac.id_product_attribute = pa.id_product_attribute'
            . ' JOIN ?:attribute a ON a.id_attribute = pac.id_attribute'
            . ' JOIN ?:attribute_lang al ON al.id_attribute = a.id_attribute AND al.id_lang = ?i'
            . ' JOIN ?:attribute_group ag ON ag.id_attribute_group = a.id_attribute_group'
            . ' JOIN ?:attribute_group_lang agl ON agl.id_attribute_group = ag.id_attribute_group AND agl.id_lang = ?i'
            . ' WHERE pa.id_product = ?i ORDER BY ag.position, a.position',
            $language,
            $language,
            $productId
        );
        $out = [];
        foreach ($rows as $row) {
            $name = trim((string) $row['name']);
            $value = trim((string) $row['value']);
            if ($name !== '' && $value !== '') {
                $out[(int) $row['id_product_attribute']][$name] = $value;
            }
        }

        return $out;
    }

    /** Whether a shopper can buy the product (or one combination of it) now. */
    private static function buyable(\Product $product, int $combinationId): bool
    {
        if (!(bool) $product->available_for_order) {
            return false;
        }
        if (!\Configuration::get('PS_STOCK_MANAGEMENT')) {
            return true;
        }
        if ((int) \StockAvailable::getQuantityAvailableByProduct((int) $product->id, $combinationId) > 0) {
            return true;
        }

        return (bool) \Product::isAvailableWhenOutOfStock((int) \StockAvailable::outOfStock((int) $product->id));
    }

    private function rememberStock(int $productId, bool $inStock): void
    {
        (new DbSyncStore())->setState(self::STOCK_STATE . '_' . $productId, ['in' => $inStock]);
    }

    /**
     * The product's features that go to Quissly, name => value; the brand as "Brand".
     *
     * @return array<string, string>
     */
    private static function features(\Product $product, int $language): array
    {
        static $choices = null;
        if ($choices === null) {
            $choices = CatalogFeatures::choices(new DbSyncStore());
        }
        $values = [];
        foreach ((array) \Product::getFrontFeaturesStatic($language, (int) $product->id) as $feature) {
            $id = (int) ($feature['id_feature'] ?? 0);
            $name = trim((string) ($feature['name'] ?? ''));
            $value = trim((string) ($feature['value'] ?? ''));
            if ($name === '' || $value === '' || !CatalogFeatures::included($id, true, $choices)) {
                continue;
            }
            $values[$name][] = $value;
        }
        $out = [];
        foreach ($values as $name => $list) {
            $out[$name] = implode(', ', array_unique($list));
        }
        $brand = trim((string) $product->manufacturer_name);
        if ($brand === '' && (int) $product->id_manufacturer > 0) {
            $brand = trim((string) \Manufacturer::getNameById((int) $product->id_manufacturer));
        }
        if ($brand !== '' && !isset($out['Brand'])) {
            $out['Brand'] = $brand;
        }

        return $out;
    }

    private static function categoryName(int $categoryId, int $language): string
    {
        if ($categoryId <= 0) {
            return '';
        }
        $category = new \Category($categoryId, $language);

        return \Validate::isLoadedObject($category) && !$category->is_root_category ? (string) $category->name : '';
    }

    /**
     * Every category the product is in, without the root and home categories.
     *
     * @return list<string>
     */
    private static function categoryNames(int $productId, int $language): array
    {
        $skip = [(int) \Configuration::get('PS_ROOT_CATEGORY'), (int) \Configuration::get('PS_HOME_CATEGORY')];
        $names = [];
        foreach ((array) \Product::getProductCategoriesFull($productId, $language) as $category) {
            $name = trim((string) ($category['name'] ?? ''));
            if ($name !== '' && !in_array((int) ($category['id_category'] ?? 0), $skip, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * The product's images (a combination's own, when it has some), cover first, as large
     * images' absolute URLs.
     *
     * @return list<string>
     */
    private static function images(int $productId, int $combinationId, int $language, string $rewrite): array
    {
        $rows = (array) \Image::getImages($language, $productId, $combinationId > 0 ? $combinationId : null);
        if ($rows === [] && $combinationId > 0) {
            return [];
        }
        usort($rows, static function (array $a, array $b): int {
            return [(int) empty($a['cover']), (int) ($a['position'] ?? 0)] <=> [(int) empty($b['cover']), (int) ($b['position'] ?? 0)];
        });
        $type = \ImageType::getFormattedName('large');
        $link = \Context::getContext()->link;
        $urls = [];
        foreach ($rows as $row) {
            $url = (string) $link->getImageLink($rewrite, (string) (int) $row['id_image'], $type);
            if ($url !== '') {
                $urls[] = strpos($url, '//') === 0 ? 'https:' . $url : $url;
            }
        }

        return $urls;
    }
}
