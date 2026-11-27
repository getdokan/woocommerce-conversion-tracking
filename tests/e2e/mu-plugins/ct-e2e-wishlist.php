<?php
/**
 * Plugin Name: CT e2e wishlists
 * Description: Test site only. Picks YITH WooCommerce Wishlist's rendering method per browser and stands in for WooCommerce Wishlists, which is not free to download.
 */

// phpcs:disable

// The ct_e2e_yith_rendering cookie (php-templates or react-components) picks YITH's rendering method for this browser.
add_filter(
    'yith_wcwl_rendering_method',
    static function ( $method ) {
        $cookie = isset( $_COOKIE['ct_e2e_yith_rendering'] ) ? $_COOKIE['ct_e2e_yith_rendering'] : '';

        return in_array( $cookie, array( 'php-templates', 'react-components' ), true ) ? $cookie : $method;
    }
);

// With the ct_e2e_wc_wishlists cookie, WooCommerce Wishlists is "active": /?ct_e2e_wishlist_add=<product>[&variation=<id>]
// fires its woocommerce_wishlist_add_item action with its arguments and redirects back to the product, as its form post does.
if ( isset( $_COOKIE['ct_e2e_wc_wishlists'] ) && ! class_exists( 'WC_Wishlists_Plugin' ) ) {
    class WC_Wishlists_Plugin {}

    add_action(
        'wp_loaded',
        static function () {
            if ( ! isset( $_GET['ct_e2e_wishlist_add'] ) ) {
                return;
            }

            $product_id   = absint( $_GET['ct_e2e_wishlist_add'] );
            $variation_id = isset( $_GET['variation'] ) ? absint( $_GET['variation'] ) : 0;

            do_action( 'woocommerce_wishlist_add_item', md5( $product_id . ':' . $variation_id ), $product_id, 1, $variation_id, array(), array(), 1 );

            wp_safe_redirect( add_query_arg( 'wlm', uniqid(), get_permalink( $product_id ) ) );
            exit;
        }
    );
}
