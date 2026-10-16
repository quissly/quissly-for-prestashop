<?php
/**
 * A storefront page's shopping event (views/js/events.js): view_PDP or search, as JSON, with the
 * storefront token. 404 while "Shopping activity" is off (nobody learns the endpoint exists),
 * 429 past 60 a minute per visitor, 413 over 1 KB, 400 for anything else; 204 when queued.
 */
class QuisslyEventModuleFrontController extends ModuleFrontController
{
    public $ajax = true;

    public function initContent()
    {
        $status = 204;
        $raw = (string) file_get_contents('php://input');
        $event = json_decode($raw, true);
        $type = is_array($event) ? (string) ($event['type'] ?? '') : '';
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !\Quissly\PrestaShop\Events\Recorder::enabled()
            || !hash_equals((string) Tools::getToken(false), (string) Tools::getValue('security_hash'))) {
            $status = 404;
        } elseif (!(new \Quissly\PrestaShop\Media\DbRateLimiter())->allow('events|' . (string) ($_SERVER['REMOTE_ADDR'] ?? ''), 60, 60, time())) {
            $status = 429;
        } elseif (strlen($raw) > 1024) {
            $status = 413;
        } elseif ($type === \Quissly\PrestaShop\Events\EventBody::VIEW) {
            $id = is_scalar($event['product_id'] ?? null) && preg_match('/^[1-9]\d{0,9}$/', (string) $event['product_id']) ? (int) $event['product_id'] : 0;
            $active = $id > 0 && (int) \Quissly\PrestaShop\Platform\Db::field('SELECT active FROM ?:product_shop WHERE id_product = ?i AND id_shop = ?i', $id, (int) $this->context->shop->id) === 1;
            if ($active) {
                \Quissly\PrestaShop\Events\Recorder::shopper(\Quissly\PrestaShop\Events\EventBody::VIEW, \Quissly\PrestaShop\Events\EventBody::view($id));
            } else {
                $status = 400;
            }
        } elseif ($type === \Quissly\PrestaShop\Events\EventBody::SEARCH) {
            $metadata = \Quissly\PrestaShop\Events\EventBody::search(
                is_string($event['query'] ?? null) ? $event['query'] : '',
                is_string($event['modality'] ?? null) ? $event['modality'] : ''
            );
            if ($metadata !== null) {
                \Quissly\PrestaShop\Events\Recorder::shopper(\Quissly\PrestaShop\Events\EventBody::SEARCH, $metadata);
            } else {
                $status = 400;
            }
        } else {
            $status = 400;
        }
        http_response_code($status);
        header('Cache-Control: no-store');
        $this->ajaxDie('');
    }
}
