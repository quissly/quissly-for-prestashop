<?php

declare(strict_types=1);

namespace Quissly\PrestaShop\Sync;

use Quissly\PrestaShop\Platform\Db;

/**
 * Catalog data: which product features go to Quissly (the Magento plugin's "Product
 * attributes sent to Quissly").
 *
 * The merchant's checklist is stored as DEVIATIONS from the default: a feature is sent
 * when the storefront shows it (Active, and shown on the product page, the catalog or the
 * product header), unless the merchant ticked a hidden one on (true) or unticked a shown
 * one off (false). Storing only the deviations means a feature created later just follows
 * its storefront settings, and saving the list unchanged changes nothing. A Disabled
 * feature is never offered or sent. Variation-group features (Color, Size) always go as
 * the options that tell the variants apart, whatever this list says.
 *
 * included() and choicesFrom() are pure; the rest reads CS-Cart.
 */
final class CatalogFeatures
{
    public const STATE = 'catalog_choices';

    /**
     * Whether a feature goes to Quissly.
     *
     * @param array<int, bool> $choices feature_id => bool
     */
    public static function included(int $featureId, bool $shown, array $choices): bool
    {
        return array_key_exists($featureId, $choices) ? (bool) $choices[$featureId] : $shown;
    }

    /**
     * The choices a submitted checklist amounts to: only the features whose tick differs
     * from what the list showed pre-ticked.
     *
     * @param array<int, bool> $defaults offered feature_id => pre-ticked (shown on the storefront)
     * @param list<int|string> $ticked   feature ids ticked on submit
     * @return array<int, bool>
     */
    public static function choicesFrom(array $defaults, array $ticked): array
    {
        $ticked = array_flip(array_map('intval', $ticked));
        $choices = [];
        foreach ($defaults as $id => $default) {
            $on = isset($ticked[(int) $id]);
            if ($on !== (bool) $default) {
                $choices[(int) $id] = $on;
            }
        }
        ksort($choices);

        return $choices;
    }

    /** @return array<int, bool> */
    public static function choices(SyncStore $store): array
    {
        $choices = [];
        foreach ((array) $store->getState(self::STATE) as $id => $on) {
            $choices[(int) $id] = (bool) $on;
        }

        return $choices;
    }

    /**
     * Every feature that is not Disabled and not a group, id => {name, shown, products,
     * variation}: whether the storefront shows it, how many products carry it, and in how
     * many variation groups it tells the variants apart. Sorted by name.
     *
     * @return array<int, array{name:string, shown:bool, products:int, variation:int}>
     */
    public static function catalog(): array
    {
        $language = (int) \Context::getContext()->language->id;
        $out = [];
        // Product features (Composition, Property...): PrestaShop shows every one on the product page.
        foreach (Db::all(
            'SELECT f.id_feature, fl.name,'
            . ' (SELECT COUNT(DISTINCT fp.id_product) FROM ?:feature_product fp WHERE fp.id_feature = f.id_feature) AS products'
            . ' FROM ?:feature f JOIN ?:feature_lang fl ON fl.id_feature = f.id_feature AND fl.id_lang = ?i',
            $language
        ) as $row) {
            $out[(int) $row['id_feature']] = ['name' => (string) $row['name'], 'shown' => true, 'products' => (int) $row['products'], 'variation' => 0];
        }
        // Combination options (Color, Size...): always sent on the products that have them, as a
        // variation's options are; a negative id keeps them apart from the features.
        foreach (Db::all(
            'SELECT ag.id_attribute_group, agl.public_name AS name,'
            . ' (SELECT COUNT(DISTINCT pa.id_product) FROM ?:product_attribute pa'
            . ' JOIN ?:product_attribute_combination pac ON pac.id_product_attribute = pa.id_product_attribute'
            . ' JOIN ?:attribute a ON a.id_attribute = pac.id_attribute WHERE a.id_attribute_group = ag.id_attribute_group) AS products'
            . ' FROM ?:attribute_group ag JOIN ?:attribute_group_lang agl ON agl.id_attribute_group = ag.id_attribute_group AND agl.id_lang = ?i',
            $language
        ) as $row) {
            $out[-(int) $row['id_attribute_group']] = ['name' => (string) $row['name'], 'shown' => true, 'products' => (int) $row['products'], 'variation' => (int) $row['products']];
        }
        uasort($out, static function (array $a, array $b): int {
            return strcasecmp($a['name'], $b['name']);
        });

        return $out;
    }

    /**
     * Save a submitted checklist; true when the choices changed (so the catalog must be
     * re-sent).
     *
     * @param list<int|string> $offered feature ids the form listed
     * @param list<int|string> $ticked  feature ids ticked
     */
    public static function save(SyncStore $store, array $offered, array $ticked): bool
    {
        $catalog = self::catalog();
        $defaults = [];
        foreach (array_map('intval', $offered) as $id) {
            if (isset($catalog[$id])) {
                $defaults[$id] = $catalog[$id]['shown'];
            }
        }
        $choices = self::choicesFrom($defaults, $ticked);
        if ($choices === self::choices($store)) {
            return false;
        }
        $store->setState(self::STATE, $choices === [] ? null : $choices);

        return true;
    }

    private function __construct()
    {
    }
}
