<?php
if (!defined('ABSPATH')) exit;
final class ETalk_Checkout_Preview {
    // Default merchant payment logo; merchants may configure an alternative.
    const DEFAULT_LOGO = 'https://e-talk.xyz/etalk-static/theme/etalk-native-marketplace/assets/brand/r4e242-small-6f4c6864de430850c4a6974862e6fc23-160.webp';
    const LEGACY_LOGO = 'https://e-talk.xyz/wp-content/uploads/2025/01/cropped-e-talk-logo32-x-32-px-1.webp';
    public static function normalize_logo($value) {
        // Replace ONLY the superseded E-TALK default, never a merchant-selected logo.
        if ($value === '' || $value === self::LEGACY_LOGO) return self::DEFAULT_LOGO;
        return $value;
    }
    public static function setting($channel, $key, $fallback) {
        $opts = get_option('woocommerce_etalk_' . $channel . '_settings', array());
        $value = is_array($opts) && isset($opts[$key]) && is_scalar($opts[$key]) ? (string) $opts[$key] : $fallback;
        return $key === 'logo_url' ? self::normalize_logo($value) : $value;
    }
}
