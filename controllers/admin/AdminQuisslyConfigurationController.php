<?php
/**
 * Quissly > Configuration (src/Admin/ConfigurationPage): the settings, the suggestion lists, Most
 * loved, Catalog data and Updates, saved with one button; the Most loved picker's search answers
 * here too (?ajax=1&action=mostLovedSearch).
 */
class AdminQuisslyConfigurationController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
        $this->meta_title = $this->module->l('Configuration');
    }

    public function init()
    {
        parent::init();
        \Quissly\PrestaShop\Admin\SetupGate::guard($this);
    }

    public function setMedia($isNewTheme = false)
    {
        parent::setMedia($isNewTheme);
        $this->addCSS($this->module->getPathUri() . 'views/css/config.css');
        $this->addCSS($this->module->getPathUri() . 'views/css/configuration.css');
        $this->addJS($this->module->getPathUri() . 'views/js/config.js');
        $this->addJS($this->module->getPathUri() . 'views/js/most-loved-picker.js');
        $this->addJS($this->module->getPathUri() . 'views/js/configuration.js');
    }

    public function postProcess()
    {
        if (Tools::isSubmit('submitQuisslyConfiguration')) {
            $errors = \Quissly\PrestaShop\Admin\ConfigurationPage::save();
            if ($errors === []) {
                // Post/redirect/get: a reload does not post the form again.
                Tools::redirectAdmin(self::$currentIndex . '&token=' . $this->token . '&conf=4');
            }
            $this->errors = array_merge($this->errors, $errors);
        }

        return parent::postProcess();
    }

    public function initContent()
    {
        $notice = \Quissly\PrestaShop\Admin\SetupGate::takeNotice();
        if ($notice !== '') {
            $this->confirmations[] = $notice;
        }
        parent::initContent();
        $self = self::$currentIndex . '&token=' . $this->token;
        $this->content .= \Quissly\PrestaShop\Admin\ConfigurationPage::render($self, $self . '&ajax=1&action=mostLovedSearch');
        $this->context->smarty->assign('content', $this->content);
    }

    /** The Most loved picker's search: {items, more}, a page of 20. */
    public function ajaxProcessMostLovedSearch()
    {
        header('Content-Type: application/json; charset=utf-8');
        $this->ajaxDie(json_encode(
            \Quissly\PrestaShop\Catalog\ProductLookup::search((string) Tools::getValue('q', ''), (int) Tools::getValue('page', 1)),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));
    }
}
