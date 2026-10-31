<?php
if (!defined('ABSPATH')) exit;
/** Checkout Blocks payment method registration; settlement handled by payment handlers. */
final class ETalk_Blocks_Dev {
    public static function register() {
        add_action('woocommerce_blocks_payment_method_type_registration', array(__CLASS__, 'register_methods'));
    }
    public static function register_methods($registry) {
        $parent = '\\Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType';
        if (!class_exists($parent)) return;
        foreach (array('v1','bnpl') as $channel) {
            $registry->register(new ETalk_Blocks_Dev_Method($channel));
        }
    }
}
// Defined only after WooCommerce's Blocks payment abstraction is available.
add_action('woocommerce_blocks_payment_method_type_registration', function($registry) {
    if (!class_exists('\\Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType')) return;
    if (!class_exists('ETalk_Blocks_Dev_Method', false)) {
        class ETalk_Blocks_Dev_Method extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {
            private $channel;
            public function __construct($channel) {
                $this->channel = $channel;
                $this->name = 'etalk_' . $channel;
            }
            public function initialize() {
                $this->settings = get_option('woocommerce_' . $this->name . '_settings', array());
            }
            public function is_active() {
                return $this->channel==='bnpl' ? (class_exists('ETalk_BNPL_Classic_Checkout') && (new ETalk_BNPL_Classic_Checkout())->is_available()) : ETalk_Merchant_Gateway_Base::v1_ready();
            }
            public function get_payment_method_script_handles() {
                wp_register_script('etalk-blocks-dev-register', plugins_url('../assets/blocks-dev.js', __FILE__), array('wc-blocks-registry','wc-settings','wp-element','wp-html-entities'), ETALK_MERCHANT_GATEWAYS_VERSION, true);
                return array('etalk-blocks-dev-register');
            }
            public function get_payment_method_script_handles_for_admin() {
                return $this->get_payment_method_script_handles();
            }
            public function get_payment_method_data() {
                $channel = $this->channel;
                $url = isset($this->settings['logo_url']) ? $this->settings['logo_url'] : ETalk_Checkout_Preview::DEFAULT_LOGO;
                return array(
                    'title' => $channel === 'bnpl' ? 'E-TALK BNPL' : 'E-TALK V1 Card',
                    'description' => $channel === 'bnpl' ? 'Buy now, pay later — eligibility determined by E-TALK.' : 'V1 card-to-card transaction through E-TALK.',
                    'logo' => esc_url(ETalk_Checkout_Preview::normalize_logo($url)),
                    'admin_preview' => $channel==='bnpl' ? ETalk_BNPL_Classic_Checkout::bnpl_checkout_allowed() : ETalk_Merchant_Gateway_Base::v1_ready(),
                    'otp_ajax' => admin_url('admin-ajax.php'),
                    'otp_nonce' => wp_create_nonce('etalk_v1_blocks_otp'),
                    'bnpl_nonce' => wp_create_nonce('etalk_bnpl_auth_check'),
                    'supports' => array('products'),
                );
            }
        }
    }
    foreach (array('v1','bnpl') as $channel) $registry->register(new ETalk_Blocks_Dev_Method($channel));
}, 10, 1);
