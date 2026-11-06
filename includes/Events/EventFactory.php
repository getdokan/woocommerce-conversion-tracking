<?php

namespace StorePulse\ConversionTracking\Events;

/**
 * Builds events from WooCommerce objects, through CRUD getters only.
 */
class EventFactory {

    /**
     * View product event.
     *
     * @param \WC_Product $product Product.
     *
     * @return Event
     */
    public static function view_product( $product ) {
        return new Event(
            Event::VIEW_PRODUCT,
            array(
                'value'       => $product->get_price(),
                'currency'    => get_woocommerce_currency(),
                'decimals'    => wc_get_price_decimals(),
                'product'     => $product,
                'product_ids' => array( $product->get_id() ),
            )
        );
    }

    /**
     * View category event.
     *
     * @param \WP_Term $term  Category.
     * @param array    $posts Products in the main query.
     *
     * @return Event
     */
    public static function view_category( $term, array $posts ) {
        return new Event(
            Event::VIEW_CATEGORY,
            array(
                'currency'    => get_woocommerce_currency(),
                'product_ids' => wp_list_pluck( array_filter( $posts, 'is_object' ), 'ID' ),
                'data'        => array(
                    'term'  => $term,
                    'posts' => $posts,
                ),
            )
        );
    }

    /**
     * Search event.
     *
     * @param string $search_string What the visitor searched for.
     * @param string $post_type     Post type searched, empty for all.
     *
     * @return Event
     */
    public static function search( $search_string, $post_type = '' ) {
        return new Event(
            Event::SEARCH,
            array(
                'data' => array(
                    'search_string' => (string) $search_string,
                    'post_type'     => is_string( $post_type ) ? $post_type : '',
                ),
            )
        );
    }

    /**
     * An event about the current cart (add to cart, begin checkout).
     *
     * @param string        $name Event name.
     * @param \WC_Cart|null $cart Cart.
     *
     * @return Event
     */
    public static function cart( $name, $cart ) {
        $args = array(
            'currency' => get_woocommerce_currency(),
            'decimals' => wc_get_price_decimals(),
        );

        if ( $cart instanceof \WC_Cart ) {
            $ids = array();

            foreach ( $cart->get_cart() as $item ) {
                if ( isset( $item['data'] ) && $item['data'] instanceof \WC_Product ) {
                    $ids[] = $item['data']->get_id();
                }
            }

            $args['value']       = $cart->get_total( 'edit' );
            $args['tax']         = $cart->get_total_tax();
            $args['shipping']    = $cart->get_shipping_total();
            $args['product_ids'] = $ids;
        }

        return new Event( $name, $args );
    }

    /**
     * Add to cart event for the product just added; the value is its shop price times the quantity.
     *
     * @param \WC_Product $product  Product or variation added.
     * @param int|float   $quantity Quantity added.
     *
     * @return Event
     */
    public static function add_to_cart( $product, $quantity = 1 ) {
        $quantity = is_numeric( $quantity ) && $quantity > 0 ? 0 + $quantity : 1;

        return new Event(
            Event::ADD_TO_CART,
            array(
                'value'       => wc_get_price_to_display( $product, array( 'qty' => $quantity ) ),
                'currency'    => get_woocommerce_currency(),
                'decimals'    => wc_get_price_decimals(),
                'product'     => $product,
                'product_ids' => array( $product->get_id() ),
                'data'        => array( 'quantity' => $quantity ),
            )
        );
    }

    /**
     * Purchase event for an order.
     *
     * @param \WC_Order $order Order.
     *
     * @return Event
     */
    public static function purchase( $order ) {
        $ids         = array();
        $items_total = 0.0;

        foreach ( $order->get_items() as $item ) {
            if ( $item instanceof \WC_Order_Item_Product ) {
                $ids[]        = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
                $items_total += (float) $item->get_total();
            }
        }

        return new Event(
            Event::PURCHASE,
            array(
                'value'       => $order->get_total(),
                'tax'         => $order->get_total_tax(),
                'shipping'    => $order->get_shipping_total(),
                'items_total' => $items_total,
                'currency'    => $order->get_currency(),
                'decimals'    => wc_get_price_decimals(),
                'order'       => $order,
                'product_ids' => $ids,
            )
        );
    }

    /**
     * Add to wishlist event, with the product when the ID resolves to one.
     *
     * @param mixed $product_id Product ID.
     *
     * @return Event
     */
    public static function add_to_wishlist( $product_id ) {
        $product = is_numeric( $product_id ) && (int) $product_id > 0 ? wc_get_product( (int) $product_id ) : false;

        if ( ! $product instanceof \WC_Product ) {
            return new Event( Event::ADD_TO_WISHLIST );
        }

        return new Event(
            Event::ADD_TO_WISHLIST,
            array(
                'value'       => $product->get_price(),
                'currency'    => get_woocommerce_currency(),
                'decimals'    => wc_get_price_decimals(),
                'product'     => $product,
                'product_ids' => array( $product->get_id() ),
            )
        );
    }

    /**
     * An event with no product or order data (sign up).
     *
     * @param string $name Event name.
     *
     * @return Event
     */
    public static function simple( $name ) {
        return new Event( $name );
    }
}
