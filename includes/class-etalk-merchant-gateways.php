<?php
if (!defined('ABSPATH')) exit;
/** WooCommerce gateway base. Financial authority remains the E-TALK API. */
abstract class ETalk_Merchant_Gateway_Base extends WC_Payment_Gateway {
    protected $channel = '';
    protected $service_path = '';
    public function __construct() {
        $this->id = 'etalk_' . $this->channel;
        $this->method_title = $this->channel === 'bnpl' ? 'E-TALK BNPL' : 'E-TALK V1 Card';
        $this->method_description = $this->channel === 'bnpl' ? __('E-TALK BNPL merchant checkout and account verification.', 'e-talk-merchant-gateways') : __('V1 Card merchant payments secured by wallet, CCV and email verification.', 'e-talk-merchant-gateways');
        $this->has_fields = $this->channel === 'v1';
        $this->supports = array('products');
        $this->init_form_fields();
        $this->init_settings();
        $this->enabled = $this->get_option('enabled', 'no');
        $this->title = $this->get_option('title', $this->method_title);
        $this->icon = esc_url(ETalk_Checkout_Preview::normalize_logo($this->get_option('logo_url', ETalk_Checkout_Preview::DEFAULT_LOGO)));
        $this->description = __('Enter wallet, CCV and email verification code to authorize your V1 payment.', 'e-talk-merchant-gateways');
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this,'process_admin_options'));
        // Obsolete gateway-wide admin warning removed; settings remain available.
        
    }
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled'=>array('title'=>__('Enable gateway','e-talk-merchant-gateways'),'type'=>'checkbox','label'=>__('Enable this payment gateway','e-talk-merchant-gateways'),'default'=>'no'),
            'title'=>array('title'=>__('Display title', 'e-talk-merchant-gateways'),'type'=>'text','default'=>'E-TALK ' . strtoupper($this->channel)),
            'merchant_id'=>array('title'=>__('Merchant ID', 'e-talk-merchant-gateways'),'type'=>'text','description'=>__('V1 Card merchant identifier only. Not used by BNPL.', 'e-talk-merchant-gateways'),'default'=>''),
            'logo_url'=>array('title'=>__('Payment logo URL', 'e-talk-merchant-gateways'), 'type'=>'text', 'description'=>__('Optional. Enter an HTTPS logo URL, or select a logo from your WordPress Media Library. Blank restores the E-TALK default. This branding does not change payment routing.', 'e-talk-merchant-gateways'), 'default'=>ETalk_Checkout_Preview::DEFAULT_LOGO, 'class'=>'etalk-logo-field'),
            'api_secret'=>array('title'=>__('BNPL API secret', 'e-talk-merchant-gateways'),'type'=>'password','description'=>__('BNPL only. Required by E-TALK for signed API authentication. Never disclose this value.', 'e-talk-merchant-gateways'),'default'=>''),
            'api_key'=>array('title'=>__('Merchant API key', 'e-talk-merchant-gateways'),'type'=>'password','description'=>__('API key used to authenticate E-TALK merchant requests. Save settings first.', 'e-talk-merchant-gateways'),'default'=>''),
            'etalk_verify'=>array('title'=>__('Connection test','e-talk-merchant-gateways'),'type'=>'etalk_verify'),
        );
        // The native BNPL contract uses API key + HMAC API secret; NO merchant ID.
        // V1 uses merchant ID + API key and has no confirmed API-secret contract.
        if ($this->channel === 'bnpl') {
            unset($this->form_fields['merchant_id']);
        } elseif ($this->channel === 'v1') {
            unset($this->form_fields['api_secret']);
        }
    }
    public function generate_etalk_verify_html($key,$data) {
        $nonce=wp_create_nonce('etalk_verify_connection');
        $channel=esc_attr($this->channel);
        return '<tr valign="top"><th scope="row">'.esc_html__('Verify Connection','e-talk-merchant-gateways').'</th><td><button type="button" class="button button-secondary etalk-verify-button" data-channel="'.$channel.'" data-nonce="'.esc_attr($nonce).'">'.esc_html__('Verify Connection','e-talk-merchant-gateways').'</button><p class="description">'.esc_html__('Save your credentials first. This only checks authenticated connectivity and never submits a payment.','e-talk-merchant-gateways').'</p><div class="etalk-verify-result" role="status" aria-live="polite"></div></td></tr>';
    }
    public function validate_logo_url_field($key, $value) {
        $value = trim((string) $value);
        if ($value === '') return '';
        $url = esc_url_raw($value, array('https'));
        if (!$url || stripos($url, 'https://') !== 0) {
            if (class_exists('WC_Admin_Settings')) WC_Admin_Settings::add_error(__('E-TALK logo must use a valid HTTPS URL.', 'e-talk-merchant-gateways'));
            return $this->get_option($key, ETalk_Checkout_Preview::DEFAULT_LOGO);
        }
        return $url;
    }
    /** V1 availability requires explicit merchant enablement and valid configured credentials. */
    public static function v1_ready() {
        if (!function_exists('WC') || !class_exists('WooCommerce')) return false;
        $settings = get_option('woocommerce_etalk_v1_settings', array());
        return is_array($settings) && ($settings['enabled'] ?? 'no') === 'yes'
            && ctype_digit((string)($settings['merchant_id'] ?? ''))
            && (int)($settings['merchant_id'] ?? 0) > 0
            && strlen((string)($settings['api_key'] ?? '')) === 32;
    }
    public static function local_admin_preview_allowed() { return self::v1_ready(); }
    public function is_available() {
        return $this->channel === 'v1' && self::v1_ready() && parent::is_available();
    }
    public function validate_fields() {
        if ($this->channel !== 'v1') return false;
        // WooCommerce Checkout Blocks calls validate_fields on the FIRST
        // order-creation request, before an order ID exists and before an OTP
        // can be issued. At this stage the gateway only creates an unpaid order.
        // This exception is limited to the WooCommerce Store API checkout route;
        // the order-pay settlement path still validates all three credentials.
        $uri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        $is_blocks_creation = defined('REST_REQUEST') && REST_REQUEST
            && (bool) preg_match('~/(?:index\.php/)?wp-json/wc/store/(?:v[0-9]+/)?checkout(?:[/?]|$)~', $uri);
        if ($is_blocks_creation && self::v1_ready()) return true;
        // WooCommerce verifies the checkout/order-pay nonce before invoking gateway validation.
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce-managed checkout request.
        $wallet = isset($_POST['etalk_v1_wallet']) && is_string($_POST['etalk_v1_wallet']) ? sanitize_text_field(wp_unslash($_POST['etalk_v1_wallet'])) : '';
        $ccv = isset($_POST['etalk_v1_ccv']) && is_string($_POST['etalk_v1_ccv']) ? sanitize_text_field(wp_unslash($_POST['etalk_v1_ccv'])) : '';
        $otp = isset($_POST['etalk_v1_otp']) && is_string($_POST['etalk_v1_otp']) ? sanitize_text_field(wp_unslash($_POST['etalk_v1_otp'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        if (!preg_match('/^0x[a-fA-F0-9]{40}$/D', $wallet) || !preg_match('/^[0-9]{3}$/D', $ccv) || !preg_match('/^[a-f0-9]{64}$/D', $otp)) {
            wc_add_notice(__('Enter your wallet, three-digit CCV and the verification code sent to your email.', 'e-talk-merchant-gateways'), 'error');return false;
        }
        return true;
    }
    public function payment_fields() {
        if ($this->channel !== 'v1') return;
        echo '<p>Enter your WebChain wallet and V1 Card CCV. After creating an unpaid order, request the code on the secure payment page.</p>';
        echo '<p><label>Wallet address <input name="etalk_v1_wallet" autocomplete="off" placeholder="0x..." required></label></p>';
        echo '<p><label>V1 Card CCV <input name="etalk_v1_ccv" type="password" inputmode="numeric" maxlength="3" autocomplete="off" required></label></p>';
        if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-pay')) echo '<p><button type="button" class="button etalk-v1-request-otp">Send verification code</button> <span class="etalk-v1-otp-status" role="status"></span></p>';
        if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-pay')) echo '<p><label>Email verification code (64 characters) <input name="etalk_v1_otp" type="password" maxlength="64" autocomplete="one-time-code" required></label></p>';
    }
    public function process_payment($order_id) {
        if ($this->channel !== 'v1' || !self::v1_ready() || !$this->is_available()) {
            wc_add_notice(__('E-TALK V1 Card is not enabled or its merchant credentials are incomplete.', 'e-talk-merchant-gateways'),'error');
            return array('result'=>'failure');
        }
        $order=wc_get_order($order_id);
        if (!$order || $order->is_paid()) return array('result'=>'failure');
        // Never signal payment success from Blocks or classic checkout initiation.
        // The dedicated Store API integration owns unpaid-order redirection.
        if (!(function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-pay'))) {
            wc_add_notice(__('V1 payment must be authorized on the secure order-payment page.', 'e-talk-merchant-gateways'), 'error');
            return array('result'=>'failure');
        }
        if (!$this->validate_fields()) return array('result'=>'failure');
        // validate_fields() has already checked the types and formats; WooCommerce verifies the order-pay nonce.
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce-managed order-pay request.
        $wallet=isset($_POST['etalk_v1_wallet']) && is_string($_POST['etalk_v1_wallet']) ? sanitize_text_field(wp_unslash($_POST['etalk_v1_wallet'])) : '';
        $ccv=isset($_POST['etalk_v1_ccv']) && is_string($_POST['etalk_v1_ccv']) ? sanitize_text_field(wp_unslash($_POST['etalk_v1_ccv'])) : '';
        $otp=isset($_POST['etalk_v1_otp']) && is_string($_POST['etalk_v1_otp']) ? sanitize_text_field(wp_unslash($_POST['etalk_v1_otp'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        $ref='WC'.$order->get_id().'V1';
        $reply=ETalk_V1_Test_API::call('process-payment',$this->get_option('merchant_id'),$this->get_option('api_key'),array(
            'card_number'=>$wallet,'ccv'=>$ccv,'otp'=>$otp,'amount'=>(string)$order->get_total(),
            'currency'=>$order->get_currency(),'reference'=>$ref
        ));
        unset($ccv,$otp);
        if ($reply['http']===200 && in_array($reply['data']['code']??'',array('payment_completed','already_processed'),true) && !empty($reply['data']['transaction_id'])) {
            $order->payment_complete(sanitize_text_field((string)$reply['data']['transaction_id']));
            $order->add_order_note('E-TALK V1 confirmed external settlement (reference '.$ref.').');
            WC()->cart->empty_cart();
            return array('result'=>'success','redirect'=>$this->get_return_url($order));
        }
        wc_add_notice(__('V1 payment not confirmed. E-TALK response: ', 'e-talk-merchant-gateways').esc_html($reply['data']['code']??('http_'.$reply['http'])),'error');
        return array('result'=>'failure');
    }

}
class ETalk_Merchant_Gateway_BNPL extends ETalk_Merchant_Gateway_Base {
    protected $channel = 'bnpl';
    protected $service_path = '/api/bnpl/v1/';
}
class ETalk_Merchant_Gateway_V1 extends ETalk_Merchant_Gateway_Base {
    protected $channel = 'v1';
    protected $service_path = '/v1-card/v1/';
}
add_filter('woocommerce_payment_gateways', function($methods) {
    $methods[] = 'ETalk_BNPL_Classic_Checkout';
    $methods[] = 'ETalk_Merchant_Gateway_V1';
    return $methods;
});
