<?php
if (!defined('ABSPATH')) exit;
/** Store API order initialization only. Never invokes a payment service. */
add_action('woocommerce_rest_checkout_process_payment_with_context', function($context, $result) {
    if (!is_object($context) || ($context->payment_method ?? '') !== 'etalk_v1') return;
    if (!class_exists('ETalk_Merchant_Gateway_Base') || !ETalk_Merchant_Gateway_Base::v1_ready()) {
        throw new Exception('V1 Card gateway is not configured or enabled.');
    }
    $order = $context->order ?? null;
    if (!$order || !is_a($order, 'WC_Order') || $order->is_paid() || $order->get_payment_method() !== 'etalk_v1') {
        throw new Exception('V1 unpaid order is unavailable.');
    }
    if (!in_array($order->get_status(), array('pending','failed','on-hold'), true)) {
        throw new Exception('V1 order is not eligible for payment.');
    }
    if ($order->get_status() !== 'pending') {
        $order->update_status('pending', 'V1 payment pending cardholder authorization.');
    }
    // Explicit Store API payment result, not the legacy gateway fallback.
    $result->set_status('success');
    $result->set_redirect_url($order->get_checkout_payment_url(true));
}, 10, 2);

// Secondary safety boundary: never present an unpaid V1 order as a paid receipt.
add_action('template_redirect', function() {
    if (!function_exists('is_order_received_page') || !is_order_received_page()) return;
    $id = absint(get_query_var('order-received'));
    if (!$id) return;
    $order = wc_get_order($id);
    if (!$order || $order->get_payment_method() !== 'etalk_v1' || $order->is_paid()) return;
    if (!ETalk_Merchant_Gateway_Base::v1_ready()) return;
    // Read-only payment-key match before redirect; never mutates a transaction.
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WooCommerce order key authorizes this redirect.
    $provided = isset($_GET['key']) && is_string($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';
    if (!is_string($provided) || !hash_equals((string)$order->get_order_key(), $provided)) return;
    wp_safe_redirect($order->get_checkout_payment_url(true), 302);
    exit;
}, 2);
