<?php
/**
 * Quissly > Quissly Admin Panel: Quissly's own admin in a frame, signed in server-side with the
 * store's key (src/Panel - the key never reaches the browser).
 */
class AdminQuisslyPanelController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
        $this->meta_title = $this->module->l('Quissly Admin Panel');
    }

    public function init()
    {
        parent::init();
        \Quissly\PrestaShop\Admin\SetupGate::guard($this);
    }

    public function initContent()
    {
        parent::initContent();
        $post = static function (string $url, string $body) {
            $response = \Quissly\PrestaShop\Platform\Http::post($url, $body, [
                'headers' => ['Content-Type: application/json'],
                'timeout' => \Quissly\PrestaShop\Panel\PanelSession::TIMEOUT_SECONDS,
            ]);
            $status = \Quissly\PrestaShop\Platform\Http::getStatus();

            return $response === false || $status === 0 ? null : ['status' => $status, 'body' => (string) $response];
        };
        $this->content .= \Quissly\PrestaShop\Panel\PanelView::render(\Quissly\PrestaShop\Credentials::fromRegistry(), $post);
        $this->context->smarty->assign('content', $this->content);
    }
}
