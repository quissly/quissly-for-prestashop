<?php
/**
 * Quissly for PrestaShop - AI product search, chat and discovery from Quissly.
 *
 * Quissly is the search brain, not the skin: the storefront search asks Quissly which products
 * match, in which order, and the shop's own theme renders them. It does what the Quissly
 * plugins for WooCommerce, Magento and CS-Cart do.
 *
 * @author    Quissly
 * @copyright Quissly
 * @license   GPL-2.0-or-later
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/sql/Schema.php';

// The module's classes: Quissly\PrestaShop\* in src/ (PSR-4, no Composer to ship).
spl_autoload_register(static function ($class) {
    $prefix = 'Quissly\\PrestaShop\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

class Quissly extends Module
{
    /**
     * The menu, Improve > Quissly: [class (controller), name]. Made by install() itself: declared in
     * $tabs, PrestaShop copies a parent that gets children into an extra "Quissly" entry.
     */
    const MENU = [
        ['AdminQuisslyConfiguration', 'Configuration', true],
        ['AdminQuisslyDashboard', 'Dashboard', true],
        ['AdminQuisslyPanel', 'Quissly Admin Panel', true],
        ['AdminQuisslyBilling', 'Billing', true],
        // Quissly Setup: no menu entry of its own - every entry opens it until it is finished.
        ['AdminQuisslySetup', 'Quissly Setup', false],
    ];

    /** The hooks this version uses (install registers them; completeUpdate() adds new ones). */
    const HOOKS = [
        'productSearchProvider',
        'displayBackOfficeHeader',
        'actionOutputHTMLBefore',
        'displayHeader',
        'displayAfterBodyOpeningTag',
        'actionCartUpdateQuantityBefore',
        'actionWishlistAddProduct',
        'actionOrderStatusPostUpdate',
        // The catalog sync (src/Sync/Sync): what changes a product Quissly holds.
        'actionProductAdd',
        'actionProductUpdate',
        'actionProductDelete',
        'actionObjectCombinationAddAfter',
        'actionObjectCombinationUpdateAfter',
        'actionObjectCombinationDeleteAfter',
        'actionObjectSpecificPriceAddAfter',
        'actionObjectSpecificPriceUpdateAfter',
        'actionObjectSpecificPriceDeleteAfter',
        'actionObjectImageAddAfter',
        'actionObjectImageUpdateAfter',
        'actionObjectImageDeleteAfter',
        'actionUpdateQuantity',
    ];

    /** The version whose upgrade step last ran (completeUpdate()). */
    const RUNNING_VERSION = 'QUISSLY_RUNNING_VERSION';

    public function __construct()
    {
        $this->name = 'quissly';
        $this->tab = 'search_filter';
        $this->version = '1.0.4';
        $this->author = 'Quissly';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '1.7.7.0', 'max' => _PS_VERSION_];


        parent::__construct();

        $this->displayName = $this->l('Quissly AI Search');
        $this->description = $this->l('AI product search, voice and image search and a shopping assistant from Quissly. Your theme renders the results. Works with PrestaShop 1.7.7 and PHP 7.1 or newer.');
        $this->confirmUninstall = $this->l('Remove Quissly? Its settings and sync data are deleted.');
    }

    public function install()
    {
        return parent::install()
            && $this->installMenu()
            && QuisslySchema::install()
            && \Quissly\PrestaShop\Platform\Settings::installDefaults()
            && $this->registerHook(self::HOOKS)
            // The search page takes the first provider a module gives: Quissly's comes first.
            && $this->updatePosition((int) Hook::getIdByName('productSearchProvider'), false, 1)
            && Configuration::updateValue(self::RUNNING_VERSION, $this->version);
    }

    public function uninstall()
    {
        \Quissly\PrestaShop\Platform\Settings::uninstall();
        Configuration::deleteByName(self::RUNNING_VERSION);
        \Quissly\PrestaShop\Update\Updater::removeWorkFolder();

        return QuisslySchema::uninstall() && parent::uninstall();
    }

    /** Improve > Quissly and its pages; an entry PrestaShop already made for a controller is reused. */
    public function installMenu(): bool
    {
        $parent = $this->menuTab('AdminQuissly', 'Quissly', (int) Tab::getIdFromClassName('IMPROVE'));
        if ($parent === 0) {
            return false;
        }
        foreach (self::MENU as list($class, $name, $visible)) {
            if ($this->menuTab($class, $name, $parent, $visible) === 0) {
                return false;
            }
        }

        return true;
    }

    private function menuTab(string $class, string $name, int $parent, bool $visible = true): int
    {
        $id = (int) Tab::getIdFromClassName($class);
        $tab = $id > 0 ? new Tab($id) : new Tab();
        $tab->class_name = $class;
        $tab->module = $this->name;
        $tab->id_parent = $parent;
        $tab->active = $visible ? 1 : 0;
        if ($class === 'AdminQuissly') {
            $tab->icon = 'search';
        }
        foreach (Language::getLanguages(false) as $language) {
            $tab->name[(int) $language['id_lang']] = $name;
        }

        return $tab->save() ? (int) $tab->id : 0;
    }

    /**
     * The upgrade step of an update whose files are in place - put there by the automatic
     * update (src/Update/Updater) or by hand: what Module Manager's "Upgrade" button does (the
     * upgrade/ scripts, the recorded version), then this version's hooks, tables, settings
     * (values kept) and menu. Runs from the cron, an admin page or a storefront page, so with this
     * version's code loaded; once per version (the recorded version is claimed first). Free when
     * there is nothing to do: one setting read, which PrestaShop has in memory.
     */
    public function completeUpdate(): void
    {
        if ((string) Configuration::get(self::RUNNING_VERSION) === $this->version) {
            return;
        }
        try {
            $db = Db::getInstance();
            $from = (string) $db->getValue('SELECT version FROM `' . _DB_PREFIX_ . 'module` WHERE name = \'' . pSQL($this->name) . '\'', false);
            if ($from === '') {
                return; // not installed
            }
            if (!Tools::version_compare($this->version, $from, '>')) {
                Configuration::updateValue(self::RUNNING_VERSION, $this->version);

                return;
            }
            $claimed = $db->execute('UPDATE `' . _DB_PREFIX_ . 'module` SET version = \'' . pSQL($this->version) . '\' WHERE name = \'' . pSQL($this->name) . '\' AND version = \'' . pSQL($from) . '\'')
                && $db->Affected_Rows() === 1;
            if (!$claimed) {
                return; // another request is doing it
            }
            // What PrestaShop's module list sets, and initUpgradeModule needs (or runs no script).
            $this->installed = true;
            $this->database_version = $from;
            $ok = true;
            if (Module::initUpgradeModule($this)) {
                $upgrade = $this->runUpgradeModule();
                $ok = !empty($upgrade['success']) && empty($upgrade['number_upgrade_left']);
                // A failed script: PrestaShop switched the module off; the version is the last one reached.
                Module::upgradeModuleVersion($this->name, $ok ? $this->version : (string) ($upgrade['upgraded_to'] ?: $from));
            }
            if ($ok) {
                $ok = $this->registerHook(self::HOOKS)
                    && $this->installMenu()
                    && QuisslySchema::install()
                    && \Quissly\PrestaShop\Platform\Settings::installDefaults();
            }
            Configuration::updateValue(self::RUNNING_VERSION, $this->version);
            \Quissly\PrestaShop\Update\Updater::completed(new \Quissly\PrestaShop\Sync\DbSyncStore(), $from, $this->version, $ok, time());
        } catch (\Throwable $e) {
            \Quissly\PrestaShop\Logger::error('Quissly upgrade step failed', ['exception' => get_class($e)]);
        }
    }

    /** The Modules page's "Configure" button opens Quissly's Configuration. */
    public function getContent()
    {
        Tools::redirectAdmin($this->context->link->getAdminLink('AdminQuisslyConfiguration'));
    }

    /**
     * The menu's Quissly mark (the speech-bubble q, as the other plugins' menus have it); an
     * update's upgrade step; at the page's end, the cron's work when the cron is not set up
     * (src/Events/Runner).
     */
    public function hookDisplayBackOfficeHeader()
    {
        $this->completeUpdate();
        $this->context->controller->addCSS($this->_path . 'views/css/menu.css');
        \Quissly\PrestaShop\Events\Runner::adminFallback((string) $this->version);
    }

    /** Catalog sync: a product added or saved (also its status toggled, a bulk action, an import). */
    public function hookActionProductAdd($params)
    {
        $this->catalogChanged((int) ($params['id_product'] ?? 0));
    }

    public function hookActionProductUpdate($params)
    {
        $this->catalogChanged((int) ($params['id_product'] ?? 0));
    }

    public function hookActionProductDelete($params)
    {
        try {
            \Quissly\PrestaShop\Sync\Sync::productDeleted((int) ($params['id_product'] ?? 0));
        } catch (\Throwable $e) {
            \Quissly\PrestaShop\Logger::error('Quissly catalog hook failed', ['exception' => get_class($e)]);
        }
    }

    /** Catalog sync: a combination, specific price (sale) or image of a product changed. */
    public function hookActionObjectCombinationAddAfter($params)
    {
        $this->catalogChanged(self::productOf($params));
    }

    public function hookActionObjectCombinationUpdateAfter($params)
    {
        $this->catalogChanged(self::productOf($params));
    }

    public function hookActionObjectCombinationDeleteAfter($params)
    {
        $this->catalogChanged(self::productOf($params));
    }

    public function hookActionObjectSpecificPriceAddAfter($params)
    {
        $this->catalogChanged(self::productOf($params));
    }

    public function hookActionObjectSpecificPriceUpdateAfter($params)
    {
        $this->catalogChanged(self::productOf($params));
    }

    public function hookActionObjectSpecificPriceDeleteAfter($params)
    {
        $this->catalogChanged(self::productOf($params));
    }

    public function hookActionObjectImageAddAfter($params)
    {
        $this->catalogChanged(self::productOf($params));
    }

    public function hookActionObjectImageUpdateAfter($params)
    {
        $this->catalogChanged(self::productOf($params));
    }

    public function hookActionObjectImageDeleteAfter($params)
    {
        $this->catalogChanged(self::productOf($params));
    }

    /** Catalog sync: a quantity changed (orders too) - re-sent only when it flips in/out of stock. */
    public function hookActionUpdateQuantity($params)
    {
        try {
            \Quissly\PrestaShop\Sync\Sync::quantityChanged((int) ($params['id_product'] ?? 0));
        } catch (\Throwable $e) {
            \Quissly\PrestaShop\Logger::error('Quissly catalog hook failed', ['exception' => get_class($e)]);
        }
    }

    /** The product an object hook's object belongs to (a combination, specific price or image); 0 for none. */
    private static function productOf($params): int
    {
        $object = $params['object'] ?? null;

        return is_object($object) && isset($object->id_product) ? (int) $object->id_product : 0;
    }

    private function catalogChanged(int $productId): void
    {
        try {
            \Quissly\PrestaShop\Sync\Sync::productChanged($productId);
        } catch (\Throwable $e) {
            \Quissly\PrestaShop\Logger::error('Quissly catalog hook failed', ['exception' => get_class($e)]);
        }
    }

    /** add_to_cart: a storefront cart gaining a product (not a quantity lowered or removed). */
    public function hookActionCartUpdateQuantityBefore($params)
    {
        if (!($this->context->controller instanceof FrontController) || ($params['operator'] ?? '') !== 'up' || !isset($params['product'])) {
            return;
        }
        \Quissly\PrestaShop\Events\Recorder::shopper(
            \Quissly\PrestaShop\Events\EventBody::ADD_TO_CART,
            \Quissly\PrestaShop\Events\EventBody::addToCart((int) $params['product']->id, (int) ($params['id_product_attribute'] ?? 0), (float) ($params['quantity'] ?? 1))
        );
    }

    /** add_to_wishlist: PrestaShop's Wishlist module (blockwishlist 2.x) adding a product. */
    public function hookActionWishlistAddProduct($params)
    {
        $id = (int) ($params['idProduct'] ?? $params['id_product'] ?? 0);
        if ($id > 0) {
            \Quissly\PrestaShop\Events\Recorder::shopper(\Quissly\PrestaShop\Events\EventBody::WISHLIST, \Quissly\PrestaShop\Events\EventBody::wishlist($id));
        }
    }

    /** purchase_successful: an order reaching its first paid status (Orders > Statuses: "paid"). */
    public function hookActionOrderStatusPostUpdate($params)
    {
        if (isset($params['newOrderStatus'], $params['id_order']) && $params['newOrderStatus'] instanceof OrderState) {
            \Quissly\PrestaShop\Events\Recorder::order(new Order((int) $params['id_order']), $params['newOrderStatus']);
        }
    }

    /** A storefront page's styles and scripts for what Quissly has on it (src/Storefront/Page). */
    public function hookDisplayHeader()
    {
        $this->completeUpdate();
        try {
            if ($this->context->controller instanceof FrontController) {
                \Quissly\PrestaShop\Storefront\Page::assets($this->context->controller, $this->_path);
                // The cron's work every few minutes while shoppers visit, for a shop without a server cron.
                if (!$this->context->controller instanceof QuisslyCronModuleFrontController) {
                    \Quissly\PrestaShop\Platform\VisitTick::maybe();
                }
            }
        } catch (\Throwable $e) {
            return '';
        }

        return '';
    }

    /**
     * The overlay's, the media buttons' and the chat's markup - at the top of the page: the
     * scripts read it when they run, and PrestaShop prints its scripts before the closing hook.
     */
    public function hookDisplayAfterBodyOpeningTag()
    {
        try {
            return \Quissly\PrestaShop\Storefront\Page::markup();
        } catch (\Throwable $e) {
            return ''; // never in a shopper's way
        }
    }

    /**
     * Just before a storefront page is sent: X-Quissly-Search on a page that ran the search, and
     * with ?quissly_debug=1 the badge saying who answered (Quissly or PrestaShop) and why.
     *
     * @param array $params ['html' => &string]
     */
    public function hookActionOutputHTMLBefore($params)
    {
        try {
            $asked = \Quissly\PrestaShop\SearchOrigin::asked($_REQUEST);
            $value = \Quissly\PrestaShop\SearchSignal::headerValue();
            if ($value !== null && $asked && !headers_sent()) {
                header('X-Quissly-Search: ' . $value);
            }
            $badge = \Quissly\PrestaShop\SearchOrigin::badge($asked, [
                'quissly' => $this->l('Results from Quissly'),
                'native'  => $this->l('Results from PrestaShop search'),
                'skipped' => $this->l('Quissly did not answer: [reason]'),
            ]);
            if ($badge !== '' && isset($params['html']) && is_string($params['html'])) {
                $params['html'] = str_replace('</body>', $badge . '</body>', $params['html']);
            }
        } catch (\Throwable $e) {
            return; // never in a shopper's way
        }
    }

    /**
     * The storefront search: a Quissly provider when the guard allows (src/Search/SearchProvider),
     * else null and PrestaShop's own search runs.
     *
     * @param array $params ['query' => ProductSearchQuery]
     */
    public function hookProductSearchProvider($params)
    {
        try {
            return \Quissly\PrestaShop\Search\SearchProvider::forQuery($params['query'] ?? null);
        } catch (\Throwable $e) {
            return null; // never in a shopper's way: PrestaShop's search answers
        }
    }
}
