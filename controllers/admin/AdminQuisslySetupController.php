<?php
/**
 * Quissly Setup (src/Admin/SetupFlow): the three-step onboarding page, and its four steps posted
 * back here as ?ajax=1&action=setupConnect|setupPlan|setupStatus|setupFinish (JSON), and the email
 * code step 1 asks Quissly for as ?ajax=1&action=setupSendCode.
 */
class AdminQuisslySetupController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
        $this->meta_title = $this->module->l('Quissly Setup');
    }

    public function init()
    {
        parent::init();
        if (empty($this->ajax) && \Quissly\PrestaShop\Admin\SetupFlow::onboarding()->isComplete()) {
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminQuisslyDashboard'));
        }
    }

    public function setMedia($isNewTheme = false)
    {
        parent::setMedia($isNewTheme);
        $this->addCSS($this->module->getPathUri() . 'views/css/setup.css');
        $this->addJS($this->module->getPathUri() . 'views/js/setup.js');
    }

    public function initContent()
    {
        parent::initContent();
        $self = self::$currentIndex . '&token=' . $this->token;
        $this->content .= \Quissly\PrestaShop\Admin\SetupFlow::page($self, [
            'connect'   => $self . '&ajax=1&action=setupConnect',
            'send_code' => $self . '&ajax=1&action=setupSendCode',
            'plan'    => $self . '&ajax=1&action=setupPlan',
            'status'  => $self . '&ajax=1&action=setupStatus',
            'finish'  => $self . '&ajax=1&action=setupFinish',
        ], $this->module->getPathUri() . 'views/img/quissly-wordmark.svg');
        $this->context->smarty->assign('content', $this->content);
    }

    public function ajaxProcessSetupConnect()
    {
        $this->answer(\Quissly\PrestaShop\Admin\SetupFlow::connect());
    }

    public function ajaxProcessSetupSendCode()
    {
        $this->answer(\Quissly\PrestaShop\Admin\SetupFlow::sendCode());
    }

    public function ajaxProcessSetupPlan()
    {
        $this->answer(\Quissly\PrestaShop\Admin\SetupFlow::plan());
    }

    public function ajaxProcessSetupStatus()
    {
        $this->answer(\Quissly\PrestaShop\Admin\SetupFlow::status());
    }

    public function ajaxProcessSetupFinish()
    {
        $answer = \Quissly\PrestaShop\Admin\SetupFlow::finish();
        if (!empty($answer['ok'])) {
            \Quissly\PrestaShop\Admin\SetupGate::notify((string) ($answer['notice'] ?? ''));
            $answer['redirect'] = $this->context->link->getAdminLink('AdminQuisslyDashboard');
        }
        unset($answer['notice']);
        $this->answer($answer);
    }

    private function answer(array $answer)
    {
        header('Content-Type: application/json; charset=utf-8');
        $this->ajaxDie(json_encode($answer, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
