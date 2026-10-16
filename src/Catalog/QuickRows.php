<?php

declare(strict_types=1);

namespace Quissly\PrestaShop\Catalog;

use Quissly\PrestaShop\Platform\Db;

/**
 * Quick Recommendations' rows (src/Quick/QuickEndpoint.php), in Quissly's order: each from the
 * shop's own product in the shopper's language and currency - name, link, image, price with tax
 * as the shop shows it (and the price before a discount when there is one) - as the Most loved
 * cards are; Quissly's Quick answers ids only. A product that is gone, not active, or not
 * visible in search is left out.
 */
final class QuickRows
{
    /**
     * @param list<int> $ids
     * @return list<array{id: int, title: string, url: string, image_url: string, price_label: string, original_price_label: string}>
     */
    public static function forIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $context = \Context::getContext();
        $shopId = (int) $context->shop->id;
        $visible = array_map('intval', Db::fields(
            'SELECT id_product FROM ?:product_shop WHERE id_shop = ?i AND active = 1 AND visibility IN (?a) AND id_product IN (?n)',
            $shopId,
            ['both', 'search'],
            $ids
        ));
        $withTax = !\Product::getTaxCalculationMethod((int) $context->customer->id);

        $rows = [];
        foreach ($ids as $id) {
            if (!in_array((int) $id, $visible, true)) {
                continue;
            }
            $product = new \Product((int) $id, false, (int) $context->language->id, $shopId);
            if (!\Validate::isLoadedObject($product)) {
                continue;
            }
            $cover = \Product::getCover((int) $id);
            $price = (float) \Product::getPriceStatic((int) $id, $withTax);
            $before = (float) \Product::getPriceStatic((int) $id, $withTax, null, 6, null, false, false);
            $rows[] = [
                'id'                   => (int) $id,
                'title'                => (string) $product->name,
                'url'                  => (string) $context->link->getProductLink($product),
                'image_url'            => is_array($cover) && !empty($cover['id_image'])
                    ? (string) $context->link->getImageLink((string) $product->link_rewrite, (string) $cover['id_image'], \ImageType::getFormattedName('home'))
                    : '',
                'price_label'          => $price > 0 ? (string) \Tools::displayPrice($price, $context->currency) : '',
                'original_price_label' => $price > 0 && $before > $price ? (string) \Tools::displayPrice($before, $context->currency) : '',
            ];
        }

        return $rows;
    }

    private function __construct()
    {
    }
}
