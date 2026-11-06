<?php
/**
 * Set-up for CustomerDetailsTest, run before the page: a consent plugin's answer and WooCommerce's known-shopper check.
 */

// phpcs:disable

global $wcct_job;

// A consent plugin through the WP Consent API, with the visitor's marketing choice.
if ( isset( $wcct_job['marketing_consent'] ) && ! function_exists( 'wp_has_consent' ) ) {
    function wp_has_consent( $category ) {
        return 'marketing' === $category ? (bool) $GLOBALS['wcct_job']['marketing_consent'] : true;
    }

    function wp_get_consent_type() {
        return 'optin';
    }
}

// Shows a registered customer's order to anyone with the key, as some stores do.
if ( ! empty( $wcct_job['trust_known_shoppers'] ) ) {
    add_filter( 'woocommerce_order_received_verify_known_shoppers', '__return_false' );
}
