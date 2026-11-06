<?php

namespace StorePulse\ConversionTracking\Events;

/**
 * Decides the events of the current page from WordPress and WooCommerce conditional tags.
 */
class PageEvents {

    /**
     * Events for the current page, in the order they are sent.
     *
     * @param Event[] $extra Events found elsewhere in the request, added before the filter.
     *
     * @return Event[]
     */
    public static function current( array $extra = array() ) {
        $events = array();

        if ( is_product() ) {
            $product = wc_get_product( get_queried_object_id() );

            if ( $product ) {
                $events[] = EventFactory::view_product( $product );
            }
        }

        if ( is_product_category() ) {
            $term = get_queried_object();

            if ( $term instanceof \WP_Term ) {
                $events[] = EventFactory::view_category( $term, (array) $GLOBALS['wp_query']->posts );
            }
        }

        if ( is_search() ) {
            $events[] = EventFactory::search( get_search_query( false ), get_query_var( 'post_type' ) );
        }

        if ( self::is_checkout_start() ) {
            $events[] = EventFactory::cart( Event::BEGIN_CHECKOUT, WC()->cart );
        }

        $order = OrderReceived::get_order();

        if ( $order ) {
            $events[] = EventFactory::purchase( $order );
        }

        // Logged-in pages only, so the flag never reaches a shared page cache.
        if ( is_user_logged_in() && SignUp::take( get_current_user_id() ) ) {
            $events[] = EventFactory::simple( Event::SIGN_UP );

            // A one-shot event: per-user page caches must not replay this page.
            if ( ! defined( 'DONOTCACHEPAGE' ) ) {
                define( 'DONOTCACHEPAGE', true );
            }
        }

        $events = array_merge( $events, $extra );

        /**
         * Events decided for the current page.
         *
         * @since 3.0.0
         *
         * @param Event[] $events Events, in send order.
         */
        $events = apply_filters( 'wcct_page_events', $events );

        return array_values(
            array_filter(
                is_array( $events ) ? $events : array(),
                function ( $event ) {
                    return $event instanceof Event;
                }
            )
        );
    }

    /**
     * Whether this page shows the checkout form for a non-empty cart.
     *
     * @return bool
     */
    private static function is_checkout_start() {
        if ( ! is_checkout() || is_order_received_page() || is_wc_endpoint_url( 'order-pay' ) ) {
            return false;
        }

        if ( ! WC()->cart || WC()->cart->is_empty() ) {
            return false;
        }

        // WooCommerce shows a login notice instead of the form here.
        $checkout = WC()->checkout();

        return is_user_logged_in() || ! $checkout->is_registration_required() || $checkout->is_registration_enabled();
    }
}
