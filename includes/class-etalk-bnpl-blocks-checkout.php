<?php
if (!defined('ABSPATH')) exit;
/** WooCommerce Store API BNPL payment handler. */
final class ETalk_BNPL_Blocks_Checkout {
    public static function pay($context,$result) {
        if (!is_object($context) || ($context->payment_method??'')!=='etalk_bnpl') return;
        if (!class_exists('ETalk_BNPL_Classic_Checkout') || !ETalk_BNPL_Classic_Checkout::bnpl_checkout_allowed())
            throw new Exception('E-TALK BNPL checkout is unavailable.');
        $order=$context->order??null;
        if (!$order || !is_a($order,'WC_Order') || $order->is_paid() || $order->get_payment_method()!=='etalk_bnpl')
            throw new Exception('Invalid BNPL order.');
        $gateway=new ETalk_BNPL_Classic_Checkout();
        $response=$gateway->process_payment_with_fields($order->get_id(),'','');
        if (($response['result']??'')!=='success')
            throw new Exception('BNPL payment not confirmed. Check E-TALK and order notes before retrying.');
        $result->set_status('success');
        $result->set_redirect_url($order->get_checkout_order_received_url());
    }
}
add_action('woocommerce_rest_checkout_process_payment_with_context',array('ETalk_BNPL_Blocks_Checkout','pay'),10,2);
