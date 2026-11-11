<?php
/**
 * QChat asking about an order (src/Orders/): Quissly's server POSTs the order reference or id and the
 * email or phone the shopper gave, signed with Quissly's order lookup key. 404 while "Order status in
 * QChat" is off or the shop is not connected; 401 (no body) unless the signature verifies for this
 * shop's project, the request is fresh and its nonce unused; 429 past 60 a minute for the whole shop.
 * Then the order only when the contact is its own, else `not_found`.
 */
class QuisslyOrderlookupModuleFrontController extends ModuleFrontController
{
    public $ajax = true;

    public function initContent()
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $credentials = \Quissly\PrestaShop\Credentials::fromRegistry();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !\Quissly\PrestaShop\Platform\Settings::on('enable_order_status') || !$credentials->isConfigured()) {
            http_response_code(404);
            $this->ajaxDie('');
        }
        $ask = \Quissly\PrestaShop\Orders\LookupRules::verify(
            (string) file_get_contents('php://input', false, null, 0, \Quissly\PrestaShop\Orders\LookupRules::MAX_BODY + 1),
            (string) ($_SERVER['HTTP_X_QUISSLY_SIGNATURE'] ?? ''),
            (string) ($_SERVER['HTTP_X_QUISSLY_KEY_ID'] ?? ''),
            $credentials->projectId(),
            time()
        );
        $limiter = new \Quissly\PrestaShop\Media\DbRateLimiter();
        // A nonce is a bucket that allows one use for 10 minutes.
        if ($ask === null || !$limiter->allow('order_nonce|' . $ask['nonce'], 1, 600, time())) {
            http_response_code(401);
            $this->ajaxDie('');
        }
        if (!$limiter->allow('order_lookup', 60, 60, time())) {
            http_response_code(429);
            $this->ajaxDie('');
        }
        $this->ajaxDie((string) json_encode(\Quissly\PrestaShop\Orders\OrderLookup::answer($ask['order_number'], $ask['contact']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
