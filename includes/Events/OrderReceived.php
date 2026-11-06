<?php

namespace StorePulse\ConversionTracking\Events;

/**
 * The order on the order received page, under the same rules WooCommerce uses to show it.
 */
class OrderReceived {

    /**
     * The order WooCommerce shows on this order received page, or false.
     *
     * @return \WC_Order|false
     */
    public static function get_order() {
        if ( ! function_exists( 'is_order_received_page' ) || ! is_order_received_page() ) {
            return false;
        }

        // WooCommerce filter.
        $order_id  = absint( apply_filters( 'woocommerce_thankyou_order_id', absint( get_query_var( 'order-received' ) ) ) );
        $order_key = isset( $_GET['key'] ) && is_string( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        // WooCommerce filter.
        $order_key = (string) apply_filters( 'woocommerce_thankyou_order_key', $order_key );
        $order     = $order_id ? wc_get_order( $order_id ) : false;

        if ( ! $order instanceof \WC_Order || '' === $order_key || ! hash_equals( $order->get_order_key(), $order_key ) ) {
            return false;
        }

        if ( ! self::visitor_owns( $order ) ) {
            return false;
        }

        if ( ! $order->get_customer_id() && self::guest_must_verify_email( $order ) ) {
            return false;
        }

        return $order;
    }

    /**
     * An order the visitor may see off the order received page: guest orders, or the logged-in customer's own.
     *
     * @param mixed $order_id Order ID.
     *
     * @return \WC_Order|false
     */
    public static function get_owned_order( $order_id ) {
        $order = is_numeric( $order_id ) && (int) $order_id > 0 ? wc_get_order( (int) $order_id ) : false;

        if ( ! $order instanceof \WC_Order || ! self::visitor_owns( $order ) ) {
            return false;
        }

        return $order;
    }

    /**
     * Whether the visitor may see the order: a guest order, or a registered customer's order with that customer logged in.
     *
     * @param \WC_Order $order Order.
     *
     * @return bool
     */
    private static function visitor_owns( $order ) {
        $customer_id = $order->get_customer_id();

        // WooCommerce filter.
        return ! $customer_id || get_current_user_id() === $customer_id || ! apply_filters( 'woocommerce_order_received_verify_known_shoppers', true );
    }

    /**
     * Whether WooCommerce asks the guest to confirm the billing email before showing the order.
     *
     * @param \WC_Order $order Order.
     *
     * @return bool
     */
    private static function guest_must_verify_email( $order ) {
        $users = '\Automattic\WooCommerce\Internal\Utilities\Users';

        if ( ! class_exists( $users ) || ! method_exists( $users, 'should_user_verify_order_email' ) ) {
            return false;
        }

        $supplied_email = null;

        if ( wp_verify_nonce( filter_input( INPUT_POST, 'check_submission' ), 'wc_verify_email' ) ) {
            $supplied_email = sanitize_email( wp_unslash( filter_input( INPUT_POST, 'email' ) ) );
        }

        return $users::should_user_verify_order_email( $order->get_id(), $supplied_email, 'order-received' );
    }
}
