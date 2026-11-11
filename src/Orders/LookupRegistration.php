<?php

declare(strict_types=1);

namespace Quissly\PrestaShop\Orders;

use Quissly\PrestaShop\Config;
use Quissly\PrestaShop\Credentials;
use Quissly\PrestaShop\Platform\Http;
use Quissly\PrestaShop\Platform\Settings;
use Quissly\PrestaShop\Sync\DbSyncStore;

/**
 * Tells Quissly where this shop answers order lookups (controllers/front/orderlookup.php) - or that it
 * does not, while "Order status in QChat" is off: `PUT <console>/api/v1/store/order-lookup` with the
 * store API key, from the cron, once a day and whenever the address or the switch changed. A failure
 * goes to the sync log once and is tried again the next day.
 */
final class LookupRegistration
{
    public const PATH = '/api/v1/store/order-lookup';
    public const STATE = 'order_lookup';
    public const EVERY = 86400;

    public static function maybe(DbSyncStore $store, int $now): void
    {
        try {
            $credentials = Credentials::fromRegistry();
            if (!$credentials->isConfigured() || $credentials->projectId() === '') {
                return;
            }
            $url = Settings::on('enable_order_status')
                ? (string) \Context::getContext()->link->getModuleLink('quissly', 'orderlookup', [], true)
                : null;
            $state = (array) ($store->getState(self::STATE) ?? []);
            $same = array_key_exists('url', $state) && $state['url'] === $url;
            if ($same && $now - (int) ($state['at'] ?? 0) < self::EVERY) {
                return;
            }
            // Claimed before the network: another cron meanwhile does not ask again.
            $store->setState(self::STATE, ['url' => $url, 'at' => $now, 'ok' => $state['ok'] ?? false]);
            Http::put(Config::CONSOLE_URL . self::PATH, (string) json_encode(['project_id' => $credentials->projectId(), 'url' => $url]), [
                'headers' => ['Content-Type: application/json', 'Accept: application/json', 'X-Store-Api-Key: ' . $credentials->bearerToken()],
                'timeout' => 10,
            ]);
            $status = Http::getStatus();
            $ok = $status >= 200 && $status < 300;
            if (!$ok && ($state['ok'] ?? true)) {
                $store->log(sprintf('Quissly could not be told where to check order status (HTTP %d); tried again tomorrow.', $status), $now);
            }
            $store->setState(self::STATE, ['url' => $url, 'at' => $now, 'ok' => $ok]);
        } catch (\Throwable $e) {
            return;
        }
    }

    private function __construct()
    {
    }
}
