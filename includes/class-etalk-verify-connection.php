<?php
if (!defined('ABSPATH')) exit;
/** Read-only authenticated gateway connection test; no V1 financial endpoint may be probed. */
add_action('wp_ajax_etalk_verify_connection', function () {
    if (!current_user_can('manage_woocommerce') || !check_ajax_referer('etalk_verify_connection','nonce',false)) {
        wp_send_json_error(array('message'=>'Not authorized.'),403);
    }
    $channel = isset($_POST['channel']) ? sanitize_key(wp_unslash($_POST['channel'])) : '';
    if ($channel !== 'bnpl' && $channel !== 'v1') wp_send_json_error(array('message'=>'Unknown gateway.'),400);
    if ($channel === 'v1') {
        $options=get_option('woocommerce_etalk_v1_settings',array());
        $merchant_id=isset($options['merchant_id'])?(string)$options['merchant_id']:'';
        $key=isset($options['api_key'])?(string)$options['api_key']:'';
        if (!ctype_digit($merchant_id) || (int)$merchant_id<1 || strlen($key)!==32) {
            wp_send_json_error(array('message'=>'Save a valid V1 Merchant ID and Merchant API key before verifying.'),400);
        }
        $response=wp_remote_post('https://e-talk.xyz/api/v1-card/v1/status',array(
            'timeout'=>15,'redirection'=>0,'sslverify'=>true,
            'headers'=>array('Content-Type'=>'application/json','X-V1-API-KEY'=>$key,'Cache-Control'=>'no-store'),
            'body'=>wp_json_encode(array('merchant_id'=>(int)$merchant_id)),
        ));
        if (is_wp_error($response)) wp_send_json_error(array('message'=>'V1 connection failed (network or TLS error).'),502);
        $code=(int)wp_remote_retrieve_response_code($response);
        $data=json_decode(wp_remote_retrieve_body($response),true);
        if ($code===200 && is_array($data) && ($data['status']??'')==='connected'
            && (int)($data['merchant_id']??0)===(int)$merchant_id && ($data['service']??'')==='v1-card') {
            wp_send_json_success(array('message'=>'Connected: E-TALK V1 authenticated this merchant ID and API key. No payment or OTP operation was performed.'));
        }
        if ($code===401 || $code===403) wp_send_json_error(array('message'=>'V1 authentication rejected. Confirm the API key belongs to this Merchant ID.'),401);
        wp_send_json_error(array('message'=>'No verified V1 connection (HTTP '.$code.').'),502);
    }
    $options = get_option('woocommerce_etalk_bnpl_settings',array());
    $key = isset($options['api_key']) ? (string)$options['api_key'] : '';
    $secret = isset($options['api_secret']) ? (string)$options['api_secret'] : '';
    if ($key === '' || $secret === '') wp_send_json_error(array('message'=>'Save the BNPL merchant API key and API secret before verifying.'),400);
    // Mirrors native BNPL_API::generate_signature for an empty payload: HMAC SHA256 of empty string.
    $signature = hash_hmac('sha256','',$secret);
    $response=wp_remote_post('https://e-talk.xyz/api/bnpl/v1/status',array(
        'timeout'=>15,'redirection'=>0,'sslverify'=>true,
        'headers'=>array('Content-Type'=>'application/json','X-BNPL-API-KEY'=>$key,'Cache-Control'=>'no-store'),
        'body'=>wp_json_encode(array('signature'=>$signature)),
    ));
    if (is_wp_error($response)) wp_send_json_error(array('message'=>'Connection failed (network or TLS error).'),502);
    $code=(int)wp_remote_retrieve_response_code($response);
    $data=json_decode(wp_remote_retrieve_body($response),true);
    if ($code===200 && is_array($data) && isset($data['status']) && $data['status']==='connected') {
        wp_send_json_success(array('message'=>'Connected: E-TALK BNPL authenticated this merchant API key and signature. No financial operation was performed.'));
    }
    if ($code===401 || $code===403) wp_send_json_error(array('message'=>'Authentication rejected by E-TALK. Check the saved API key and secret.'),401);
    wp_send_json_error(array('message'=>'E-TALK did not return a confirmed BNPL connection (HTTP '.$code.').'),502);
});
