<?php
if (!defined('ABSPATH')) exit;
/** V1 Card Checkout Blocks customer flow. */
final class ETalk_V1_Blocks_Direct {
    private static function allowed() {
        return class_exists('ETalk_Merchant_Gateway_Base') && ETalk_Merchant_Gateway_Base::v1_ready();
    }
    private static function amount() {
        if (!function_exists('WC') || !WC()->cart) return null;
        WC()->cart->calculate_totals();
        return number_format((float) WC()->cart->get_total('edit'), 2, '.', '');
    }
    public static function request_otp() {
        if (!self::allowed() || !check_ajax_referer('etalk_v1_blocks_otp', 'nonce', false) || !function_exists('WC') || !WC()->session) {
            wp_send_json_error(array('code'=>'forbidden'), 403);
        }
        $wallet = isset($_POST['wallet']) ? sanitize_text_field(wp_unslash($_POST['wallet'])) : '';
        $ccv = isset($_POST['ccv']) ? sanitize_text_field(wp_unslash($_POST['ccv'])) : '';
        $amount = self::amount();
        $currency = get_woocommerce_currency();
        if (!preg_match('/^0x[a-fA-F0-9]{40}$/D', $wallet) || !preg_match('/^[0-9]{3}$/D', $ccv)
            || $amount === null || (float)$amount <= 0 || !in_array($currency, array('USD','ETK'), true)) {
            wp_send_json_error(array('code'=>'invalid_request'), 400);
        }
        $existing = WC()->session->get('etalk_v1_external_pending');
        $reference = (is_array($existing) && !empty($existing['reference']) && preg_match('/^[A-Za-z0-9_-]{8,100}$/D', (string)$existing['reference']))
            ? (string)$existing['reference'] : 'WCV1'.bin2hex(random_bytes(16));
        $options = get_option('woocommerce_etalk_v1_settings', array());
        $r = ETalk_V1_Test_API::call('request-payment-otp', $options['merchant_id']??'', $options['api_key']??'', array(
            'card_number'=>$wallet,'ccv'=>$ccv,'amount'=>$amount,'currency'=>$currency,'reference'=>$reference
        ));
        unset($ccv);
        if ($r['http'] !== 200 || ($r['data']['status']??'') !== 'otp_sent') {
            wp_send_json_error(array('code'=>$r['data']['code']??'request_failed'), $r['http']>=400&&$r['http']<600?$r['http']:502);
        }
        WC()->session->set('etalk_v1_external_pending', array(
            'reference'=>$reference,'wallet'=>$wallet,'amount'=>$amount,'currency'=>$currency,
            'created'=>time(),'merchant_id'=>(int)($options['merchant_id']??0)
        ));
        wp_send_json_success(array('code'=>'otp_sent'));
    }
    public static function pay($context, $result) {
        if (!is_object($context) || ($context->payment_method??'') !== 'etalk_v1') return;
        if (!self::allowed() || !function_exists('WC') || !WC()->session) throw new Exception('V1 checkout is unavailable.');
        $order=$context->order??null;
        if (!$order || !is_a($order, 'WC_Order') || $order->is_paid() || $order->get_payment_method()!=='etalk_v1') throw new Exception('Invalid V1 order.');
        $pd=(array)($context->payment_data??array());
        // Accept only the official payment_data object, never browser display fields
        // or order metadata. Missing payment data must fail closed.
        if (isset($pd['paymentMethodData']) && is_array($pd['paymentMethodData'])) {
            $pd = $pd['paymentMethodData'];
        }
        if (!isset($pd['etalk_v1_wallet'])) {
            throw new Exception('V1 Checkout Blocks did not transmit payment details. Refresh checkout and retry; no charge was submitted.');
        }
        // Normalize copied OTP text without weakening the 64-hex check.
        $wallet=is_scalar($pd['etalk_v1_wallet']??null)?trim((string)$pd['etalk_v1_wallet']):'';
        $ccv=is_scalar($pd['etalk_v1_ccv']??null)?trim((string)$pd['etalk_v1_ccv']):'';
        $otp=is_scalar($pd['etalk_v1_otp']??null)?strtolower(preg_replace('/\s+/', '', (string)$pd['etalk_v1_otp'])):'';
        if (!preg_match('/^0x[a-fA-F0-9]{40}$/D',$wallet)) throw new Exception('Enter a valid 42-character WebChain wallet address.');
        if (!preg_match('/^[0-9]{3}$/D',$ccv)) throw new Exception('Enter the 3-digit V1 Card CCV.');
        if (!preg_match('/^[a-f0-9]{64}$/D',$otp)) throw new Exception('Enter the full 64-character hexadecimal code from the E-TALK email; do not enter a 6-digit code.');
        $pending=WC()->session->get('etalk_v1_external_pending');
        $options=get_option('woocommerce_etalk_v1_settings',array());
        $amount=number_format((float)$order->get_total(),2,'.','');
        if (!is_array($pending) || empty($pending['reference']) || !hash_equals((string)($pending['wallet']??''),$wallet)
            || !hash_equals((string)($pending['amount']??''),$amount) || (string)($pending['currency']??'')!==$order->get_currency()
            || (int)($pending['merchant_id']??0)!==(int)($options['merchant_id']??0)
            || time()-(int)($pending['created']??0)>300) throw new Exception('Request a new verification code for this order total.');
        // No CCV or OTP in order metadata, notes or logs.
        $r=ETalk_V1_Test_API::call('process-payment',$options['merchant_id']??'',$options['api_key']??'',array(
            'card_number'=>$wallet,'ccv'=>$ccv,'otp'=>$otp,'amount'=>$amount,'currency'=>$order->get_currency(),
            'reference'=>(string)$pending['reference']
        ));
        unset($ccv,$otp);
        if ($r['http']!==200 || !in_array($r['data']['code']??'',array('payment_completed','already_processed'),true)
            || empty($r['data']['transaction_id'])) throw new Exception('V1 payment not confirmed: '.sanitize_key($r['data']['code']??'connection_failed'));
        $transaction=sanitize_text_field((string)$r['data']['transaction_id']);
        if (!$order->is_paid()) $order->payment_complete($transaction);
        WC()->session->__unset('etalk_v1_external_pending');
        $result->set_status('success');
        $result->set_redirect_url($order->get_checkout_order_received_url());
    }
}
add_action('wp_ajax_etalk_v1_blocks_request_otp',array('ETalk_V1_Blocks_Direct','request_otp'));
add_action('wp_ajax_nopriv_etalk_v1_blocks_request_otp',array('ETalk_V1_Blocks_Direct','request_otp'));
add_action('woocommerce_rest_checkout_process_payment_with_context',array('ETalk_V1_Blocks_Direct','pay'),10,2);
