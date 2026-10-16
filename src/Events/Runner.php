<?php

declare(strict_types=1);

namespace Quissly\PrestaShop\Events;

use Quissly\PrestaShop\Client;
use Quissly\PrestaShop\Credentials;
use Quissly\PrestaShop\Logger;
use Quissly\PrestaShop\Sync\DbSyncStore;
use Quissly\PrestaShop\Sync\Sync;
use Quissly\PrestaShop\Update\Updater;

/**
 * What the cron runs for the events (controllers/front/cron.php; an admin page's end when the
 * cron has not run for 10 minutes): the version report when due, then the queue to Quissly (and,
 * at an admin page's end, a few seconds of the catalog queue and the update check).
 */
final class Runner
{
    /** The cron counts as running when it ran within this many seconds. */
    public const CRON_FRESH_SECONDS = 600;

    /**
     * The queued events, one signed call each.
     *
     * @return array{sent: int, dropped: int, retried: int}
     */
    public static function send(float $budgetSeconds): array
    {
        $credentials = Credentials::fromRegistry();
        $client = $credentials->isConfigured() ? new Client($credentials) : null;
        $counts = EventSender::run(
            new DbEventQueue(),
            $client !== null && Recorder::enabled(),
            static function (array $body) use ($client): ?array {
                return $client !== null ? $client->post(EventSender::PATH, $body, EventSender::TIMEOUT_SECONDS) : null;
            },
            time(),
            $budgetSeconds,
            $client !== null
        );
        if ($counts['sent'] + $counts['dropped'] + $counts['retried'] > 0) {
            Logger::notice(sprintf('events sent=%d dropped=%d retried=%d', $counts['sent'], $counts['dropped'], $counts['retried']));
        }

        return $counts;
    }

    /**
     * The version report (quissly_version_update) when due: at connect, after each update and
     * once a day - only from a connected store (an unconnected one keeps tracking, so its first
     * report says where it came from).
     */
    public static function versionReport(DbSyncStore $store, string $version): void
    {
        try {
            if ($version === '') {
                return;
            }
            $stored = (array) ($store->getState(VersionReport::STATE) ?? []);
            $result = VersionReport::track($stored, $version, time());
            $state = $result['state'];
            if ($result['report'] && Recorder::store(VersionReport::EVENT, VersionReport::metadata($state))) {
                $state['reported_at'] = time();
            }
            if ($state !== $stored) {
                $store->setState(VersionReport::STATE, $state);
            }
        } catch (\Throwable $e) {
            return;
        }
    }

    /**
     * At the end of an admin page: the cron's work, a few seconds of it, when the cron has not
     * run - after the page has gone to the browser where PHP-FPM allows it.
     */
    public static function adminFallback(string $version): void
    {
        register_shutdown_function(static function () use ($version) {
            $store = new DbSyncStore();
            self::versionReport($store, $version);
            if ((int) ($store->getState('last_cron')['at'] ?? 0) >= time() - self::CRON_FRESH_SECONDS) {
                return;
            }
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            Sync::run(Sync::FALLBACK_BUDGET_SECONDS, 'admin_page');
            self::send(5.0);
            \Quissly\PrestaShop\Showcase\Showcase::tick($store);
            Updater::runIfDue($store, time());
        });
    }

    private function __construct()
    {
    }
}
