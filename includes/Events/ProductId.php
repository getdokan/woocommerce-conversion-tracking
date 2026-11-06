<?php

namespace StorePulse\ConversionTracking\Events;

use StorePulse\ConversionTracking\Settings\Store;

/**
 * The product identifier sent to a platform and written to the catalog feed: the product ID or its SKU.
 */
class ProductId {

    /**
     * Send the WooCommerce product ID; variations send their own ID.
     */
    const ID = 'id';

    /**
     * Send the SKU, the product ID when the SKU is empty.
     */
    const SKU = 'sku';

    /**
     * Field ID under wcct_settings_v3 platforms.<platform>, and key in the wcct_data_feed option.
     */
    const FIELD = 'product_id_mode';

    /**
     * Option holding the catalog feed settings (Pro).
     */
    const FEED_OPTION = 'wcct_data_feed';

    /**
     * Integration ID => settings platform ID, where they differ.
     */
    const PLATFORMS = array(
        'ga4'     => 'google',
        'adwords' => 'google',
    );

    /**
     * Every mode.
     *
     * @return string[]
     */
    public static function modes() {
        return array( self::ID, self::SKU );
    }

    /**
     * Whether a value is a known mode.
     *
     * @param mixed $mode Raw mode.
     *
     * @return bool
     */
    public static function is_mode( $mode ) {
        return is_string( $mode ) && in_array( $mode, self::modes(), true );
    }

    /**
     * The mode a platform sends, from wcct_settings_v3; ID by default.
     *
     * @param string $platform Platform or integration ID.
     *
     * @return string
     */
    public static function mode_for( $platform ) {
        $platform = self::platform( $platform );
        $settings = get_option( Store::OPTION, null );
        $stored   = is_array( $settings ) && isset( $settings['platforms'][ $platform ][ self::FIELD ] ) ? $settings['platforms'][ $platform ][ self::FIELD ] : '';
        $mode     = self::is_mode( $stored ) ? $stored : self::ID;

        /**
         * Product identifier a platform sends: id (product ID) or sku (SKU, the ID when empty).
         *
         * @since 3.0.0
         *
         * @param string $mode     Mode.
         * @param string $platform Settings platform ID.
         */
        $mode = apply_filters( 'wcct_product_id_mode', $mode, $platform );

        return self::is_mode( $mode ) ? $mode : self::ID;
    }

    /**
     * The mode the catalog feed writes in its id column, from wcct_data_feed; ID by default.
     *
     * @return string
     */
    public static function feed_mode() {
        $feed   = get_option( self::FEED_OPTION, array() );
        $stored = is_array( $feed ) && isset( $feed[ self::FIELD ] ) ? $feed[ self::FIELD ] : '';
        $mode   = self::is_mode( $stored ) ? $stored : self::ID;

        /**
         * Product identifier the catalog feed writes: id (product ID) or sku (SKU, the ID when empty).
         *
         * @since 3.0.0
         *
         * @param string $mode Mode.
         */
        $mode = apply_filters( 'wcct_feed_product_id_mode', $mode );

        return self::is_mode( $mode ) ? $mode : self::ID;
    }

    /**
     * Whether a platform's pixel and the catalog feed send the same identifier, for the Doctor.
     *
     * @param string $platform Platform or integration ID.
     *
     * @return bool
     */
    public static function feed_matches( $platform = 'facebook' ) {
        return self::mode_for( $platform ) === self::feed_mode();
    }

    /**
     * A product's identifier in a mode: the ID, or the product's own SKU falling back to the ID.
     *
     * @param \WC_Product|object $product Product or variation.
     * @param string             $mode    Mode.
     *
     * @return int|string Integer ID in ID mode, string in SKU mode; 0 for no product.
     */
    public static function value( $product, $mode ) {
        if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
            return 0;
        }

        $id = (int) $product->get_id();

        if ( self::SKU !== $mode ) {
            return $id;
        }

        // Edit context: a variation without a SKU would otherwise read its parent's; it also skips the woocommerce_product_get_sku filters.
        $sku = method_exists( $product, 'get_sku' ) ? $product->get_sku( 'edit' ) : '';
        $sku = is_scalar( $sku ) ? trim( (string) $sku ) : '';

        return '' !== $sku ? $sku : (string) $id;
    }

    /**
     * A product's identifier for a platform.
     *
     * @param \WC_Product|object $product  Product or variation.
     * @param string             $platform Platform or integration ID.
     *
     * @return int|string
     */
    public static function for_product( $product, $platform ) {
        return self::value( $product, self::mode_for( $platform ) );
    }

    /**
     * A product's identifier in the catalog feed.
     *
     * @param \WC_Product|object $product Product or variation.
     *
     * @return int|string
     */
    public static function for_feed( $product ) {
        return self::value( $product, self::feed_mode() );
    }

    /**
     * Identifiers of the cart items for a platform; a variation sends its own.
     *
     * @param array  $cart     Cart contents.
     * @param string $platform Platform or integration ID.
     *
     * @return array
     */
    public static function for_cart( $cart, $platform ) {
        $mode = self::mode_for( $platform );
        $ids  = array();

        foreach ( (array) $cart as $item ) {
            if ( isset( $item['data'] ) && is_object( $item['data'] ) ) {
                $ids[] = self::value( $item['data'], $mode );
            }
        }

        return $ids;
    }

    /**
     * Identifiers of an event's products for a platform: the event's product, else its product IDs.
     *
     * @param Event|object $event    Event.
     * @param string       $platform Platform or integration ID.
     *
     * @return array
     */
    public static function for_event( $event, $platform ) {
        if ( ! is_object( $event ) || ! method_exists( $event, 'get_product_ids' ) ) {
            return array();
        }

        $mode    = self::mode_for( $platform );
        $product = $event->get_product();

        if ( is_object( $product ) ) {
            return array( self::value( $product, $mode ) );
        }

        $ids = array();

        foreach ( $event->get_product_ids() as $id ) {
            // ID mode needs no product; SKU mode loads it and keeps the ID when it is gone.
            $product = self::SKU === $mode && function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
            $ids[]   = is_object( $product ) ? self::value( $product, $mode ) : ( self::SKU === $mode ? (string) $id : (int) $id );
        }

        return $ids;
    }

    /**
     * Identifiers of an order's items for a platform; a variation sends its own, a deleted product its stored ID.
     *
     * @param \WC_Order|object $order    Order.
     * @param string           $platform Platform or integration ID.
     *
     * @return array
     */
    public static function for_order( $order, $platform ) {
        $mode = self::mode_for( $platform );
        $ids  = array();

        if ( ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
            return $ids;
        }

        foreach ( $order->get_items() as $item ) {
            $id = self::for_order_item( $item, $mode );

            if ( $id ) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * One order item's identifier in a mode.
     *
     * @param \WC_Order_Item_Product|object $item Order item.
     * @param string                        $mode Mode.
     *
     * @return int|string 0 when the item has no product.
     */
    public static function for_order_item( $item, $mode ) {
        if ( ! is_object( $item ) || ! method_exists( $item, 'get_product_id' ) ) {
            return 0;
        }

        $product = $item->get_product();

        if ( is_object( $product ) ) {
            return self::value( $product, $mode );
        }

        $id = (int) ( $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id() );

        return self::SKU === $mode && $id ? (string) $id : $id;
    }

    /**
     * The product a product page shows: the variation picked in the URL or by default, else the product itself.
     *
     * @param \WC_Product|mixed $product Product.
     *
     * @return \WC_Product|mixed
     */
    public static function viewed( $product ) {
        if ( ! $product instanceof \WC_Product_Variable ) {
            return $product;
        }

        $attributes = array();

        // Same selection as WooCommerce's variation dropdowns.
        foreach ( array_keys( $product->get_variation_attributes() ) as $name ) {
            $key = 'attribute_' . sanitize_title( $name );

            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, like WooCommerce's own form.
            $posted = isset( $_REQUEST[ $key ] ) ? wc_clean( wp_unslash( $_REQUEST[ $key ] ) ) : null;
            $value  = is_string( $posted ) ? $posted : $product->get_variation_default_attribute( $name );

            if ( '' === $value ) {
                return $product;
            }

            $attributes[ $key ] = $value;
        }

        if ( ! $attributes ) {
            return $product;
        }

        try {
            $variation_id = \WC_Data_Store::load( 'product' )->find_matching_product_variation( $product, $attributes );
        } catch ( \Exception $e ) {
            return $product;
        }

        $variation = $variation_id ? wc_get_product( $variation_id ) : null;

        return $variation instanceof \WC_Product_Variation && $variation->variation_is_visible() ? $variation : $product;
    }

    /**
     * Settings platform ID of a platform or integration ID.
     *
     * @param mixed $platform Platform or integration ID.
     *
     * @return string
     */
    private static function platform( $platform ) {
        $platform = is_string( $platform ) ? $platform : '';

        return isset( self::PLATFORMS[ $platform ] ) ? self::PLATFORMS[ $platform ] : $platform;
    }
}
