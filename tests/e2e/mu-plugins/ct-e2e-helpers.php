<?php
/**
 * Plugin Name: CT e2e helpers
 * Description: Test site only. Lets a test pick the checkout page and stops calls to wordpress.org.
 */

// phpcs:disable

// The ct_e2e_checkout=classic cookie makes the shortcode page the checkout page, so the order lands on its thank-you page.
add_filter(
    'woocommerce_get_checkout_page_id',
    static function ( $page_id ) {
        if ( isset( $_COOKIE['ct_e2e_checkout'] ) && 'classic' === $_COOKIE['ct_e2e_checkout'] ) {
            $page = get_page_by_path( 'classic-checkout' );

            if ( $page ) {
                return $page->ID;
            }
        }

        return $page_id;
    }
);

// wp-env has no mail server; report mail as sent instead of failing on it.
add_filter( 'pre_wp_mail', '__return_true' );

// No update checks: they slow down a fresh site and nothing here needs wordpress.org.
add_filter( 'automatic_updater_disabled', '__return_true' );
remove_action( 'admin_init', '_maybe_update_core' );
remove_action( 'admin_init', '_maybe_update_plugins' );
remove_action( 'admin_init', '_maybe_update_themes' );

add_filter(
    'pre_http_request',
    static function ( $pre, $args, $url ) {
        $host = (string) wp_parse_url( $url, PHP_URL_HOST );

        if ( '' !== $host && false !== strpos( $host, 'wordpress.org' ) ) {
            return new WP_Error( 'ct_e2e_http_blocked', 'wordpress.org HTTP disabled on the e2e site' );
        }

        return $pre;
    },
    10,
    3
);
