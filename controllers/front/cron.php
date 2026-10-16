<?php
/**
 * The module's cron (Quissly > Dashboard shows the line to add to the server's crontab): an
 * update's upgrade step, the version report, the catalog sync, the shopping events, the search
 * suggestions Quissly generates (Showcase) and the update check run here. Answers JSON; refuses a request without the right token.
 * Also called by storefront visits when the server's cron is not set up (`visit=1`, src/Platform/VisitTick).
 */
class QuisslyCronModuleFrontController extends ModuleFrontController
{
    public $ajax = true;

    public function initContent()
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!\Quissly\PrestaShop\Platform\Cron::valid((string) Tools::getValue('token'))) {
            header('HTTP/1.1 403 Forbidden');
            $this->ajaxDie(json_encode(['ok' => false]));
        }
        // A storefront visit's background call (src/Platform/VisitTick) does not wait for this.
        ignore_user_abort(true);
        $this->module->completeUpdate();
        $store = new \Quissly\PrestaShop\Sync\DbSyncStore();
        if (Tools::getValue('visit') === '1') {
            // Not the server's cron: it does not count as one, and stands aside while one runs.
            if ((int) ($store->getState('last_cron')['at'] ?? 0) >= time() - 600) {
                $this->ajaxDie(json_encode(['ok' => true, 'skipped' => 'cron_running']));
            }
            $store->setState('last_visit_run', ['at' => time()]);
        } else {
            $store->setState('last_cron', ['at' => time()]);
        }
        @set_time_limit(\Quissly\PrestaShop\Sync\Sync::CRON_BUDGET_SECONDS + 120);
        // The version report when due, the catalog queue, then the shopping events (src/Events/Runner).
        \Quissly\PrestaShop\Events\Runner::versionReport($store, (string) $this->module->version);
        $sync = \Quissly\PrestaShop\Sync\Sync::run(\Quissly\PrestaShop\Sync\Sync::CRON_BUDGET_SECONDS, 'cron');
        $events = \Quissly\PrestaShop\Events\Runner::send(40.0);
        // After the first sync: Quissly's Search Examples and generated Search Suggestions, when due.
        $showcase = \Quissly\PrestaShop\Showcase\Showcase::tick($store);
        // What the store's plan includes (Setup\PlanGate), asked again every 10 minutes.
        if (\Quissly\PrestaShop\Setup\PlanGate::isStale($store, time()) && \Quissly\PrestaShop\Credentials::fromRegistry()->isConfigured()) {
            \Quissly\PrestaShop\Setup\PlanGate::remember($store, \Quissly\PrestaShop\Admin\SetupFlow::billing()->subscriptions(), time());
        }
        // Last: an update replaces the module's files, and the rest of this request runs the old code.
        \Quissly\PrestaShop\Update\Updater::runIfDue($store, time());
        $this->ajaxDie(json_encode(['ok' => true, 'sync' => $sync, 'events' => $events, 'showcase' => $showcase]));
    }
}
