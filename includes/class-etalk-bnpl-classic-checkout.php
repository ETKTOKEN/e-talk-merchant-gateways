<?php
if (!defined('ABSPATH')) exit;
/** BNPL WooCommerce checkout; merchant-authenticated, customer OTP session and per-order reference. */
final class ETalk_BNPL_Classic_Checkout extends ETalk_Merchant_Gateway_BNPL {
    public function __construct() {
        parent::__construct();
        $this->method_description = __('E-TALK BNPL with customer OTP and C-Lock settlement.', 'e-talk-merchant-gateways');
        $this->has_fields = true;
        $this->enabled = $this->get_option('enabled','no');
        $this->description = __('Sign in with your E-TALK account, verify OTP and check eligibility.', 'e-talk-merchant-gateways');
    }
    public static function bnpl_checkout_allowed() {
        if (!function_exists('WC')) return false;
        $settings=get_option('woocommerce_etalk_bnpl_settings',array());
        return is_array($settings) && ($settings['enabled']??'no')==='yes' && !empty($settings['api_key']) && !empty($settings['api_secret']);
    }
    public function is_available() {
        if (!self::bnpl_checkout_allowed()) return false;
        if (!function_exists('WC') || !WC()->cart || WC()->cart->is_empty()) return false;
        return true;
    }
    public function payment_fields() {
        echo '<p>Login, OTP and eligibility are completed on the E-TALK BNPL Checkout Block. The reference is generated automatically for this order.</p>';
        echo '<p>For BNPL checkout, use the secure E-TALK authentication and verification flow.</p>';
    }
    private static function valid_session() {
        $s = ETalk_BNPL_Auth_Check::read_identity();
        if (!is_array($s) || !preg_match('/^[a-f0-9]{64}$/D',(string)($s['session']??'')) ||
            (int)($s['verified_at']??0)<time()-600 || (int)($s['verified_at']??0)>time() ||
            !is_email((string)($s['email']??''))) return null;
        return $s;
    }
    public function validate_fields() {
        return self::bnpl_checkout_allowed() && (bool)self::valid_session();
    }
    public function process_payment($order_id) {
        return $this->process_payment_with_fields($order_id,'','');
    }
    /** Both classic and Store API use identical server-side payment validation and settlement. */
    public function process_payment_with_fields($order_id,$ref,$confirm) {
        if (!self::bnpl_checkout_allowed() || !self::valid_session()) {
            wc_add_notice(__('Complete E-TALK login and OTP verification before paying.','e-talk-merchant-gateways'),'error');
            return array('result'=>'failure');
        }
        $order=wc_get_order($order_id);
        if (!$order || $order->is_paid() || $order->get_payment_method()!==$this->id) return array('result'=>'failure');
        if ($order->get_currency()!=='USD' || (float)$order->get_total()<=0) {
            wc_add_notice(__('E-TALK BNPL requires a positive USD order total.', 'e-talk-merchant-gateways'),'error');return array('result'=>'failure');
        }
        $s=self::valid_session();
        if (!$s) {
            wc_add_notice(__('Verify your E-TALK account and six-digit OTP before paying.', 'e-talk-merchant-gateways'),'error');return array('result'=>'failure');
        }
        $order_amount=wc_format_decimal($order->get_total('edit'),wc_get_price_decimals());
        if (($s['eligible_currency']??'')!=='USD' || (string)($s['eligible_amount']??'')!==$order_amount ||
            (int)($s['eligible_at']??0)<time()-600) {
            wc_add_notice(__('Your cart total changed or BNPL eligibility expired. Check eligibility again.', 'e-talk-merchant-gateways'),'error');return array('result'=>'failure');
        }
        // The billing email belongs to the merchant checkout and may differ from
        // the verified E-TALK payer email. The bearer session, not billing email,
        // authenticates the payer to E-TALK. Never use billing email to choose payer.
        // Stable per-order reference; never supplied or editable by the browser.
        $ref='BNPLWC_'.strtoupper(substr(hash('sha256',home_url('/').'|'.$order->get_id()),0,16));
        $saved=(string)$order->get_meta('_etalk_bnpl_order_ref',true);
        if ($saved!=='' && $saved!==$ref) {wc_add_notice(__('This order is already bound to a different BNPL reference.', 'e-talk-merchant-gateways'),'error');return array('result'=>'failure');}
        if ((string)$order->get_meta('_etalk_bnpl_uncertain',true)==='yes') {
            wc_add_notice(__('Payment outcome requires reconciliation before retry.', 'e-talk-merchant-gateways'),'error');return array('result'=>'failure');
        }
        if (!$saved) {$order->update_meta_data('_etalk_bnpl_order_ref',$ref);$order->save();}
        $key=(string)$this->get_option('api_key');$secret=(string)$this->get_option('api_secret');
        if ($key===''||$secret==='') {wc_add_notice(__('Merchant API credentials are missing.', 'e-talk-merchant-gateways'),'error');return array('result'=>'failure');}
        $body=array('amount'=>$order_amount,'currency'=>'USD','order_reference'=>$ref);
        ksort($body);$body['signature']=hash_hmac('sha256',http_build_query($body),$secret);
        $r=wp_remote_post('https://e-talk.xyz/api/bnpl/v1/external-purchase',array(
            'timeout'=>35,'redirection'=>0,'sslverify'=>true,
            'headers'=>array('Content-Type'=>'application/json','Accept'=>'application/json','Cache-Control'=>'no-store',
              'Authorization'=>'Bearer '.$s['session'],'X-BNPL-API-KEY'=>$key),
            'body'=>wp_json_encode($body),
        ));unset($key,$secret,$body);
        if (is_wp_error($r)) {
            $order->update_meta_data('_etalk_bnpl_uncertain','yes');$order->save();
            $order->add_order_note('BNPL outcome unknown after transport error. Check E-TALK merchant ledger; do not retry.');
            wc_add_notice(__('Payment outcome unknown: reconcile E-TALK before retry.', 'e-talk-merchant-gateways'),'error');return array('result'=>'failure');
        }
        $http=(int)wp_remote_retrieve_response_code($r);
        $data=json_decode(wp_remote_retrieve_body($r),true);
        if (!is_array($data)) {
            $order->update_meta_data('_etalk_bnpl_uncertain','yes');$order->save();
            $order->add_order_note('BNPL response was unparseable. Verify merchant ledger before retry.');
            wc_add_notice(__('Payment response unknown; reconciliation required.', 'e-talk-merchant-gateways'),'error');return array('result'=>'failure');
        }
        if ($http===200 && ($data['status']??'')==='success' && ($data['order_reference']??'')===$ref &&
            in_array($data['authorization']??'',array('committed','existing'),true) &&
            (int)($data['merchant_transaction_id']??0)>0 && ($data['merchant_settlement']??'')==='pending_usdc') {
            $tx=(string)(int)$data['merchant_transaction_id'];
            $order->update_meta_data('_etalk_bnpl_transaction_id',$tx);
            $order->update_meta_data('_etalk_bnpl_settlement','pending_usdc');
            $order->save();
            $order->payment_complete('BNPL-'.$tx);
            $order->add_order_note('E-TALK BNPL merchant transaction '.$tx.' confirmed pending_usdc; reference '.$ref.'.');
            if (WC()->cart) WC()->cart->empty_cart();
            return array('result'=>'success','redirect'=>$this->get_return_url($order));
        }
        $code=sanitize_key((string)($data['code']??'remote_rejected'));
        if ($http===200 || $http>=500) {
            $order->update_meta_data('_etalk_bnpl_uncertain','yes');$order->save();
            $order->add_order_note('BNPL unexpected response; ledger reconciliation required.');
        }
        wc_add_notice(__('E-TALK BNPL not confirmed: ', 'e-talk-merchant-gateways').esc_html($code),'error');
        return array('result'=>'failure');
    }
}
