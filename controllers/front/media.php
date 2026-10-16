<?php
/**
 * Voice and image search (views/js/media.js): the shopper's recording or photo goes to Quissly,
 * the products it found are kept behind a token (src/Media/MediaEndpoint), and the results page
 * shows them (src/Search/SearchProvider). POST only, the storefront token required, 10 a minute
 * per visitor; off unless the feature is on and Quissly is answering searches.
 */
class QuisslyMediaModuleFrontController extends ModuleFrontController
{
    public $ajax = true;

    public function initContent()
    {
        $kind = Tools::getValue('kind') === 'image' ? 'image' : 'voice';
        $credentials = \Quissly\PrestaShop\Credentials::fromRegistry();
        $on = array_column(\Quissly\PrestaShop\Features::all(), 'on', 'id');
        $enabled = $_SERVER['REQUEST_METHOD'] === 'POST'
            && hash_equals((string) Tools::getToken(false), (string) Tools::getValue('security_hash'))
            && $credentials->isConfigured()
            && !empty($on['search_interception'])
            && !empty($on[$kind === 'voice' ? 'enable_voice' : 'enable_image'])
            && \Quissly\PrestaShop\Sync\SyncWorker::isGateOpen(new \Quissly\PrestaShop\Sync\DbSyncStore());

        $endpoint = new \Quissly\PrestaShop\Media\MediaEndpoint(
            new \Quissly\PrestaShop\Media\DbTokenStore(),
            new \Quissly\PrestaShop\Media\DbRateLimiter(),
            static function (string $kind, string $base64) use ($credentials): array {
                $client = new \Quissly\PrestaShop\Client($credentials);
                $userId = \Quissly\PrestaShop\Search\SearchProvider::userId();
                $answer = $kind === 'voice'
                    ? $client->media(\Quissly\PrestaShop\Config::QSEARCH_PATH, ['audio' => $base64], $userId, \Quissly\PrestaShop\Media\MediaEndpoint::PAGE_SIZE)
                    : $client->media(\Quissly\PrestaShop\Config::QIMAGE_PATH, ['image' => $base64], $userId, \Quissly\PrestaShop\Media\MediaEndpoint::PAGE_SIZE);

                return ['ids' => $answer['result']->ids, 'transcription' => $answer['transcription']];
            }
        );

        // REMOTE_ADDR only: X-Forwarded-For is client-spoofable (a fresh bucket per header value).
        $answer = $endpoint->handle($kind, (string) Tools::getValue($kind === 'voice' ? 'audio' : 'image', ''), $enabled, (string) ($_SERVER['REMOTE_ADDR'] ?? ''), time());

        http_response_code((int) $answer['status']);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $this->ajaxDie(json_encode($answer['body'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
