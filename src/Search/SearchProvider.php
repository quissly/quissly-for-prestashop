<?php

declare(strict_types=1);

namespace Quissly\PrestaShop\Search;

use PrestaShop\PrestaShop\Adapter\Search\SearchProductSearchProvider;
use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchContext;
use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchProviderInterface;
use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchQuery;
use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchResult;
use PrestaShop\PrestaShop\Core\Product\Search\SortOrderFactory;
use Quissly\PrestaShop\Client;
use Quissly\PrestaShop\Config;
use Quissly\PrestaShop\Credentials;
use Quissly\PrestaShop\Exception\AuthException;
use Quissly\PrestaShop\Exception\QuisslyException;
use Quissly\PrestaShop\HealthStore;
use Quissly\PrestaShop\Logger;
use Quissly\PrestaShop\Media\DbTokenStore;
use Quissly\PrestaShop\Media\MediaEndpoint;
use Quissly\PrestaShop\Platform\Db;
use Quissly\PrestaShop\Platform\Settings;
use Quissly\PrestaShop\SearchSignal;
use Quissly\PrestaShop\Shopper;
use Quissly\PrestaShop\SortMap;
use Quissly\PrestaShop\Sync\DbSyncStore;
use Quissly\PrestaShop\Sync\SyncWorker;

/**
 * The storefront search, answered by Quissly - PrestaShop's own extension point for it: the
 * search page asks modules for a provider (hook productSearchProvider), and runs PrestaShop's
 * search when none answers. The theme renders the products as always; this only decides which
 * products, in which order, and how many there are for the pager.
 *
 * forQuery() is the guard: a provider only for the storefront search page's text search
 * (2-150 characters), with search switched on, the store connected and Quissly's first full
 * sync confirmed - when in doubt it returns null and PrestaShop's search answers.
 *
 * Fallback, as the other plugins: a transport failure, 5xx, 429, 400 or 422 on page 1 runs
 * PrestaShop's search instead; on a later page it shows an empty page (a native swap would
 * mix two result sets across pages). 401/402/403 also run PrestaShop's search, and the
 * merchant is told on the Dashboard. A 200 with no documents is a real "nothing found".
 */
final class SearchProvider implements ProductSearchProviderInterface
{
    /** @var ProductSearchProviderInterface|null PrestaShop's own search, for the fallback */
    private $native;

    /** @var callable|null test seam: Credentials -> client with qsearch() */
    private static $clientFactory;

    public function __construct(ProductSearchProviderInterface $native = null)
    {
        $this->native = $native;
    }

    /**
     * The guard. A provider for this query, or null to leave it to PrestaShop.
     *
     * @param mixed $query the hook's ProductSearchQuery
     */
    public static function forQuery($query): ?self
    {
        if (!$query instanceof ProductSearchQuery) {
            return null;
        }
        $controller = \Context::getContext()->controller ?? null;
        if (!is_object($controller) || ($controller->php_self ?? '') !== 'search') {
            return null; // categories, brands, new products...: never
        }
        // From here on this is the search page: a guard that fails says why (SearchSignal).
        if (!Settings::on('search_interception')) {
            SearchSignal::recordSkip('search-off');

            return null;
        }
        if (self::mediaToken() === '') {
            $text = trim((string) $query->getSearchString());
            $length = mb_strlen($text);
            if ((string) $query->getSearchTag() !== '' || $length < Config::MIN_QUERY_LENGTH || $length > Config::MAX_QUERY_LENGTH) {
                SearchSignal::recordSkip('no-term');

                return null;
            }
            // A faceted filter on the search page (ps_facetedsearch's ?q=...): PrestaShop answers,
            // so the filter the shopper picked is not silently dropped.
            if ((string) \Tools::getValue('q') !== '') {
                SearchSignal::recordSkip('filter:q');

                return null;
            }
        }
        if (!Credentials::fromRegistry()->isConfigured()) {
            SearchSignal::recordSkip('not-configured');

            return null;
        }
        if (!SyncWorker::isGateOpen(new DbSyncStore())) {
            SearchSignal::recordSkip('gate-closed');

            return null;
        }

        return new self(new SearchProductSearchProvider(\Context::getContext()->getTranslator()));
    }

    public function runQuery(ProductSearchContext $context, ProductSearchQuery $query)
    {
        $token = self::mediaToken();
        if ($token !== '') {
            return $this->mediaResult($context, $query, $token);
        }

        $text = trim((string) $query->getSearchString());
        $page = max(1, (int) $query->getPage());
        $perPage = max(1, (int) $query->getResultsPerPage() ?: Config::DEFAULT_PAGE_SIZE);
        $sort = self::sort($query);

        try {
            $credentials = Credentials::fromRegistry();
            $client = self::$clientFactory !== null ? (self::$clientFactory)($credentials) : new Client($credentials);
            $result = $client->qsearch($text, self::userId(), $page, $perPage, $sort[0], $sort[1], Shopper::deviceAndOs((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')));
        } catch (AuthException $e) {
            Logger::error('QSearch auth/plan failure surfaced to merchant', ['status' => $e->getHttpStatus()]);
            HealthStore::recordFailure($e->getHttpStatus(), $e->getMessage());
            SearchSignal::recordFallback(SearchSignal::codeFor($e));

            return $this->nativeResult($context, $query);
        } catch (QuisslyException $e) {
            SearchSignal::recordFallback(SearchSignal::codeFor($e));
            // Page 1: PrestaShop's search, silently. A later page: empty, not a mixed set.
            return $page === 1 ? $this->nativeResult($context, $query) : new ProductSearchResult();
        }

        SearchSignal::recordHit(count($result->ids), $result->numTotalResults);
        // Quissly answered: an earlier refusal shown on the Dashboard is over.
        HealthStore::clearFailure();
        \Hook::exec('actionSearch', ['searched_query' => $text, 'total' => $result->numTotalResults, 'expr' => $text]);

        return $this->result(self::visible($result->ids, (int) $context->getIdShop()), $result->numTotalResults);
    }

    /** A voice/image results page: the products chosen when the shopper spoke or sent the photo. */
    private function mediaResult(ProductSearchContext $context, ProductSearchQuery $query, string $token): ProductSearchResult
    {
        $ids = (new DbTokenStore())->get($token, time());
        if ($ids === null) {
            return new ProductSearchResult(); // expired: an empty page, never a text search
        }
        $ids = self::visible($ids, (int) $context->getIdShop());
        $perPage = max(1, (int) $query->getResultsPerPage() ?: Config::DEFAULT_PAGE_SIZE);
        $offset = (max(1, (int) $query->getPage()) - 1) * $perPage;

        return $this->result(array_slice($ids, $offset, $perPage), count($ids));
    }

    /** @param list<int> $ids product ids, in Quissly's order */
    private function result(array $ids, int $total): ProductSearchResult
    {
        $products = [];
        foreach ($ids as $id) {
            $products[] = ['id_product' => $id, 'id_product_attribute' => 0];
        }
        $result = new ProductSearchResult();
        $result->setProducts($products)->setTotalProductsCount($total);
        $result->setAvailableSortOrders((new SortOrderFactory(\Context::getContext()->getTranslator()))->getDefaultSortOrders());

        return $result;
    }

    private function nativeResult(ProductSearchContext $context, ProductSearchQuery $query)
    {
        return $this->native !== null ? $this->native->runQuery($context, $query) : new ProductSearchResult();
    }

    /**
     * Only the ids the shop shows in search (active, visible "everywhere" or "search only"), in
     * the order Quissly gave - an id Quissly still has but the shop hides is left out.
     *
     * @param list<int> $ids
     * @return list<int>
     */
    private static function visible(array $ids, int $shopId): array
    {
        if ($ids === []) {
            return [];
        }
        $shown = array_flip(array_map('intval', Db::fields(
            "SELECT id_product FROM ?:product_shop WHERE id_product IN (?n) AND id_shop = ?i AND active = 1 AND visibility IN ('both', 'search')",
            $ids,
            $shopId
        )));

        return array_values(array_filter($ids, static function (int $id) use ($shown): bool {
            return isset($shown[$id]);
        }));
    }

    /**
     * PrestaShop's sort control to Quissly's codes: price (asc/desc) is the only sort Quissly
     * honours besides relevance; anything else is relevance.
     *
     * @return array{0: int, 1: int} sort_by, sort_type
     */
    private static function sort(ProductSearchQuery $query): array
    {
        $order = $query->getSortOrder();
        if ($order !== null && $order->getField() === 'price') {
            return [SortMap::Q_PRICE, strtolower((string) $order->getDirection()) === 'desc' ? SortMap::T_DESC : SortMap::T_ASC];
        }

        return [SortMap::Q_RELEVANCE, SortMap::T_ASC];
    }

    private static function mediaToken(): string
    {
        foreach ([MediaEndpoint::QUERY_VAR_VOICE, MediaEndpoint::QUERY_VAR_IMAGE] as $param) {
            $token = (string) \Tools::getValue($param);
            if ($token !== '' && preg_match('/^[a-f0-9-]{36}$/', $token)) {
                return $token;
            }
        }

        return '';
    }

    /**
     * The shopper sent to Quissly: customer:<id> when signed in, else guest:<uuid> from the
     * year-long quissly_uid cookie (minted on the first search). PrestaShop has no cookie
     * banner of its own; a consent module's answer is read in a later phase.
     */
    public static function userId(): ?string
    {
        $customer = \Context::getContext()->customer ?? null;
        if (is_object($customer) && (int) $customer->id > 0 && $customer->isLogged()) {
            return Shopper::customerId((int) $customer->id);
        }
        if (!Shopper::consentAllows((string) \Quissly\PrestaShop\Platform\Settings::get('guest_consent_cookie'), $_COOKIE)) {
            return null;
        }
        $guest = Shopper::guestId((string) ($_COOKIE[Shopper::COOKIE] ?? ''));
        if ($guest !== null) {
            return $guest;
        }
        $uuid = Shopper::newUuid();
        if (!headers_sent()) {
            // Written by hand: setcookie() takes SameSite only from PHP 7.3 on (and 7.3 refuses
            // the old trick of putting it in the path).
            $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
            header('Set-Cookie: ' . Shopper::COOKIE . '=' . $uuid . '; Expires=' . gmdate('D, d M Y H:i:s', time() + 365 * 86400)
                . ' GMT; Max-Age=' . (365 * 86400) . '; Path=/; ' . ($secure ? 'Secure; ' : '') . 'HttpOnly; SameSite=Lax', false);
            $_COOKIE[Shopper::COOKIE] = $uuid;
        }

        return Shopper::GUEST_PREFIX . $uuid;
    }

    /** Test seam: a qsearch client factory, or null for the real Client. */
    public static function setClientFactory(?callable $factory): void
    {
        self::$clientFactory = $factory;
    }
}
