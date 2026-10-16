<?php

declare(strict_types=1);

namespace Quissly\PrestaShop\Admin;

use Quissly\PrestaShop\Catalog\Products;
use Quissly\PrestaShop\Client;
use Quissly\PrestaShop\Credentials;
use Quissly\PrestaShop\Exception\AuthException;
use Quissly\PrestaShop\Exception\QuisslyException;
use Quissly\PrestaShop\Features;
use Quissly\PrestaShop\HealthStore;
use Quissly\PrestaShop\Panel\QChatDirectory;
use Quissly\PrestaShop\Platform\Text;
use Quissly\PrestaShop\SortMap;
use Quissly\PrestaShop\StatusReport;
use Quissly\PrestaShop\Sync\DbSyncStore;
use Quissly\PrestaShop\Sync\SyncWorker;

/**
 * Quissly > Dashboard: the CS-Cart add-on's DashboardView (search status, connection, features,
 * catalog sync, the sync log - each a card), fed from PrestaShop. The cron line is the module's
 * cron address with its token (controllers/front/cron.php).
 */
final class DashboardPage
{
    /** @param array{settings: string, panel: string, test_connection: string, sync_now: string} $urls */
    public static function render(array $urls, string $cronUrl, string $securityHash): string
    {
        $store = new DbSyncStore();
        $credentials = Credentials::fromRegistry();
        $connected = $credentials->isConfigured();
        $lastCron = $store->getState('last_cron');

        return DashboardView::render([
            'connected'       => $connected,
            'environment'     => $connected ? $credentials->environment() : '',
            'status'          => StatusReport::build($credentials, $store),
            'features'        => self::notes(Features::all(), $store, $connected),
            'active_products' => Products::countActive(),
            'synced'          => $store->countSynced(),
            'queued'          => $store->countQueued(),
            'waiting'         => $store->waiting(),
            'progress'        => $store->getState(SyncWorker::STATE_PROGRESS),
            'initial_sync'    => SyncWorker::isGateOpen($store),
            'gate_blocked'    => $store->getState(SyncWorker::STATE_GATE_BLOCKED),
            'refusal'         => $store->getState(SyncWorker::STATE_REFUSAL),
            'last_run'        => $store->getState(SyncWorker::STATE_LAST_RUN),
            'last_cron_at'    => isset($lastCron['at']) ? (int) $lastCron['at'] : null,
            'cron_command'    => 'curl -s "' . $cronUrl . '"',
            'log'             => $store->recentLog(DashboardView::LOG_LINES),
            'now'             => time(),
            'urls'            => $urls,
            'security_hash'   => $securityHash,
        ]);
    }

    /**
     * Test connection: one QSearch call.
     *
     * @return array{0: string, 1: string} 'success' | 'error' | 'warning', and the message
     */
    public static function testConnection(): array
    {
        $credentials = Credentials::fromRegistry();
        if (!$credentials->isConfigured()) {
            return ['error', Text::t('quissly_search.not_configured')];
        }
        try {
            $result = (new Client($credentials))->qsearch('test', 'admin_test_connection', 1, 1, SortMap::Q_RELEVANCE, SortMap::T_DESC);
            HealthStore::recordSuccess();

            return ['success', Text::t('quissly_search.test_connection_ok', ['[count]' => $result->numTotalResults])];
        } catch (AuthException $e) {
            HealthStore::recordFailure($e->getHttpStatus(), $e->getMessage());

            return ['error', Text::t('quissly_search.test_connection_auth_fail', ['[status]' => $e->getHttpStatus()])];
        } catch (QuisslyException $e) {
            return ['warning', Text::t('quissly_search.test_connection_fail', ['[status]' => $e->getHttpStatus()])];
        }
    }

    /**
     * What a switched-on feature waits for: the storefront ones wait for search to be live; QChat
     * says whether Quissly has a chat agent for the store (as last looked up).
     */
    private static function notes(array $features, DbSyncStore $store, bool $connected): array
    {
        $gateOpen = SyncWorker::isGateOpen($store);
        foreach ($features as &$feature) {
            if (($feature['locked'] ?? '') !== '') {
                $feature['note'] = Text::t('quissly_search.feature_not_in_plan');
                continue;
            }
            if (!$feature['on'] || !$feature['built']) {
                continue;
            }
            if (in_array($feature['id'], ['enable_overlay', 'enable_voice', 'enable_image'], true) && !$gateOpen) {
                $feature['note'] = Text::t('quissly_search.feature_waits_for_search');
            }
            if ($feature['id'] === 'enable_qchat' && $connected) {
                $cache = (array) $store->getState(QChatDirectory::STATE);
                $feature['note'] = !empty($cache['agent_id'])
                    ? Text::t('quissly_search.qchat_agent_ok')
                    : Text::t('quissly_search.qchat_agent_missing', ['[error]' => (string) ($cache['error'] ?? '')]);
            }
        }
        unset($feature);

        return $features;
    }

    private function __construct()
    {
    }
}
