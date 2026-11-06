<?php

namespace StorePulse\ConversionTracking\Privacy;

use StorePulse\ConversionTracking\Settings\Store;

/**
 * The customer placeholders of the Custom script, filled only when "Include customer details in scripts" allows it.
 */
class CustomerDetails {

    /**
     * Custom script setting in wcct_settings_v3 (platforms.custom), off by default.
     */
    const SETTING = 'customer_details';

    /**
     * Platform the setting belongs to.
     */
    const PLATFORM = 'custom';

    /**
     * The "Respect cookie choices" option, 'on' or 'off'.
     */
    const CONSENT_OPTION = 'wcct_consent_mode';

    /**
     * WP Consent API category the values wait for.
     */
    const CONSENT_CATEGORY = 'marketing';

    /**
     * Placeholders this setting controls.
     */
    const PLACEHOLDERS = array( '{customer_id}', '{customer_email}', '{customer_first_name}', '{customer_last_name}' );

    /**
     * Whether "Include customer details in scripts" is on.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        $doc = Store::load();

        return ! empty( $doc['platforms'][ self::PLATFORM ][ self::SETTING ] );
    }

    /**
     * Placeholder => value for an order; every value is empty unless the order may share its customer's details.
     *
     * @param \WC_Order|mixed $order    Order being rendered.
     * @param \WC_Order|false $verified The order received page's verified order, from OrderReceived::get_order().
     *
     * @return string[]
     */
    public static function values( $order, $verified ): array {
        $values = array_fill_keys( self::PLACEHOLDERS, '' );
        $user   = self::allowed( $order, $verified ) ? $order->get_user() : false;

        if ( ! $user ) {
            return $values;
        }

        return array_combine(
            self::PLACEHOLDERS,
            array_map( array( __CLASS__, 'js_string' ), array( $user->ID, $user->user_email, $user->first_name, $user->last_name ) )
        );
    }

    /**
     * A value escaped for a JavaScript string in quotes or backticks, without the quotes; it can't close the script tag.
     *
     * @param mixed $value Value.
     *
     * @return string
     */
    public static function js_string( $value ): string {
        $json = wp_json_encode( (string) $value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

        // Backtick and $ too, so a value can't end or interpolate in a template literal.
        return is_string( $json ) ? str_replace( array( '`', '$' ), array( '\\x60', '\\x24' ), substr( $json, 1, -1 ) ) : '';
    }

    /**
     * Setting on, the verified order received page of this order, its customer logged in, and consent when consent mode is on.
     *
     * @param \WC_Order|mixed $order    Order being rendered.
     * @param \WC_Order|false $verified Verified order of the order received page.
     *
     * @return bool
     */
    public static function allowed( $order, $verified ): bool {
        if ( ! self::is_enabled() || ! self::is_order( $order ) || ! self::is_order( $verified ) ) {
            return false;
        }

        $customer_id = (int) $order->get_customer_id();

        // Stricter than WooCommerce's woocommerce_order_received_verify_known_shoppers: always the customer's own session.
        if ( $order->get_id() !== $verified->get_id() || ! $customer_id || get_current_user_id() !== $customer_id ) {
            return false;
        }

        // Checked on the server on purpose: the thank-you page isn't cached, and the values are never printed without consent.
        $allowed = ! self::consent_mode_on() || self::has_marketing_consent();

        /**
         * Whether the Custom script may receive the customer's ID, email and name for this order.
         *
         * @since 3.0.0
         *
         * @param bool      $allowed Setting, ownership and consent checks passed.
         * @param \WC_Order $order   Order.
         */
        return (bool) apply_filters( 'wcct_customer_details_allowed', $allowed, $order );
    }

    /**
     * Whether a value has the order getters this class reads.
     *
     * @param mixed $order Value.
     *
     * @return bool
     */
    private static function is_order( $order ): bool {
        return is_object( $order ) && method_exists( $order, 'get_id' ) && method_exists( $order, 'get_customer_id' ) && method_exists( $order, 'get_user' );
    }

    /**
     * Whether "Respect cookie choices" is on.
     *
     * @return bool
     */
    public static function consent_mode_on(): bool {
        return \StorePulse\ConversionTracking\Consent\ConsentMode::is_enabled();
    }

    /**
     * Whether the visitor gave marketing consent; true when no consent plugin sets a consent type.
     *
     * @return bool
     */
    public static function has_marketing_consent(): bool {
        if ( function_exists( 'wcct_consent_allows' ) ) {
            return (bool) wcct_consent_allows( self::CONSENT_CATEGORY );
        }

        return function_exists( 'wp_has_consent' ) ? (bool) wp_has_consent( self::CONSENT_CATEGORY ) : true;
    }
}
