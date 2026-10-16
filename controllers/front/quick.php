<?php
/**
 * Quick Recommendations (views/js/quick.js): the shopper's typed text goes to Quissly, and the
 * products it ranks come back as rows filled from the shop (src/Quick/QuickEndpoint,
 * src/Catalog/QuickRows). POST only, the storefront token required, 60 a minute per visitor;
 * off unless the feature is on and Quissly is answering searches.
 */
class QuisslyQuickModuleFrontController extends ModuleFrontController
{
    public $ajax = true;

    public function initContent()
    {
        $credentials = \Quissly\PrestaShop\Credentials::fromRegistry();
        $on = array_column(\Quissly\PrestaShop\Features::all(), 'on', 'id');
        $enabled = $_SERVER['REQUEST_METHOD'] === 'POST'
            && hash_equals((string) Tools::getToken(false), (string) Tools::getValue('security_hash'))
            && $credentials->isConfigured()
            && !empty($on['search_interception'])
            && !empty($on['enable_quick'])
            && \Quissly\PrestaShop\Sync\SyncWorker::isGateOpen(new \Quissly\PrestaShop\Sync\DbSyncStore());

        $endpoint = new \Quissly\PrestaShop\Quick\QuickEndpoint(
            new \Quissly\PrestaShop\Media\DbRateLimiter(),
            static function (string $query) use ($credentials): array {
                return (new \Quissly\PrestaShop\Client($credentials))->quick($query, \Quissly\PrestaShop\Search\SearchProvider::userId());
            },
            static function (array $ids): array {
                return \Quissly\PrestaShop\Catalog\QuickRows::forIds($ids);
            }
        );

        // REMOTE_ADDR only, as the media endpoint.
        $answer = $endpoint->handle((string) Tools::getValue('q', ''), $enabled, (string) ($_SERVER['REMOTE_ADDR'] ?? ''), time());

        http_response_code((int) $answer['status']);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $this->ajaxDie(json_encode($answer['body'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
