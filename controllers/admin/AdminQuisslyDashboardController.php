<?php
/**
 * Quissly > Dashboard (src/Admin/DashboardPage): search status, connection, features, catalog
 * sync and the sync log; Test connection and the sync buttons post back here.
 */
class AdminQuisslyDashboardController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
        $this->meta_title = $this->module->l('Dashboard');
    }

    public function init()
    {
        parent::init();
        \Quissly\PrestaShop\Admin\SetupGate::guard($this);
    }

    public function setMedia($isNewTheme = false)
    {
        parent::setMedia($isNewTheme);
        $this->addCSS($this->module->getPathUri() . 'views/css/dashboard.css');
    }

    public function postProcess()
    {
        $do = (string) Tools::getValue('quissly_do');
        if ($do === 'test') {
            list($kind, $message) = \Quissly\PrestaShop\Admin\DashboardPage::testConnection();
            if ($kind === 'success') {
                $this->confirmations[] = $message;
            } elseif ($kind === 'error') {
                $this->errors[] = $message;
            } else {
                $this->warnings[] = $message;
            }
        } elseif ($do === 'sync' && Tools::isSubmit('security_hash')) {
            $this->syncNow(Tools::getValue('full') === 'Y');
        }

        return parent::postProcess();
    }

    /** "Sync now" (the queue) or "Start full sync" / "Sync all products again" (full): sends for up to 25 seconds. */
    private function syncNow(bool $full)
    {
        if (!\Quissly\PrestaShop\Credentials::fromRegistry()->isConfigured()) {
            $this->errors[] = \Quissly\PrestaShop\Platform\Text::t('quissly_search.su_connect_first');

            return;
        }
        @set_time_limit(\Quissly\PrestaShop\Sync\Sync::ADMIN_BUDGET_SECONDS + 60);
        $worker = \Quissly\PrestaShop\Sync\Sync::worker();
        if ($full) {
            $worker->startFullSync((new \Quissly\PrestaShop\Sync\ProductSource())->activeIds());
        }
        $summary = $worker->run(\Quissly\PrestaShop\Sync\Sync::ADMIN_BUDGET_SECONDS, 'admin');
        $message = \Quissly\PrestaShop\Platform\Text::t('quissly_search.run_summary', [
            '[confirmed]' => $summary['confirmed'], '[waiting]' => $summary['waiting'], '[failed]' => $summary['failed'], '[queued]' => $summary['queued'],
        ]);
        if ($summary['stopped'] !== '') {
            $this->warnings[] = $message . ' ' . $summary['stopped'] . '.';
        } else {
            $this->confirmations[] = $message;
        }
    }

    public function initContent()
    {
        $notice = \Quissly\PrestaShop\Admin\SetupGate::takeNotice();
        if ($notice !== '') {
            $this->confirmations[] = $notice;
        }
        parent::initContent();
        $self = self::$currentIndex . '&token=' . $this->token;
        $this->content .= \Quissly\PrestaShop\Admin\DashboardPage::render([
            'settings'        => $this->context->link->getAdminLink('AdminQuisslyConfiguration'),
            'panel'           => $this->context->link->getAdminLink('AdminQuisslyPanel'),
            'test_connection' => $self . '&quissly_do=test',
            'sync_now'        => $self . '&quissly_do=sync',
        ], \Quissly\PrestaShop\Platform\Cron::url(), $this->token);
        $this->context->smarty->assign('content', $this->content);
    }
}
