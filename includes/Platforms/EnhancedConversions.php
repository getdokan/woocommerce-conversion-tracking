<?php

namespace StorePulse\ConversionTracking\Platforms;

use StorePulse\ConversionTracking\Consent\ConsentMode;
use StorePulse\ConversionTracking\Events\UserData;

/**
 * Google Ads enhanced conversions (Pro): the verified order's hashed email and phone, set on the Google tag before the conversion.
 */
class EnhancedConversions {

    /**
     * WP Consent API category of Google's ad_user_data.
     */
    const CONSENT_CATEGORY = 'marketing';

    /**
     * Hashed user_data for the conversion of an order, only when it is the verified order of the order received page.
     *
     * @param mixed $order    Order the conversion is for.
     * @param mixed $verified The order received page's verified order (OrderReceived::get_order()), or false.
     *
     * @return array { sha256_email_address?, sha256_phone_number? }
     */
    public static function user_data( $order, $verified ): array {
        if ( ! is_object( $order ) || ! is_object( $verified ) || ! method_exists( $order, 'get_id' ) || ! method_exists( $verified, 'get_id' ) ) {
            return array();
        }

        $id = (int) $order->get_id();

        if ( $id <= 0 || (int) $verified->get_id() !== $id ) {
            return array();
        }

        return UserData::google_from_order( $verified );
    }

    /**
     * The user_data call; with consent mode on it runs only while marketing consent (ad_user_data) is given.
     *
     * @param string $set_js The gtag('set', 'user_data', ...) call.
     *
     * @return string
     */
    public static function gate( string $set_js ): string {
        if ( '' === trim( $set_js ) || ! ConsentMode::is_enabled() ) {
            return $set_js;
        }

        // No "&&", as in ConsentMode; a missing runtime sets nothing.
        return 'if (window.wcctConsent) { if (window.wcctConsent.allows(' . wp_json_encode( self::CONSENT_CATEGORY ) . ')) { ' . $set_js . ' } }';
    }

    /**
     * Keep the page out of page caches: it carries one shopper's hashed details.
     *
     * @return void
     */
    public static function skip_page_cache() {
        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }
    }
}
