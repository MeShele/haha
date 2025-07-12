<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class FreedomPay extends PaymentModule
{
    private $logFile;
    
    public function __construct()
    {
        $this->name = 'freedompay';
        $this->tab = 'payments_gateways';
        $this->version = '4.0.5';
        $this->author = 'FreedomPay';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';
        
        parent::__construct();

        $this->displayName = $this->l('FreedomPay');
        $this->description = $this->l('Accept payments via FreedomPay for hotel bookings');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall?');
        
        $this->logFile = dirname(__FILE__).'/var/freedompay_'.date('Ymd').'.log';
    }

    public function install()
    {
        // Инициализация пути к лог-файлу
        $this->logFile = dirname(__FILE__).'/var/freedompay_'.date('Ymd').'.log';
        $this->log('Installation started');
        
        if (!function_exists('curl_init')) {
            $this->log('cURL extension is required', true);
            $this->_errors[] = $this->l('cURL extension is required');
            return false;
        }
        
        // Создаем папку для логов
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            if (!@mkdir($logDir, 0755, true)) {
                $this->_errors[] = $this->l('Cannot create log directory');
                return false;
            }
        }

        $success = parent::install() 
            && $this->registerHook('payment') // Используем старый хук payment
            && $this->registerHook('paymentReturn')
            && $this->registerHook('header')
            && $this->registerHook('actionAuthentication')
            && $this->registerHook('actionCustomerAccountAdd')
            && $this->createSessionTable()
            && Configuration::updateValue('FREEDOMPAY_TEST_MODE', 1);
        
        if ($success) {
            $this->log('Installation successful');
        } else {
            $this->log('Installation failed', true);
        }
        
        return $success;
    }

    public function uninstall()
    {
        $this->log('Uninstallation started');
        
        $success = parent::uninstall()
            && $this->unregisterHook('paymentOptions')
            && $this->unregisterHook('paymentReturn')
            && $this->unregisterHook('header')
            && $this->unregisterHook('actionAuthentication')
            && $this->unregisterHook('actionCustomerAccountAdd')
            && $this->dropSessionTable()
            && Configuration::deleteByName('FREEDOMPAY_MERCHANT_ID')
            && Configuration::deleteByName('FREEDOMPAY_MERCHANT_SECRET')
            && Configuration::deleteByName('FREEDOMPAY_API_URL')
            && Configuration::deleteByName('FREEDOMPAY_TEST_MODE');
            
        if ($success) {
            $this->log('Uninstallation successful');
        } else {
            $this->log('Uninstallation failed', true);
        }
        
        return $success;
    }

    private function createSessionTable()
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'freedompay_sessions` (
            `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `cart_id` int(11) NOT NULL,
            `session_token` varchar(64) NOT NULL,
            `date_add` datetime NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `session_token` (`session_token`),
            KEY `cart_id` (`cart_id`)
        ) ENGINE='._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8;';

        return Db::getInstance()->execute($sql);
    }

    private function dropSessionTable()
    {
        $sql = 'DROP TABLE IF EXISTS `'._DB_PREFIX_.'freedompay_sessions`';
        return Db::getInstance()->execute($sql);
    }

    public function getContent()
    {
        $output = '';
        $this->log('Loading module configuration page');
        
        if (Tools::isSubmit('submit_freedompay')) {
            $this->log('Processing form submission');
            
            $merchant_id = Tools::getValue('merchant_id');
            $merchant_secret = Tools::getValue('merchant_secret');
            $api_url = Tools::getValue('api_url');
            $test_mode = Tools::getValue('test_mode');

            if (empty($merchant_id) || empty($merchant_secret) || empty($api_url)) {
                $this->log('Validation failed: all fields are required', true);
                $output .= $this->displayError($this->l('All fields are required'));
            } else {
                Configuration::updateValue('FREEDOMPAY_MERCHANT_ID', $merchant_id);
                Configuration::updateValue('FREEDOMPAY_MERCHANT_SECRET', $merchant_secret);
                Configuration::updateValue('FREEDOMPAY_API_URL', $api_url);
                Configuration::updateValue('FREEDOMPAY_TEST_MODE', $test_mode);
                
                $this->log('Settings updated successfully');
                $output .= $this->displayConfirmation($this->l('Settings updated'));
            }
        }

        $this->context->smarty->assign(array(
            'module_dir' => $this->getPathUri(),
            'merchant_id' => Configuration::get('FREEDOMPAY_MERCHANT_ID'),
            'merchant_secret' => Configuration::get('FREEDOMPAY_MERCHANT_SECRET'),
            'api_url' => Configuration::get('FREEDOMPAY_API_URL'),
            'test_mode' => Configuration::get('FREEDOMPAY_TEST_MODE'),
            'form_action' => $_SERVER['REQUEST_URI'],
        ));

        return $output . $this->display(__FILE__, 'views/templates/admin/configuration.tpl');
    }

    public function hookPaymentOptions($params)
    {
        $this->log('hookPaymentOptions triggered');
        
        if (!$this->active) {
            $this->log('Module not active, skipping');
            return [];
        }

        $cart = $this->context->cart;
        $this->log("Cart ID: {$cart->id}, Customer ID: {$cart->id_customer}");

        // Check if it's a booking cart
        $is_booking = false;
        $products = $cart->getProducts();
        $this->log("Cart products count: " . count($products));
        
        foreach ($products as $product) {
            $this->log("Checking product ID: {$product['id_product']}");
            
            try {
                $is_room = Db::getInstance()->getValue('
                    SELECT id_product 
                    FROM '._DB_PREFIX_.'htl_room_type 
                    WHERE id_product = '.(int)$product['id_product']
                );
                
                $this->log("Is room product: " . ($is_room ? 'Yes' : 'No'));
                
                if ($is_room) {
                    $is_booking = true;
                    break;
                }
            } catch (Exception $e) {
                $this->log("Product check error: " . $e->getMessage(), true);
            }
        }
        
        $this->log("Is booking cart: " . ($is_booking ? 'Yes' : 'No'));
        
        if (!$is_booking) {
            $this->log('Not a booking cart, skipping payment button');
            return [];
        }

        // Check currency compatibility
        $currency_order = new Currency($cart->id_currency);
        $currencies_module = $this->getCurrency($cart->id_currency);
        
        if (!in_array($currency_order->id, array_keys($currencies_module))) {
            $this->log('Currency not supported: '.$currency_order->id);
            return [];
        }

        $booking_total = $cart->getOrderTotal(true, Cart::BOTH);
        $this->log("Booking total: $booking_total");
        
        // Assign variables to template
        $this->context->smarty->assign([
            'module_dir' => $this->getPathUri(),
            'booking_total' => $booking_total,
            'link' => $this->context->link,
        ]);
        
        $paymentOption = new PrestaShop\PrestaShop\Core\Payment\PaymentOption();
        $paymentOption->setCallToActionText($this->l('Pay with FreedomPay'))
            ->setModuleName($this->name)
            ->setAction($this->context->link->getModuleLink(
                $this->name,
                'payment',
                [],
                true
            ))
            ->setAdditionalInformation($this->fetch('module:freedompay/views/templates/hook/payment.tpl'));
        
        return [$paymentOption];
    }
    
    public function hookPaymentReturn($params)
    {
        if (!$this->active) {
            return;
        }
        
        $order = $params['order'];
        if ($order->module != $this->name) {
            return;
        }
        
        $this->context->smarty->assign(array(
            'shop_name' => $this->context->shop->name,
            'total' => Tools::displayPrice(
                $order->getOrdersTotalPaid(),
                new Currency($order->id_currency),
                false
            ),
            'status' => 'ok',
            'reference' => $order->reference,
            'contact_url' => $this->context->link->getPageLink('contact', true)
        ));
        
        return $this->display(__FILE__, 'views/templates/hook/payment_return.tpl');
    }
    
    public function hookHeader()
    {
        $this->context->controller->addCSS($this->getPathUri().'views/css/freedompay.css', 'all');
    }
    
    public function hookActionAuthentication($params)
    {
        $this->restoreCartFromSession();
    }
    
    public function hookActionCustomerAccountAdd($params)
    {
        $this->restoreCartFromSession();
    }
    
    private function restoreCartFromSession()
    {
        if ($session_token = Tools::getValue('session_token')) {
            $cart_id = (int)Db::getInstance()->getValue('
                SELECT cart_id
                FROM '._DB_PREFIX_.'freedompay_sessions
                WHERE session_token = "'.pSQL($session_token).'"
            ');
            
            if ($cart_id) {
                $this->context->cart = new Cart($cart_id);
                $this->context->cookie->id_cart = $cart_id;
                $this->context->cookie->write();
                $this->log("Restored cart from session token: $cart_id");
            }
        }
    }

      public function hookPayment($params)
    {
        $this->log('hookPayment triggered');
        
        if (!$this->active) {
            $this->log('Module not active, skipping');
            return;
        }

        $cart = $this->context->cart;
        $this->log("Cart ID: {$cart->id}, Customer ID: {$cart->id_customer}");

        // Упрощенная проверка для бронирований
        $is_booking = true; // Для тестирования показываем всегда

        $this->log("Is booking cart: " . ($is_booking ? 'Yes' : 'No'));
        
        if (!$is_booking) {
            $this->log('Not a booking cart, skipping payment button');
            return;
        }

        $booking_total = $cart->getOrderTotal(true, Cart::BOTH);
        $this->log("Booking total: $booking_total");
        
        // Передаем переменные в шаблон как в оригинальном модуле
        $this->context->smarty->assign(array(
            'payment_link' => $this->context->link->getModuleLink('freedompay', 'payment', array(), true),
            'module_dir' => $this->getPathUri(),
            'booking_total' => $booking_total,
        ));

        $this->log('Displaying payment button');
        return $this->display(__FILE__, 'views/templates/hook/payment.tpl');
    }
    
    /**
     * Log messages to file
     */
    private function log($message, $isError = false)
    {
        $prefix = date('[Y-m-d H:i:s]') . ($isError ? ' [ERROR] ' : ' ');
        file_put_contents($this->logFile, $prefix . $message . PHP_EOL, FILE_APPEND);
        
        if ($isError) {
            PrestaShopLogger::addLog('FreedomPay: ' . $message, 3);
        }
    }
}