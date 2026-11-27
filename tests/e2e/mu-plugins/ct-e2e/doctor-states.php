<?php
/**
 * Tracking Doctor store states for the e2e specs, loaded by ct-e2e-helpers.php when a ct_e2e_doctor_checks or
 * ct_e2e_doctor_state cookie is set. Nothing is written to the database; the states apply only to REST requests.
 *
 * ct_e2e_doctor_checks: comma-separated IDs; Lite's other checks are left out (Pro's and the samples stay).
 * ct_e2e_doctor_state: comma-separated states:
 *   meta-plugin   Meta for WooCommerce active with its pixel on
 *   pixelyoursite PixelYourSite active
 *   gtm           GTM4WP active with a container
 *   bad-ids       a Meta access token as the Pixel ID and no Google Ads conversion label
 *   purchase-off  X's Purchase off
 *   repeat        one purchase handed over twice to Meta
 */

// phpcs:disable

$ct_e2e_list = static function ( $name ) {
    return isset( $_COOKIE[ $name ] ) ? array_filter( array_map( 'trim', explode( ',', sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) ) ) ) ) : array();
};

$ct_e2e_checks = $ct_e2e_list( 'ct_e2e_doctor_checks' );
$ct_e2e_states = $ct_e2e_list( 'ct_e2e_doctor_state' );

if ( $ct_e2e_checks ) {
    add_filter(
        'wcct_doctor_checks',
        static function ( $checks ) use ( $ct_e2e_checks ) {
            return array_values(
                array_filter(
                    $checks,
                    static function ( $check ) use ( $ct_e2e_checks ) {
                        if ( ! is_string( $check ) || 0 !== strpos( $check, 'StorePulse\\ConversionTracking\\Doctor\\Checks\\' ) || ! class_exists( $check ) ) {
                            return true;
                        }

                        return in_array( ( new $check() )->id(), $ct_e2e_checks, true );
                    }
                )
            );
        },
        99
    );
}

$ct_e2e_rest = static function () {
    return defined( 'REST_REQUEST' ) && REST_REQUEST;
};

$ct_e2e_plugins = array_intersect_key(
    array(
        'meta-plugin'   => 'facebook-for-woocommerce/facebook-for-woocommerce.php',
        'pixelyoursite' => 'pixelyoursite/pixelyoursite.php',
        'gtm'           => 'duracelltomi-google-tag-manager/duracelltomi-google-tag-manager-for-wordpress.php',
    ),
    array_flip( $ct_e2e_states )
);

if ( $ct_e2e_plugins ) {
    // Only during REST requests, after the real plugins have loaded, so WordPress never tries to load these.
    add_filter(
        'option_active_plugins',
        static function ( $active ) use ( $ct_e2e_plugins, $ct_e2e_rest ) {
            return $ct_e2e_rest() && is_array( $active ) ? array_values( array_unique( array_merge( $active, array_values( $ct_e2e_plugins ) ) ) ) : $active;
        }
    );
}

if ( in_array( 'meta-plugin', $ct_e2e_states, true ) ) {
    add_filter(
        'pre_option_wc_facebook_pixel_id',
        static function ( $pre ) use ( $ct_e2e_rest ) {
            return $ct_e2e_rest() ? '999999999999999' : $pre;
        }
    );
}

if ( in_array( 'gtm', $ct_e2e_states, true ) ) {
    add_filter(
        'pre_option_gtm4wp-options',
        static function ( $pre ) use ( $ct_e2e_rest ) {
            return $ct_e2e_rest() ? array( 'gtm-code' => 'GTM-CTE2E' ) : $pre;
        }
    );
}

if ( array_intersect( array( 'bad-ids', 'purchase-off' ), $ct_e2e_states ) ) {
    add_filter(
        'pre_option_wcct_settings_v3',
        static function ( $pre ) use ( $ct_e2e_states, $ct_e2e_rest ) {
            static $busy = false;

            if ( $busy || ! $ct_e2e_rest() || ! class_exists( '\StorePulse\ConversionTracking\Settings\Store' ) ) {
                return $pre;
            }

            $busy = true;
            $doc  = \StorePulse\ConversionTracking\Settings\Store::load();
            $busy = false;

            if ( in_array( 'bad-ids', $ct_e2e_states, true ) ) {
                $doc['platforms']['facebook']['pixel_id']     = 'EAAB123';
                $doc['platforms']['google']['purchase_label'] = '';
            }

            if ( in_array( 'purchase-off', $ct_e2e_states, true ) ) {
                $doc['platforms']['twitter']['events'] = array( 'page_view' );
            }

            return $doc;
        }
    );
}

if ( in_array( 'repeat', $ct_e2e_states, true ) ) {
    add_filter(
        'pre_option_wcct_order_log_repeats',
        static function ( $pre ) use ( $ct_e2e_rest ) {
            return $ct_e2e_rest() ? array( array( 'order_id' => 1, 'platform' => 'facebook', 'time' => time() - 60 ) ) : $pre;
        }
    );
}
