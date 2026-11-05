<?php

namespace StorePulse\ConversionTracking\Events;

/**
 * Per-order, per-platform "Purchase sent" flags, stored as order meta through WooCommerce CRUD (HPOS-safe).
 */
class SentFlags {

    /**
     * Meta key prefix; the platform ID follows.
     */
    const PREFIX = '_wcct_sent_';

    /**
     * Platforms that kept the key they used before 3.0.
     */
    const LEGACY_KEYS = array(
        'ga4' => '_wcct_ga4_tracked',
    );

    /**
     * Order meta key for a platform's flag, or an empty string for an invalid platform ID.
     *
     * @param string $platform Platform ID.
     *
     * @return string
     */
    public static function meta_key( $platform ) {
        $platform = is_string( $platform ) ? strtolower( preg_replace( '/[^A-Za-z0-9_\-]/', '', $platform ) ) : '';

        if ( '' === $platform ) {
            return '';
        }

        return isset( self::LEGACY_KEYS[ $platform ] ) ? self::LEGACY_KEYS[ $platform ] : self::PREFIX . $platform;
    }

    /**
     * Whether the platform's Purchase was already sent for the order.
     *
     * @param \WC_Order|mixed $order    Order.
     * @param string          $platform Platform ID.
     *
     * @return bool
     */
    public static function is_sent( $order, $platform ) {
        $key = self::meta_key( $platform );

        if ( '' === $key || ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
            return false;
        }

        return (bool) $order->get_meta( $key, true );
    }

    /**
     * Mark the platform's Purchase as sent for the order; returns false when nothing was written.
     *
     * @param \WC_Order|mixed $order    Order.
     * @param string          $platform Platform ID.
     *
     * @return bool
     */
    public static function mark_sent( $order, $platform ) {
        $key = self::meta_key( $platform );

        if ( '' === $key || ! is_object( $order ) || ! method_exists( $order, 'update_meta_data' ) || self::is_sent( $order, $platform ) ) {
            return false;
        }

        $order->update_meta_data( $key, time() );
        $order->save_meta_data();

        return true;
    }

    /**
     * Remove the platform's flag from the order.
     *
     * @param \WC_Order|mixed $order    Order.
     * @param string          $platform Platform ID.
     *
     * @return void
     */
    public static function clear( $order, $platform ) {
        $key = self::meta_key( $platform );

        if ( '' === $key || ! is_object( $order ) || ! method_exists( $order, 'delete_meta_data' ) ) {
            return;
        }

        $order->delete_meta_data( $key );
        $order->save_meta_data();
    }
}
