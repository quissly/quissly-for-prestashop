<?php
/**
 * The chat's cart, for QChat's "Add to Cart" (views/js/cart.js):
 *   op=resolve (GET ids=uuid,uuid) - Quissly's product ids as the shop's ({uuid: id_product}, or
 *     "<id_product>-<id_product_attribute>" for the combination of a product the chat named): uuid5
 *     over the shop's products and their combinations in the account's namespace, then the pairs the
 *     sync recorded;
 *   op=update (POST product_id, delta, security_hash) - the shopper's cart gains or loses `delta`
 *     of the product: product_id "7" = its default combination, "1-2" = that combination (only
 *     one of that product's own) - through PrestaShop's own cart; {ok, quantity}.
 */
class QuisslyCartModuleFrontController extends ModuleFrontController
{
    public $ajax = true;

    public function initContent()
    {
        header('Content-Type: application/json; charset=utf-8');
        if (Tools::getValue('op') === 'resolve') {
            header('Cache-Control: private, max-age=300');
            $this->ajaxDie(json_encode((object) $this->resolve((string) Tools::getValue('ids', ''))));
        }
        header('Cache-Control: no-store');
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals((string) Tools::getToken(false), (string) Tools::getValue('security_hash'))) {
            http_response_code(403);
            $this->ajaxDie(json_encode(['ok' => false]));
        }
        $this->ajaxDie(json_encode($this->update((string) Tools::getValue('product_id'), (int) Tools::getValue('delta'))));
    }

    /** @return array<string, int|string> */
    private function resolve(string $raw): array
    {
        $ids = array_slice(array_values(array_unique(array_filter(
            array_map(static function (string $id): string {
                return strtolower(trim($id));
            }, explode(',', $raw)),
            static function (string $id): bool {
                return (bool) preg_match(\Quissly\PrestaShop\Cart\ChatIds::UUID, $id);
            }
        ))), 0, 50);
        if ($ids === []) {
            return [];
        }
        $store = new \Quissly\PrestaShop\Sync\DbSyncStore();
        $namespace = \Quissly\PrestaShop\Panel\QChatDirectory::cachedNamespace($store);
        $found = $namespace !== '' ? \Quissly\PrestaShop\Cart\ChatIds::resolve($namespace, $ids, \Quissly\PrestaShop\Catalog\Products::activeIds(), \Quissly\PrestaShop\Catalog\Products::activeCombinationIds()) : [];

        return $found + $store->productIdsFor(array_values(array_diff($ids, array_keys($found))));
    }

    private function update(string $target, int $delta): array
    {
        $parsed = \Quissly\PrestaShop\Cart\ChatIds::cartTarget($target);
        if ($parsed === null || $delta === 0) {
            return ['ok' => false];
        }
        list($productId, $attribute) = $parsed;
        if ($attribute > 0 && !Db::getInstance()->getValue('SELECT 1 FROM ' . _DB_PREFIX_ . 'product_attribute WHERE id_product = ' . (int) $productId . ' AND id_product_attribute = ' . (int) $attribute)) {
            return ['ok' => false]; // not one of this product's combinations
        }
        // A product that does not exist (or is off) is refused here: PrestaShop's cart answered it
        // with a fatal error and a 500 page instead of JSON.
        $product = new Product($productId, false, (int) $this->context->language->id);
        if (!Validate::isLoadedObject($product) || !$product->active) {
            return ['ok' => false];
        }
        $context = $this->context;
        if (!Validate::isLoadedObject($context->cart)) {
            // A shopper with no cart yet: one, kept on their cookie as PrestaShop does.
            $cart = new Cart();
            $cart->id_lang = (int) $context->language->id;
            $cart->id_currency = (int) $context->currency->id;
            $cart->id_shop = (int) $context->shop->id;
            $cart->id_customer = (int) $context->customer->id;
            $cart->id_guest = (int) $context->cookie->id_guest;
            $cart->add();
            $context->cart = $cart;
            $context->cookie->id_cart = (int) $cart->id;
            $context->cookie->write();
        }
        if ($attribute === 0) {
            $attribute = (int) Product::getDefaultAttribute($productId);
        }
        try {
            $result = $context->cart->updateQty(abs($delta), $productId, $attribute, false, $delta > 0 ? 'up' : 'down');
        } catch (\Throwable $e) {
            \Quissly\PrestaShop\Logger::error('Chat cart: PrestaShop refused the change', ['exception' => get_class($e)]);

            return ['ok' => false];
        }
        $quantity = (int) ($context->cart->getProductQuantity($productId, $attribute)['quantity'] ?? 0);

        return ['ok' => $result === true || $result > 0, 'id_product' => $productId, 'id_product_attribute' => $attribute, 'quantity' => $quantity];
    }
}
