<?php
/**
 * Plugin Name: E-TALK Merchant Gateways
 * Description: E-TALK V1 Card and BNPL payment integration for WooCommerce.
 * Version: 1.0.0
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Author: E-TALK LTD
 * License: GPLv2 or later
 * Text Domain: e-talk-merchant-gateways
 */
if (!defined('ABSPATH')) { exit; }
if (!defined('ETALK_MERCHANT_GATEWAYS_VERSION')) define('ETALK_MERCHANT_GATEWAYS_VERSION', '1.0.0');
add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        // Uses WooCommerce Store API payment methods for Checkout Blocks.
    }
});
add_action('plugins_loaded', function () {
    if (!class_exists('WC_Payment_Gateway')) return;
    require_once __DIR__ . '/includes/class-etalk-merchant-gateways.php';
    require_once __DIR__ . '/includes/class-etalk-bnpl-classic-checkout.php';
}, 20);
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    if (!class_exists('WooCommerce')) return $links;
    array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=checkout')) . '">' . esc_html__('Payment settings', 'e-talk-merchant-gateways') . '</a>');
    return $links;
});

// Shared payment logo helper is required by both gateway and Checkout Blocks.
require_once __DIR__ . '/includes/class-etalk-checkout-preview.php';

// Front-end payment panels require styles on the actual checkout page.
// This is intentionally scoped to checkout, never to unrelated theme pages.
add_action('wp_enqueue_scripts', function () {
    if (!function_exists('is_checkout') || !is_checkout()) return;
    wp_enqueue_style(
        'etalk-merchant-ui',
        plugins_url('assets/merchant-ui.css', __FILE__),
        array(),
        ETALK_MERCHANT_GATEWAYS_VERSION
    );
}, 20);

// Merchant payment settings assets.
add_action('admin_enqueue_scripts', function($hook) {
    if (!current_user_can('manage_woocommerce')) return;
    // Read-only navigation parameters; no settings are changed here.
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Page detection only.
    $page = isset($_GET['page']) && is_scalar($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
    $tab = isset($_GET['tab']) && is_scalar($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
    // phpcs:enable WordPress.Security.NonceVerification.Recommended
    $is_settings = $page === 'wc-settings' && $tab === 'checkout';
    if (!$is_settings) return;
    wp_enqueue_style('etalk-merchant-ui', plugins_url('assets/merchant-ui.css', __FILE__), array(), ETALK_MERCHANT_GATEWAYS_VERSION);
    if ($is_settings) {
        wp_enqueue_media();
        wp_enqueue_script('etalk-verify-connection', plugins_url('assets/verify-connection.js', __FILE__), array('jquery'), ETALK_MERCHANT_GATEWAYS_VERSION, true);
        wp_enqueue_script('etalk-merchant-settings', plugins_url('assets/merchant-settings.js', __FILE__), array('jquery'), ETALK_MERCHANT_GATEWAYS_VERSION, true);
    }
});

// Checkout Blocks payment method registration.
require_once __DIR__ . '/includes/class-etalk-blocks-dev.php';

require_once __DIR__ . '/includes/class-etalk-verify-connection.php';

require_once __DIR__ . "/includes/class-etalk-v1-test-api.php";

add_action('wp_enqueue_scripts', function () {
 if (!function_exists('is_checkout') || !is_checkout() || !ETalk_Merchant_Gateway_Base::v1_ready()) return;
 wp_enqueue_script('etalk-v1-test',plugins_url('assets/v1-test.js',__FILE__),array('jquery'),ETALK_MERCHANT_GATEWAYS_VERSION,true);
 $order_id=absint(get_query_var('order-pay'));
 wp_localize_script('etalk-v1-test','etalkV1Test',array('ajax'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('etalk_v1_checkout_test'),'orderId'=>$order_id,'orderKey'=>($order_id && ($o=wc_get_order($order_id))) ? $o->get_order_key() : ''));
});

require_once __DIR__ . "/includes/class-etalk-v1-blocks-direct.php";

// BNPL customer authentication endpoints.
require_once __DIR__ . '/includes/class-etalk-bnpl-auth-check.php';
ETalk_BNPL_Auth_Check::register();

// BNPL Store API payment bridge.
require_once __DIR__ . '/includes/class-etalk-bnpl-blocks-checkout.php';
