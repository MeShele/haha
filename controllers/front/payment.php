<?php
class FreedomPayPaymentModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    public $display_column_left = false;
    public $display_column_right = false;
    private $logFile;

    public function __construct()
    {
        parent::__construct();
        $this->logFile = dirname(__FILE__).'/../../var/freedompay_' . date('Ymd') . '.log';
    }

    public function initContent()
    {
        // Restore session by token
        if ($session_token = Tools::getValue('session_token')) {
            $cart_id = (int)Db::getInstance()->getValue('
                SELECT cart_id
                FROM '._DB_PREFIX_.'freedompay_sessions
                WHERE session_token = "'.pSQL($session_token).'"
            ');
            
            if ($cart_id) {
                $this->context->cart = new Cart($cart_id);
                $this->log("Restored cart from session token: $cart_id");
            }
        }

        $this->log('Payment controller initContent started');
        
        // Check cart validity
        $cart = $this->context->cart;
        $this->log("Cart ID: {$cart->id}, Customer ID: {$cart->id_customer}");
        
        $invalid = false;
        $reasons = [];
        
        if (!$cart->id) {
            $invalid = true;
            $reasons[] = 'no cart id';
        }
        
        if (!$cart->id_customer) {
            $invalid = true;
            $reasons[] = 'no customer id';
        }
        
        if (!$this->module->active) {
            $invalid = true;
            $reasons[] = 'module not active';
        }
        
        if ($invalid) {
            $this->log('Invalid cart: ' . implode(', ', $reasons) . ', redirecting to step 1');
            Tools::redirect($this->context->link->getPageLink('order', true, null, array('step' => 1)));
        }
        
        // Check if order already exists
        if ($orderId = Order::getOrderByCartId($cart->id)) {
            $this->log("Order already exists: $orderId, redirecting to confirmation");
            Tools::redirect($this->context->link->getPageLink(
                'order-confirmation',
                true,
                null,
                [
                    'id_cart' => $cart->id,
                    'id_module' => $this->module->id,
                    'id_order' => $orderId,
                    'key' => $this->context->customer->secure_key
                ]
            ));
        }
        
        // Initialize payment with cart ID
        $this->initPayment($cart);
    }
    
    private function initPayment($cart)
    {
        $this->log("Initializing payment for cart: ".$cart->id);
        
        // Get configuration
        $merchant_id = Configuration::get('FREEDOMPAY_MERCHANT_ID');
        $api_url = Configuration::get('FREEDOMPAY_API_URL');
        $secret = Configuration::get('FREEDOMPAY_MERCHANT_SECRET');
        $test_mode = Configuration::get('FREEDOMPAY_TEST_MODE');
        $total = $cart->getOrderTotal(true, Cart::BOTH);
        
        $this->log("Config: merchant_id=$merchant_id, api_url=$api_url, test_mode=$test_mode, total=$total");
        
        // Validate configuration
        if (empty($merchant_id) || empty($secret) || empty($api_url)) {
            $error = 'Payment module is not configured';
            $this->log($error, true);
            $this->errors[] = $this->module->l($error);
            $this->setTemplate('module:freedompay/views/templates/front/payment_error.tpl');
            return;
        }
        
        // Check for existing token
        $existing_token = Db::getInstance()->getValue('
            SELECT session_token
            FROM '._DB_PREFIX_.'freedompay_sessions
            WHERE cart_id = '.(int)$cart->id.'
        ');
        
        if ($existing_token) {
            $session_token = $existing_token;
            $this->log("Using existing session token: $session_token");
        } else {
            // Generate session token
            $session_token = md5(uniqid(mt_rand(), true));
            $this->log("Generated new session token: $session_token");
            
            // Save session token in database
            Db::getInstance()->insert('freedompay_sessions', array(
                'cart_id' => (int)$cart->id,
                'session_token' => pSQL($session_token),
                'date_add' => date('Y-m-d H:i:s'),
            ));
        }
        
        // Prepare payment data
        $paymentData = array(
            'pg_merchant_id' => $merchant_id,
            'pg_order_id' => $cart->id,
            'pg_amount' => number_format($total, 2, '.', ''),
            'pg_currency' => $this->context->currency->iso_code,
            'pg_description' => 'Booking #' . $cart->id,
            'pg_salt' => Tools::passwdGen(10, 'NUMERIC'),
            'pg_success_url' => $this->context->link->getPageLink(
                'order-confirmation',
                true,
                null,
                array(
                    'id_cart' => $cart->id,
                    'id_module' => $this->module->id,
                    'key' => $this->context->customer->secure_key,
                    'session_token' => $session_token
                )
            ),
            'pg_failure_url' => $this->context->link->getPageLink(
                'order',
                true,
                null,
                array('step' => 3)
            ),
            'pg_result_url' => $this->context->link->getModuleLink(
                'freedompay',
                'callback',
                array('session_token' => $session_token),
                true
            ),
            'pg_testing_mode' => $test_mode ? 1 : 0,
            'pg_need_email_notification' => 1,
            'pg_user_contact_email' => $this->context->customer->email,
        );
        
        $this->log("Payment data: " . print_r($paymentData, true));

        // Generate signature
        ksort($paymentData);
        $signString = 'init_payment.php;' . implode(';', array_values($paymentData)) . ';' . $secret;
        $signature = md5($signString);
        $paymentData['pg_sig'] = $signature;
        
        $this->log("Signature string: $signString");
        $this->log("Generated signature: $signature");
        
        // Send request to FreedomPay
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url . '/init_payment.php');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($paymentData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            $this->log('cURL Error: ' . curl_error($ch), true);
        }
        
        curl_close($ch);

        $this->log("FreedomPay response (HTTP $httpCode): $response");
        
        if ($httpCode != 200) {
            $error = "FreedomPay API returned HTTP code $httpCode";
            $this->log($error, true);
            $this->errors[] = $this->module->l($error);
            $this->setTemplate('module:freedompay/views/templates/front/payment_error.tpl');
            return;
        }
        
        // Parse XML response
        $xml = simplexml_load_string($response);
        if (!$xml) {
            $error = "Failed to parse FreedomPay response";
            $this->log($error, true);
            $this->errors[] = $this->module->l($error);
            $this->setTemplate('module:freedompay/views/templates/front/payment_error.tpl');
            return;
        }
        
        // Check response status
        if ((string)$xml->pg_status != 'ok') {
            $errorCode = (string)$xml->pg_error_code;
            $errorDesc = (string)$xml->pg_error_description;
            $error = "FreedomPay error $errorCode: $errorDesc";
            $this->log($error, true);
            $this->errors[] = $this->module->l('Payment initiation failed: ') . $errorDesc;
            $this->setTemplate('module:freedompay/views/templates/front/payment_error.tpl');
            return;
        }
        
        // Get redirect URL
        $redirectUrl = (string)$xml->pg_redirect_url;
        $this->log("Redirecting to payment page: $redirectUrl");
        
        // Redirect user
        Tools::redirect($redirectUrl);
    }
    
    private function log($message, $isError = false)
    {
        $prefix = date('[Y-m-d H:i:s]') . ($isError ? ' [ERROR] ' : ' ');
        file_put_contents($this->logFile, $prefix . $message . PHP_EOL, FILE_APPEND);
    }
}