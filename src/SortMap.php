<?php

declare(strict_types=1);

namespace Quissly\PrestaShop;

/**
 * PrestaShop's sort orders (SortOrder: entity.field.direction, the search page's `?order=`) to
 * Quissly's sort_by / sort_type codes.
 *
 * Quissly's sort_by codes, as its search engine reads them (since 2026-10-20; 0-2 since 2026-06-22):
 *
 *   0 = relevance (default)   1 = price (discounted_price)   2 = discount (discount_percent)
 *   3 = title (name, case-insensitive)   4 = popularity (metadata.popularity)
 *   5 = created_at (metadata.created_at)
 *
 * sort_type: 1 = asc, 2 = desc. Quissly sorts every match (variants collapsed to one row per
 * product) before it pages, and a product without the field goes last either way - so
 * ProductMapper always sends discounted_price and popularity, and created_at when the product
 * has a date. Until 2026-10-19 price went out as 2, which Quissly reads as discount.
 *
 * PrestaShop's search page offers Relevance (product.position.desc, the search controller's
 * default - also when the shopper picked nothing), Name A-Z / Z-A and Price low-high / high-low
 * (SortOrdersCollection::getDefaults). Sales and date added are PrestaShop's own sorts of its
 * Best sellers and New products pages; the search page shows them too while Quissly answers
 * (SEARCH_PAGE_EXTRAS). Anything else - a manufacturer's name, reference, random, a module's own
 * field - has no Quissly code and keeps Quissly's relevance order.
 *
 * Pure logic, locked by a known-input/known-output test.
 */
final class SortMap
{
    /** Quissly sort_by codes. */
    public const Q_RELEVANCE  = 0;
    public const Q_PRICE      = 1;
    public const Q_DISCOUNT   = 2;
    public const Q_TITLE      = 3;
    public const Q_POPULARITY = 4;
    public const Q_CREATED    = 5;

    /** Quissly sort_type codes. */
    public const T_ASC  = 1;
    public const T_DESC = 2;

    /** A product SortOrder field => the Quissly code that sorts on it. */
    private const FIELDS = [
        'price'    => self::Q_PRICE,
        'name'     => self::Q_TITLE,
        'sales'    => self::Q_POPULARITY,
        'date_add' => self::Q_CREATED,
    ];

    /**
     * The search page's sort orders beyond PrestaShop's defaults - [field, direction, label] -
     * labelled as PrestaShop labels them on its Best sellers and New products pages (domain
     * Shop.Theme.Catalog, so the shop's own translations apply). Sales only descending: PrestaShop
     * offers no other.
     */
    public const SEARCH_PAGE_EXTRAS = [
        ['sales', 'desc', 'Sales, highest to lowest'],
        ['date_add', 'desc', 'Date added, newest to oldest'],
        ['date_add', 'asc', 'Date added, oldest to newest'],
    ];

    /**
     * A SortOrder to [sort_by, sort_type]: a product field Quissly sorts on, ascending or
     * descending; anything else is relevance (sort_type ignored by Quissly, ascending sent).
     *
     * @return array{0: int, 1: int}
     */
    public static function resolve(?string $entity, ?string $field, ?string $direction): array
    {
        $field = strtolower((string) $field);
        $direction = strtolower((string) $direction);
        if (strtolower((string) $entity) === 'product' && isset(self::FIELDS[$field]) && in_array($direction, ['asc', 'desc'], true)) {
            return [self::FIELDS[$field], $direction === 'desc' ? self::T_DESC : self::T_ASC];
        }

        return [self::Q_RELEVANCE, self::T_ASC];
    }

    private function __construct()
    {
    }
}
