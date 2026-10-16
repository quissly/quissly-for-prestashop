<?php
/**
 * The chat's cart, for QChat's "Add to Cart" (views/js/cart.js):
 *   op=resolve (GET ids=uuid,uuid) - Quissly's product ids as the shop's ({uuid: id_product}):
 *     uuid5 over the shop's products in the account's namespace, then the pairs the sync recorded;
 *   op=update (POST product_id, delta, security_hash) - the shopper's cart gains or loses `delta`
 *     of the product (its default combination), through PrestaShop's own cart; {ok, quantity}.
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
        $this->ajaxDie(json_encode($this->update((int) Tools::getValue('product_id'), (int) Tools::getValue('delta'))));
    }

    /** @return array<string, int> */
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
        $found = $namespace !== '' ? \Quissly\PrestaShop\Cart\ChatIds::resolve($namespace, $ids, \Quissly\PrestaShop\Catalog\Products::activeIds()) : [];

        return $found + $store->productIdsFor(array_values(array_diff($ids, array_keys($found))));
    }

    private function update(int $productId, int $delta): array
    {
        if ($productId <= 0 || $delta === 0) {
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
        $attribute = (int) Product::getDefaultAttribute($productId);
        $result = $context->cart->updateQty(abs($delta), $productId, $attribute, false, $delta > 0 ? 'up' : 'down');
        $quantity = (int) ($context->cart->getProductQuantity($productId, $attribute)['quantity'] ?? 0);

        return ['ok' => $result === true || $result > 0, 'id_product' => $productId, 'id_product_attribute' => $attribute, 'quantity' => $quantity];
    }
}
