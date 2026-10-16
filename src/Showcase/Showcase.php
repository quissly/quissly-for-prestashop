<?php

declare(strict_types=1);

namespace Quissly\PrestaShop\Showcase;

use Quissly\PrestaShop\Client;
use Quissly\PrestaShop\Credentials;
use Quissly\PrestaShop\Panel\QChatDirectory;
use Quissly\PrestaShop\Platform\Db;
use Quissly\PrestaShop\Platform\Shop;
use Quissly\PrestaShop\Sync\DbSyncStore;
use Quissly\PrestaShop\Sync\ProductSource;
use Quissly\PrestaShop\Sync\SyncWorker;

/**
 * The cron's showcase step (the CS-Cart add-on's fn_quissly_search_showcase_*): after the first
 * sync, ask Quissly's generator for the store's Search Examples and generated Search Suggestions
 * (POST /v2beta/qsearch/suggestions_enrich, ShowcaseRunner) - per storefront language, with that
 * language's catalog facts. Quissly writes the lists itself and keeps any the merchant edited.
 * Runs from the cron and an admin page's end; ShowcaseRunner decides when one is due.
 */
final class Showcase
{
    public static function tick(DbSyncStore $store): string
    {
        $credentials = Credentials::fromRegistry();
        $serviceId = $credentials->isConfigured() ? QChatDirectory::cachedSearchServiceId($store) : '';
        $ready = $serviceId !== '' && SyncWorker::isGateOpen($store);

        return ShowcaseRunner::tick(
            $store,
            $ready,
            time(),
            array_keys(Shop::languages()),
            [self::class, 'facts'],
            static function (array $body) use ($credentials): ?array {
                // One call can take minutes: a model call and up to 12 searches per language.
                return (new Client($credentials))->post(SuggestionsEnrich::PATH, $body, 180);
            }
        );
    }

    /**
     * Catalog facts for the generator: the most recently changed products a shopper can find -
     * title, main category, brand, features with their values, price - plus the shop's name and
     * currency. In another language than the main one, titles and categories are that
     * language's (features stay out: their values would mix languages).
     *
     * @return array{shop_name: string, currency: string, products: list<array<string, mixed>>}
     */
    public static function facts(string $language = ''): array
    {
        $source = new ProductSource();
        $main = Shop::mainLanguage();
        $translated = $language !== '' && $language !== $main;
        $languageId = $translated ? (int) \Language::getIdByIso($language) : 0;
        $ids = Db::fields(
            "SELECT id_product FROM ?:product_shop WHERE id_shop = ?i AND active = 1 AND visibility != 'none' ORDER BY date_upd DESC LIMIT ?i",
            (int) \Context::getContext()->shop->id,
            SuggestionsEnrich::MAX_PRODUCTS
        );
        $products = [];
        foreach ($ids as $id) {
            $p = $source->load((int) $id, false);
            if ($p === null) {
                continue;
            }
            $options = [];
            $brand = '';
            foreach ((array) ($p['features'] ?? []) as $name => $value) {
                if (mb_strtolower((string) $name) === 'brand') {
                    $brand = (string) $value;
                }
                $values = array_values(array_filter(array_map('trim', (array) preg_split('/\s*[,|]\s*/u', (string) $value)), 'strlen'));
                $options[] = ['name' => (string) $name, 'values' => $values];
            }
            $title = (string) $p['product'];
            $type = (string) ($p['category_name'] ?? '');
            if ($translated && $languageId > 0) {
                $product = new \Product((int) $id, false, $languageId);
                $title = trim((string) $product->name) !== '' ? (string) $product->name : $title;
                $category = new \Category((int) $product->id_category_default, $languageId);
                $type = \Validate::isLoadedObject($category) && trim((string) $category->name) !== '' ? (string) $category->name : $type;
                $options = [];
            }
            $price = is_numeric($p['price'] ?? null) ? (float) $p['price'] : null;
            $products[] = [
                'title'        => $title,
                'product_type' => $type,
                'vendor'       => $brand,
                'options'      => $options,
                'min_price'    => $price,
                'max_price'    => $price,
            ];
        }
        $currency = \Currency::getDefaultCurrency();

        return [
            'shop_name' => (string) \Configuration::get('PS_SHOP_NAME'),
            'currency'  => is_object($currency) ? (string) $currency->iso_code : '',
            'products'  => $products,
        ];
    }

    private function __construct()
    {
    }
}
