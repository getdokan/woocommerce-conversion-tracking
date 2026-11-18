<?php

namespace StorePulse\ConversionTracking\Events;

/**
 * The reference an order goes by everywhere: the order number the merchant sees, the order ID if a plugin empties it.
 */
class OrderReference {

    /**
     * The reference of an order.
     *
     * @param \WC_Order|object $order Order with get_id(), and get_order_number() when it has one.
     *
     * @return string
     */
    public static function of( $order ) {
        return self::from( method_exists( $order, 'get_order_number' ) ? $order->get_order_number() : '', method_exists( $order, 'get_id' ) ? $order->get_id() : 0 );
    }

    /**
     * The reference for an order number and ID, never empty.
     *
     * @param mixed $number   Order number.
     * @param mixed $order_id Order ID.
     *
     * @return string
     */
    public static function from( $number, $order_id ) {
        $number = is_scalar( $number ) ? trim( (string) $number ) : '';

        return '' !== $number ? $number : (string) abs( (int) $order_id );
    }
}
