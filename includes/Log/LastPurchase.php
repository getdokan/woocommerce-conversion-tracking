<?php

namespace StorePulse\ConversionTracking\Log;

/**
 * Each platform's last checked-in purchase, in one option per platform so parallel check-ins never overwrite each other; the 24-hour prune never touches it.
 */
class LastPurchase {

    /**
     * Option name prefix; the platform ID follows.
     */
    const PREFIX = 'wcct_last_purchase_';

    /**
     * Option name for a platform, or an empty string for an invalid ID.
     *
     * @param string $platform Platform ID.
     *
     * @return string
     */
    public static function option( $platform ) {
        $platform = is_string( $platform ) ? strtolower( preg_replace( '/[^A-Za-z0-9_\-]/', '', $platform ) ) : '';

        return '' === $platform ? '' : self::PREFIX . $platform;
    }

    /**
     * Record a purchase handed to the platform.
     *
     * @param string          $platform Platform ID.
     * @param \WC_Order|mixed $order    Order.
     * @param int|null        $time     Unix time, default now.
     *
     * @return bool Whether it was written.
     */
    public static function record( $platform, $order, $time = null ) {
        $option = self::option( $platform );

        if ( '' === $option || ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
            return false;
        }

        $value = array(
            'last_purchase_at' => null === $time ? time() : (int) $time,
            'order_id'         => (int) $order->get_id(),
            'order_number'     => method_exists( $order, 'get_order_number' ) ? (string) $order->get_order_number() : (string) $order->get_id(),
        );

        // Not autoloaded: only the dashboard reads it.
        return update_option( $option, $value, false );
    }

    /**
     * A platform's last purchase: last_purchase_at (Unix time), order_id and order_number; null when there is none.
     *
     * @param string $platform Platform ID.
     *
     * @return array|null
     */
    public static function get( $platform ) {
        $option = self::option( $platform );
        $value  = '' === $option ? null : get_option( $option, null );

        if ( ! is_array( $value ) || empty( $value['last_purchase_at'] ) ) {
            return null;
        }

        return array(
            'last_purchase_at' => (int) $value['last_purchase_at'],
            'order_id'         => isset( $value['order_id'] ) ? (int) $value['order_id'] : 0,
            'order_number'     => isset( $value['order_number'] ) ? (string) $value['order_number'] : '',
        );
    }
}
