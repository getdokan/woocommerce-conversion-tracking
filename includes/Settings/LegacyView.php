<?php

namespace StorePulse\ConversionTracking\Settings;

use StorePulse\ConversionTracking\Platforms\XSettings;

/**
 * Reads the 2.x wcct_settings option as 3.0 settings, until the migration (#122) has written them.
 */
class LegacyView {

    /**
     * Filter Pro uses to add what it saved in 2.x.
     */
    const FILTER = 'wcct_settings_v1_view';

    /**
     * 2.x Meta event keys => platform events.
     */
    const FACEBOOK_EVENTS = array(
        'ViewContent'      => 'view_product',
        'AddToCart'        => 'add_to_cart',
        'InitiateCheckout' => 'begin_checkout',
        'Purchase'         => 'purchase',
        'Search'           => 'search',
        'Registration'     => 'sign_up',
        'ViewCategory'     => 'view_category',
        'AddToWishlist'    => 'add_to_wishlist',
    );

    /**
     * 3.0 settings built from the 2.x option; platforms 2.x never saved are left out.
     *
     * @param mixed $v1 The wcct_settings option.
     *
     * @return array
     */
    public static function from_v1( $v1 ): array {
        $v1        = is_array( $v1 ) ? $v1 : array();
        $platforms = array();

        $facebook = self::first( $v1, 'facebook' );

        if ( null !== $facebook ) {
            $platforms['facebook'] = array(
                'pixel_id' => self::text( $facebook, 'pixel_id' ),
                'events'   => Google::v1_events( $facebook, self::FACEBOOK_EVENTS ),
            );

            $platforms['facebook']['paused'] = self::paused( $v1['facebook'], $platforms['facebook']['pixel_id'] );
        }

        $google = Google::from_v1( isset( $v1['ga4'] ) ? $v1['ga4'] : null, isset( $v1['adwords'] ) ? $v1['adwords'] : null );

        if ( $google ) {
            $platforms['google'] = $google;
        }

        if ( null !== self::first( $v1, 'twitter' ) ) {
            // 2.x labels are not 3.0 event IDs; they read as needs_resetup instead.
            $platforms['twitter']           = XSettings::from_v1( $v1['twitter'] );
            $platforms['twitter']['paused'] = self::paused( $v1['twitter'], $platforms['twitter']['pixel_id'] );
        }

        if ( isset( $v1['custom'] ) && is_array( $v1['custom'] ) ) {
            $custom  = $v1['custom'];
            $enabled = Google::enabled( $custom );

            $platforms['custom'] = array(
                'thankyou_enabled' => $enabled && '' !== self::raw( $custom, 'checkout' ),
                'thankyou_script'  => self::raw( $custom, 'checkout' ),
                'signup_enabled'   => $enabled && '' !== self::raw( $custom, 'registration' ),
                'signup_script'    => self::raw( $custom, 'registration' ),
            );
        }

        $view = $platforms ? array( 'platforms' => $platforms ) : array();

        /**
         * Filter the 3.0 view of the 2.x settings.
         *
         * @since 3.0.0
         *
         * @param array $view 3.0 settings: { platforms: id => values }.
         * @param array $v1   The wcct_settings option.
         */
        $view = apply_filters( self::FILTER, $view, $v1 );

        return is_array( $view ) ? $view : array();
    }

    /**
     * A 2.x integration switched off with its ID saved stays off in 3.0 as paused.
     *
     * Read-only in the API for now; #103 and #122 persist it in wcct_paused_platforms.
     *
     * @param array  $integration 2.x settings.
     * @param string $id          Its saved ID.
     *
     * @return bool
     */
    public static function paused( array $integration, string $id ): bool {
        return '' !== $id && ! Google::enabled( $integration );
    }

    /**
     * Index 0 of a 2.x integration, null when it was never saved.
     *
     * @param array  $v1 The wcct_settings option.
     * @param string $id Integration ID.
     *
     * @return array|null
     */
    public static function first( array $v1, string $id ): ?array {
        if ( ! isset( $v1[ $id ] ) || ! is_array( $v1[ $id ] ) ) {
            return null;
        }

        return isset( $v1[ $id ][0] ) && is_array( $v1[ $id ][0] ) ? $v1[ $id ][0] : array();
    }

    /**
     * A trimmed string value, or an empty string.
     *
     * @param array  $values Values.
     * @param string $key    Key.
     *
     * @return string
     */
    private static function text( array $values, string $key ): string {
        return trim( self::raw( $values, $key ) );
    }

    /**
     * A string value as saved, or an empty string.
     *
     * @param array  $values Values.
     * @param string $key    Key.
     *
     * @return string
     */
    private static function raw( array $values, string $key ): string {
        return isset( $values[ $key ] ) && is_scalar( $values[ $key ] ) ? (string) $values[ $key ] : '';
    }
}
