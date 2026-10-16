<?php
/**
 * The module's cron (Quissly > Dashboard shows the line to add to the server's crontab): an
 * update's upgrade step, the version report, the catalog sync, the shopping events, the search
 * suggestions Quissly generates (Showcase) and the update check run here. Answers JSON; refuses a request without the right token.
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
        $this->module->completeUpdate();
        $store = new \Quissly\PrestaShop\Sync\DbSyncStore();
        $store->setState('last_cron', ['at' => time()]);
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
