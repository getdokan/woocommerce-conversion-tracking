<?php

namespace StorePulse\ConversionTracking\Events;

/**
 * Wishlist adds from YITH WooCommerce Wishlist and WooCommerce Wishlists, sent through the visitor's pending events.
 */
class Wishlist {

    /**
     * jQuery events after which the browser asks for pending events: YITH's PHP templates, then its React components.
     */
    const TRIGGERS = array( 'added_to_wishlist', 'yith_wcwl_reload_fragments' );

    /**
     * Whether a supported wishlist plugin is active.
     *
     * @return bool
     */
    public static function active(): bool {
        $active = defined( 'YITH_WCWL' ) || class_exists( 'WC_Wishlists_Plugin' );

        /**
         * Whether a wishlist plugin whose adds are tracked is active.
         *
         * @since 3.0.0
         *
         * @param bool $active Whether YITH WooCommerce Wishlist or WooCommerce Wishlists is active.
         */
        return (bool) apply_filters( 'wcct_wishlist_active', $active );
    }

    /**
     * Product added, from WooCommerce Wishlists' woocommerce_wishlist_add_item arguments: the variation when there is one.
     *
     * @param mixed $product_id   Product ID.
     * @param mixed $variation_id Variation ID.
     *
     * @return int
     */
    public static function product_id( $product_id, $variation_id = 0 ): int {
        if ( is_numeric( $variation_id ) && (int) $variation_id > 0 ) {
            return (int) $variation_id;
        }

        return is_numeric( $product_id ) && (int) $product_id > 0 ? (int) $product_id : 0;
    }
}
