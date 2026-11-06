<?php

namespace StorePulse\ConversionTracking\Events;

/**
 * Decides whether a platform still sends the Purchase for an order: once per order, never for skipped statuses.
 */
class PurchaseGate {

    /**
     * Option holding the time 3.0 first ran; orders created before it count as already sent.
     */
    const SINCE_OPTION = 'wcct_count_once_since';

    /**
     * Unpaid statuses that still send a Purchase on the thank-you page by default.
     */
    const UNPAID_DEFAULTS = array( 'pending', 'on-hold' );

    /**
     * Write the count-once cutoff on the first run; returns it.
     *
     * @return int
     */
    public static function ensure_since() {
        $since = self::since();

        if ( ! $since ) {
            $since = time();
            add_option( self::SINCE_OPTION, $since );
        }

        return $since;
    }

    /**
     * The count-once cutoff as a Unix time, 0 when not written yet.
     *
     * @return int
     */
    public static function since() {
        $since = get_option( self::SINCE_OPTION, 0 );

        return is_numeric( $since ) ? max( 0, (int) $since ) : 0;
    }

    /**
     * Unpaid statuses that still send a Purchase, without the wc- prefix.
     *
     * @return string[]
     */
    public static function unpaid_statuses() {
        /**
         * Unpaid order statuses that still send a Purchase on the thank-you page; a removed status sends nothing, and nothing sends it once paid unless the thank-you page is viewed again.
         *
         * @since 3.0.0
         *
         * @param string[] $statuses Default: pending, on-hold.
         */
        $statuses = apply_filters( 'wcct_unpaid_order_statuses', self::UNPAID_DEFAULTS );

        return is_array( $statuses ) ? SkippedStatuses::normalise( $statuses ) : self::UNPAID_DEFAULTS;
    }

    /**
     * Whether a status is one of the unpaid statuses (default or filtered).
     *
     * @param string $status Order status, with or without the wc- prefix.
     *
     * @return bool
     */
    public static function is_unpaid_status( $status ) {
        $status = SkippedStatuses::normalise( array( $status ) );

        return $status && in_array( $status[0], array_unique( array_merge( self::UNPAID_DEFAULTS, self::unpaid_statuses() ) ), true );
    }

    /**
     * Whether an order in this status sends a Purchase.
     *
     * @param string $status Order status, with or without the wc- prefix.
     *
     * @return bool
     */
    public static function status_sends( $status ) {
        $clean = SkippedStatuses::normalise( array( $status ) );

        if ( ! $clean || SkippedStatuses::is_skipped( $clean[0] ) ) {
            return false;
        }

        return ! in_array( $clean[0], self::UNPAID_DEFAULTS, true ) || in_array( $clean[0], self::unpaid_statuses(), true );
    }

    /**
     * Whether the order was created before the count-once cutoff, so it was counted by the earlier version.
     *
     * @param \WC_Order|mixed $order Order.
     *
     * @return bool
     */
    public static function counted_before( $order ) {
        $since = self::since();

        if ( ! $since || ! is_object( $order ) || ! method_exists( $order, 'get_date_created' ) ) {
            return false;
        }

        $created = $order->get_date_created();

        return $created instanceof \DateTimeInterface && $created->getTimestamp() < $since;
    }

    /**
     * Whether the platform sends the Purchase for the order now.
     *
     * @param \WC_Order|mixed $order    Order.
     * @param string          $platform Platform ID.
     *
     * @return bool
     */
    public static function should_send( $order, $platform ) {
        if ( ! is_object( $order ) || ! method_exists( $order, 'get_status' ) ) {
            return false;
        }

        return self::status_sends( $order->get_status() ) && ! self::counted_before( $order ) && ! SentFlags::is_sent( $order, $platform );
    }
}
