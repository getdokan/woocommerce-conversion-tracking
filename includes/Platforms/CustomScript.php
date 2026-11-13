<?php

namespace StorePulse\ConversionTracking\Platforms;

use StorePulse\ConversionTracking\Privacy\CustomerDetails;
use StorePulse\ConversionTracking\Settings\LegacyView;
use StorePulse\ConversionTracking\Settings\Store;

/**
 * The Custom script's placements and placeholders.
 */
class CustomScript {

    /**
     * Platform ID in the 3.0 settings.
     */
    const PLATFORM = 'custom';

    /**
     * Thank-you page, once per order.
     */
    const THANKYOU = 'thankyou';

    /**
     * Every front-end page, in the header.
     */
    const HEADER = 'header';

    /**
     * The first page view after a new account.
     */
    const SIGNUP = 'signup';

    /**
     * Placements, each with `<placement>_enabled` and `<placement>_script` in the settings.
     */
    const PLACEMENTS = array( self::THANKYOU, self::HEADER, self::SIGNUP );

    /**
     * Placements the 2.x settings page saves, as its checkout and registration scripts.
     */
    const V1_PLACEMENTS = array( self::THANKYOU, self::SIGNUP );

    /**
     * Order placeholders; `{order_id}` and `{used_coupons}` are kept from 2.x.
     */
    const ORDER_PLACEHOLDERS = array( '{order_number}', '{order_total}', '{order_subtotal}', '{order_discount}', '{order_shipping}', '{currency}', '{payment_method}', '{order_id}', '{used_coupons}' );

    /**
     * Each placement's switch and script, from the stored settings.
     *
     * @return array placement => { enabled: bool, script: string }
     */
    public static function current(): array {
        return self::settings( Store::load() );
    }

    /**
     * Each placement's switch and script; thank-you and sign-up come from the 2.x scripts while 3.0 holds none for them.
     *
     * @param array         $doc 3.0 settings.
     * @param callable|null $v1  Returns the wcct_settings option; read only when needed.
     *
     * @return array placement => { enabled: bool, script: string }
     */
    public static function settings( array $doc, ?callable $v1 = null ): array {
        $custom   = self::custom_of( $doc );
        $legacy   = null;
        $settings = array();

        foreach ( self::PLACEMENTS as $placement ) {
            $values = $custom;

            if ( in_array( $placement, self::V1_PLACEMENTS, true ) && ! self::has_placement( $custom, $placement ) ) {
                if ( null === $legacy ) {
                    $legacy = self::custom_of( LegacyView::from_v1( call_user_func( $v1 ? $v1 : array( __CLASS__, 'v1_option' ) ) ) );
                }

                $values = $legacy;
            }

            $script = isset( $values[ $placement . '_script' ] ) && is_string( $values[ $placement . '_script' ] ) ? $values[ $placement . '_script' ] : '';

            $settings[ $placement ] = array(
                'enabled' => ! empty( $values[ $placement . '_enabled' ] ),
                'script'  => $script,
            );
        }

        return $settings;
    }

    /**
     * A placement's script when it is on and not empty, else an empty string.
     *
     * @param array  $settings  From settings().
     * @param string $placement Placement.
     *
     * @return string
     */
    public static function script( array $settings, string $placement ): string {
        if ( empty( $settings[ $placement ]['enabled'] ) || ! isset( $settings[ $placement ]['script'] ) || '' === trim( $settings[ $placement ]['script'] ) ) {
            return '';
        }

        return $settings[ $placement ]['script'];
    }

    /**
     * Whether any placement has a script to print.
     *
     * @param array $settings From settings().
     *
     * @return bool
     */
    public static function has_script( array $settings ): bool {
        foreach ( self::PLACEMENTS as $placement ) {
            if ( '' !== self::script( $settings, $placement ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Order placeholder => value, escaped for a JavaScript string.
     *
     * @param \WC_Order|object $order Order with the WC_Order getters.
     *
     * @return string[]
     */
    public static function order_values( $order ): array {
        $coupons = $order->get_coupon_codes();
        $number  = $order->get_order_number();

        $values = array(
            '{order_number}'   => $number,
            '{order_total}'    => $order->get_total(),
            '{order_subtotal}' => $order->get_subtotal(),
            '{order_discount}' => $order->get_total_discount(),
            '{order_shipping}' => $order->get_shipping_total(),
            '{currency}'       => $order->get_currency(),
            '{payment_method}' => $order->get_payment_method(),
            '{order_id}'       => $number,
            '{used_coupons}'   => implode( ',', is_array( $coupons ) ? $coupons : array() ),
        );

        return array_map( array( CustomerDetails::class, 'js_string' ), $values );
    }

    /**
     * Replace every placeholder in one pass; any without a value becomes empty.
     *
     * @param string $script Merchant script.
     * @param array  $values Placeholder => value.
     *
     * @return string
     */
    public static function fill( string $script, array $values = array() ): string {
        $map = array_fill_keys( array_merge( self::ORDER_PLACEHOLDERS, CustomerDetails::PLACEHOLDERS ), '' );

        foreach ( array_intersect_key( $values, $map ) as $placeholder => $value ) {
            $map[ $placeholder ] = is_scalar( $value ) ? (string) $value : '';
        }

        // strtr() never replaces inside a value it has put in, so a coupon code can't pull in another placeholder.
        return strtr( $script, $map );
    }

    /**
     * The wcct_settings option.
     *
     * @return mixed
     */
    public static function v1_option() {
        return get_option( Store::V1_OPTION, array() );
    }

    /**
     * The Custom platform's stored values.
     *
     * @param array $doc 3.0 settings.
     *
     * @return array
     */
    private static function custom_of( array $doc ): array {
        return isset( $doc['platforms'][ self::PLATFORM ] ) && is_array( $doc['platforms'][ self::PLATFORM ] ) ? $doc['platforms'][ self::PLATFORM ] : array();
    }

    /**
     * Whether the stored values hold a key of the placement.
     *
     * @param array  $custom    Custom platform values.
     * @param string $placement Placement.
     *
     * @return bool
     */
    private static function has_placement( array $custom, string $placement ): bool {
        return array_key_exists( $placement . '_enabled', $custom ) || array_key_exists( $placement . '_script', $custom );
    }
}
