<?php

declare(strict_types=1);

namespace Quissly\PrestaShop\Catalog;

use Quissly\PrestaShop\MostLoved;
use Quissly\PrestaShop\Platform\Db;
use Quissly\PrestaShop\Platform\Settings;
use Quissly\PrestaShop\Platform\Text;
use Quissly\PrestaShop\Sync\SyncStore;

/**
 * The Most loved cards in the search overlay (the Shopify app's): 8 picks, an out-of-stock or
 * hidden one replaced by the first available backup (MostLoved::pick), none while switched off
 * or short of picks. Each card: name, link, image, price in the shopper's currency (PrestaShop's
 * own formatting, tax as the shop shows it), rating from Product Comments when installed, "3
 * colors available". Kept five minutes per language and currency: 12 products are not for
 * every page.
 */
final class MostLovedCards
{
    /** @return list<array{title: string, url: string, image: string, price: string, rating: float, ratingCount: int, variants: string}> */
    public static function forShopper(SyncStore $store): array
    {
        if (!Settings::on('most_loved')) {
            return [];
        }
        $shown = MostLoved::codes((string) Settings::get('most_loved_shown'), MostLoved::SHOWN);
        $backups = MostLoved::codes((string) Settings::get('most_loved_backups'), MostLoved::BACKUPS);
        $context = \Context::getContext();
        $key = md5((string) json_encode([$shown, $backups, (int) $context->language->id, (int) $context->currency->id, (int) $context->shop->id]));
        $cached = $store->getState(MostLoved::CACHE);
        if (is_array($cached) && ($cached['key'] ?? '') === $key && (int) ($cached['until'] ?? 0) > time()) {
            return (array) $cached['cards'];
        }
        $codes = MostLoved::pick($shown, $backups, static function (string $code): bool {
            return ProductLookup::state($code)['reason'] === '';
        });
        $cards = [];
        foreach ($codes as $code) {
            $card = self::card(ProductLookup::productId($code));
            if ($card !== null) {
                $cards[] = $card;
            }
        }
        $store->setState(MostLoved::CACHE, ['key' => $key, 'cards' => $cards, 'until' => time() + MostLoved::CACHE_SECONDS]);

        return $cards;
    }

    /** @return array{title: string, url: string, image: string, price: string, rating: float, ratingCount: int, variants: string}|null */
    private static function card(int $id): ?array
    {
        $context = \Context::getContext();
        $product = new \Product($id, false, (int) $context->language->id, (int) $context->shop->id);
        if (!\Validate::isLoadedObject($product)) {
            return null;
        }
        $cover = \Product::getCover($id);
        $image = is_array($cover) && !empty($cover['id_image'])
            ? (string) $context->link->getImageLink((string) $product->link_rewrite, (string) $cover['id_image'], \ImageType::getFormattedName('home'))
            : '';
        $price = (float) \Product::getPriceStatic($id, !\Product::getTaxCalculationMethod((int) $context->customer->id));
        list($rating, $count) = self::rating($id);

        return [
            'title'       => (string) $product->name,
            'url'         => (string) $context->link->getProductLink($product),
            'image'       => $image,
            'price'       => \Tools::displayPrice($price, $context->currency),
            'rating'      => $rating,
            'ratingCount' => $count,
            'variants'    => MostLoved::variantsLabel(self::combinations($id), self::firstOption($id), Text::t('quissly_search.sf_variants'), Text::t('quissly_search.sf_variants_plain')),
        ];
    }

    /**
     * The average rating out of 5 and how many reviews - PrestaShop's Product Comments module,
     * validated comments only; [0, 0] without it.
     *
     * @return array{0: float, 1: int}
     */
    private static function rating(int $id): array
    {
        if (!\Module::isEnabled('productcomments')) {
            return [0.0, 0];
        }
        $row = Db::row('SELECT AVG(grade) AS grade, COUNT(*) AS n FROM ?:product_comment WHERE id_product = ?i AND validate = 1 AND deleted = 0', $id);

        return [round((float) ($row['grade'] ?? 0), 1), (int) ($row['n'] ?? 0)];
    }

    private static function combinations(int $id): int
    {
        return (int) Db::field('SELECT COUNT(*) FROM ?:product_attribute_shop WHERE id_product = ?i AND id_shop = ?i', $id, (int) \Context::getContext()->shop->id);
    }

    /** The first option the combinations differ by ("Color"), '' when none. */
    private static function firstOption(int $id): string
    {
        return (string) Db::field(
            'SELECT agl.public_name FROM ?:product_attribute pa'
            . ' JOIN ?:product_attribute_combination pac ON pac.id_product_attribute = pa.id_product_attribute'
            . ' JOIN ?:attribute a ON a.id_attribute = pac.id_attribute'
            . ' JOIN ?:attribute_group ag ON ag.id_attribute_group = a.id_attribute_group'
            . ' JOIN ?:attribute_group_lang agl ON agl.id_attribute_group = ag.id_attribute_group AND agl.id_lang = ?i'
            . ' WHERE pa.id_product = ?i ORDER BY ag.position LIMIT 1',
            (int) \Context::getContext()->language->id,
            $id
        );
    }

    private function __construct()
    {
    }
}
