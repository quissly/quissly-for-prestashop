<?php
/**
 * Quissly > Billing (src/Admin/BillingFlow): the store's plans, usage and invoices, and every plan
 * action, posted back here as ?ajax=1&action=billingManage (JSON).
 */
class AdminQuisslyBillingController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
        $this->meta_title = $this->module->l('Billing');
    }

    public function init()
    {
        parent::init();
        \Quissly\PrestaShop\Admin\SetupGate::guard($this);
    }

    public function setMedia($isNewTheme = false)
    {
        parent::setMedia($isNewTheme);
        $this->addCSS($this->module->getPathUri() . 'views/css/billing.css');
        $this->addJS($this->module->getPathUri() . 'views/js/billing.js');
    }

    public function initContent()
    {
        $notice = \Quissly\PrestaShop\Admin\SetupGate::takeNotice();
        if ($notice !== '') {
            $this->confirmations[] = $notice;
        }
        parent::initContent();
        $self = self::$currentIndex . '&token=' . $this->token;
        $this->content .= \Quissly\PrestaShop\Admin\BillingFlow::page($self . '&ajax=1&action=billingManage', $self);
        $this->context->smarty->assign('content', $this->content);
    }

    public function ajaxProcessBillingManage()
    {
        $answer = \Quissly\PrestaShop\Admin\BillingFlow::manage();
        if (!empty($answer['notice'])) {
            \Quissly\PrestaShop\Admin\SetupGate::notify((string) $answer['notice']);
        }
        unset($answer['notice']);
        header('Content-Type: application/json; charset=utf-8');
        $this->ajaxDie(json_encode($answer, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
