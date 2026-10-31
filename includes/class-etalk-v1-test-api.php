<?php
if (!defined('ABSPATH')) exit;
final class ETalk_V1_Test_API {
 public static function call($route,$merchant_id,$key,$body) {
  if (!in_array($route,array('request-payment-otp','process-payment'),true) || !ctype_digit((string)$merchant_id) || (int)$merchant_id<1 || strlen((string)$key)!==32) return array('http'=>0,'data'=>array('code'=>'merchant_config_invalid'));
  $payload=array_merge(array('merchant_id'=>(int)$merchant_id),$body);
  $r=wp_remote_post('https://e-talk.xyz/api/v1-card/v1/'.$route,array(
   'timeout'=>20,'redirection'=>0,'sslverify'=>true,
   'headers'=>array('Content-Type'=>'application/json','X-V1-API-KEY'=>(string)$key,'Cache-Control'=>'no-store'),
   'body'=>wp_json_encode($payload)
  ));
  if (is_wp_error($r)) return array('http'=>0,'data'=>array('code'=>'network_error'));
  $data=json_decode(wp_remote_retrieve_body($r),true);
  return array('http'=>(int)wp_remote_retrieve_response_code($r),'data'=>is_array($data)?$data:array('code'=>'invalid_response'));
 }
 public static function request_otp() {
  if (!ETalk_Merchant_Gateway_Base::v1_ready() || !check_ajax_referer('etalk_v1_checkout_test','nonce',false)) wp_send_json_error(array('code'=>'forbidden'),403);
  $wallet=isset($_POST['wallet'])?sanitize_text_field(wp_unslash($_POST['wallet'])):'';
  $ccv=isset($_POST['ccv'])?sanitize_text_field(wp_unslash($_POST['ccv'])):'';
  $order_id=isset($_POST['order_id'])?absint($_POST['order_id']):0;
  $order=$order_id?wc_get_order($order_id):null;
  if (!$order || $order->is_paid() || $order->get_payment_method() !== 'etalk_v1' || !in_array($order->get_status(),array('pending','failed','on-hold'),true) || !hash_equals((string)$order->get_order_key(),(string)(isset($_POST['order_key'])?sanitize_text_field(wp_unslash($_POST['order_key'])):'')) || !preg_match('/^0x[a-fA-F0-9]{40}$/D',$wallet)||!preg_match('/^[0-9]{3}$/D',$ccv)) wp_send_json_error(array('code'=>'invalid_request'),400);
  $options=get_option('woocommerce_etalk_v1_settings',array());
  $r=self::call('request-payment-otp',$options['merchant_id']??'',$options['api_key']??'',array(
   'card_number'=>$wallet,'ccv'=>$ccv,'amount'=>(string)$order->get_total(),'currency'=>$order->get_currency(),'reference'=>'WC'.$order->get_id().'V1'
  ));
  if ($r['http']===200 && ($r['data']['status']??'')==='otp_sent') wp_send_json_success(array('code'=>'otp_sent'));
  wp_send_json_error(array('code'=>$r['data']['code']??'request_failed'),$r['http']>=400&&$r['http']<600?$r['http']:502);
 }
}
add_action('wp_ajax_etalk_v1_request_otp',array('ETalk_V1_Test_API','request_otp'));
add_action('wp_ajax_nopriv_etalk_v1_request_otp',array('ETalk_V1_Test_API','request_otp'));
