<?php
if (!defined('ABSPATH')) exit;
final class ETalk_BNPL_Auth_Check {
    const ROOT='https://e-talk.xyz/api/bnpl/v1/';
    private static function authorized() { return check_ajax_referer('etalk_bnpl_auth_check', 'nonce', false) && self::shop_session() !== null; }
    private static function shop_session() {
        if (!function_exists('WC')) return null;
        $wc=WC();
        if (!$wc->session && method_exists($wc,'initialize_session')) $wc->initialize_session();
        if (!$wc->session) return null;
        return $wc->session;
    }
    private static function session_key() { return 'etalk_bnpl_checkout_identity'; }
    public static function read_identity() {
        $wc=self::shop_session();
        $s=$wc ? $wc->get(self::session_key()) : null;
        if (!is_array($s) || !preg_match('/^[a-f0-9]{64}$/D',(string)($s['session']??'')) ||
            (int)($s['verified_at']??0)<time()-600 || (int)($s['verified_at']??0)>time()) return null;
        return $s;
    }
    public static function delete_identity() { $wc=self::shop_session(); if($wc) $wc->__unset(self::session_key()); }
    private static function fail($code, $http=400) { wp_send_json_error(array('code'=>$code), $http); }
    public static function register() {
        add_action('wp_ajax_etalk_bnpl_auth_start', array(__CLASS__,'start'));
        add_action('wp_ajax_etalk_bnpl_auth_verify', array(__CLASS__,'verify'));
        add_action('wp_ajax_etalk_bnpl_eligibility_check', array(__CLASS__,'eligibility'));
        add_action('wp_ajax_nopriv_etalk_bnpl_auth_start',array(__CLASS__,'start'));
        add_action('wp_ajax_nopriv_etalk_bnpl_auth_verify',array(__CLASS__,'verify'));
        add_action('wp_ajax_nopriv_etalk_bnpl_eligibility_check',array(__CLASS__,'eligibility'));
    }
    private static function remote($route, $payload) {
        $r=wp_remote_post(self::ROOT . $route,array('timeout'=>20,'redirection'=>0,'sslverify'=>true,'headers'=>array('Content-Type'=>'application/json','Accept'=>'application/json'),'body'=>wp_json_encode($payload)));
        if(is_wp_error($r)) return array('ok'=>false,'code'=>'connection_failed');
        $code=wp_remote_retrieve_response_code($r);
        $data=json_decode(wp_remote_retrieve_body($r),true);
        if(!is_array($data)) return array('ok'=>false,'code'=>'invalid_response');
        return array('ok'=>$code===200 && ($data['status']??'')==='success','code'=>sanitize_key((string)($data['code']??'request_failed')),'data'=>$data);
    }
    public static function start() {
        if(!self::authorized()) self::fail('forbidden',403);
        // Authorized above via check_ajax_referer(). Passwords are intentionally not sanitized: changing characters breaks authentication.
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified by authorized().
        $email=isset($_POST['email']) && is_string($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Password must be delivered unchanged to E-TALK.
        $password=isset($_POST['password']) && is_string($_POST['password']) ? wp_unslash($_POST['password']) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        if(!is_email($email)||$password==='') self::fail('missing_credentials');
        self::delete_identity();
        $r=self::remote('verify-user',array('email'=>$email,'password'=>$password));unset($password);
        if(!$r['ok']) self::fail($r['code'],401);
        $token=(string)($r['data']['auth_token']??'');
        if(strlen($token)<24) self::fail('invalid_auth_response',502);
        $wc=self::shop_session(); $wc->set(self::session_key(),array('token'=>$token,'email'=>$email,'started'=>time())); if (method_exists($wc,'set_customer_session_cookie')) $wc->set_customer_session_cookie(true);
        wp_send_json_success(array('stage'=>'otp_sent','expires_in'=>900));
    }
    public static function verify() {
        if(!self::authorized()) self::fail('forbidden',403);
        $state=self::shop_session()->get(self::session_key());
        if(!is_array($state)||empty($state['token'])) self::fail('session_expired',401);
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above via authorized().
        $otp=isset($_POST['otp']) && is_string($_POST['otp']) ? sanitize_text_field(wp_unslash($_POST['otp'])) : '';
        if(!preg_match('/^[0-9]{6}$/D',$otp)) self::fail('invalid_otp_format');
        $r=self::remote('verify-otp',array('auth_token'=>$state['token'],'otp'=>$otp));unset($otp);
        if(!$r['ok']) self::fail($r['code'],401);
        $session=(string)($r['data']['session_token']??'');
        if(!preg_match('/^[a-f0-9]{64}$/D',$session)) { self::delete_identity(); self::fail('invalid_session_response',502); }
        // Staff-only short-lived server transient. Never expose the session token to browser or logs.
        self::shop_session()->set(self::session_key(),array('session'=>$session,'verified_at'=>time(),'email'=>$state['email']));
        wp_send_json_success(array('stage'=>'identity_verified','message'=>'Customer authentication and OTP succeeded. Check eligibility below. No C-Lock or payment created.'));
    }
    public static function eligibility() {
        if(!self::authorized()) self::fail('forbidden',403);
        $state=self::shop_session()->get(self::session_key());
        if(!is_array($state)||!preg_match('/^[a-f0-9]{64}$/D',(string)($state['session']??''))) self::fail('verify_otp_first',401);
        if (!WC()->cart) wc_load_cart();
        $cart=WC()->cart;
        if (!$cart || $cart->is_empty()) self::fail('cart_unavailable',400);
        $amount=wc_format_decimal($cart->get_total('edit'),wc_get_price_decimals());
        if (get_woocommerce_currency() !== 'USD') self::fail('currency_unsupported',400);
        if(!preg_match('/^[0-9]{1,9}(?:\.[0-9]{1,2})?$/D',$amount) || (float)$amount<=0) self::fail('invalid_amount');
        $r=wp_remote_post(self::ROOT.'check-eligibility',array(
            'timeout'=>20,'redirection'=>0,'sslverify'=>true,
            'headers'=>array('Content-Type'=>'application/json','Accept'=>'application/json','Authorization'=>'Bearer '.$state['session'],'Cache-Control'=>'no-store'),
            'body'=>wp_json_encode(array('amount'=>(float)$amount))
        ));
        if(is_wp_error($r)) self::fail('connection_failed',502);
        $http=(int)wp_remote_retrieve_response_code($r);
        $data=json_decode(wp_remote_retrieve_body($r),true);
        if(!is_array($data)) self::fail('invalid_response',502);
        if($http!==200 || ($data['status']??'')!=='success') self::fail(sanitize_key((string)($data['code']??'eligibility_unavailable')), $http>=400&&$http<600?$http:502);
        if (!empty($data['eligible'])) {
            $state['eligible_amount']=$amount;
            $state['eligible_currency']='USD';
            $state['eligible_at']=time();
            self::shop_session()->set(self::session_key(),$state);
        }
        wp_send_json_success(array(
            'amount'=>$amount,
            'eligible'=>!empty($data['eligible']),
            'reason'=>sanitize_key((string)($data['reason']??'unknown')),
            'message'=>!empty($data['eligible'])?'Eligible for this amount under E-TALK BNPL rules. No funds locked.':'Not eligible for this amount: '.sanitize_key((string)($data['reason']??'unknown')).'. No funds locked.'
        ));
    }
}
