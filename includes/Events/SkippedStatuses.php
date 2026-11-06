<?php

namespace StorePulse\ConversionTracking\Events;

/**
 * Order statuses that never send a Purchase, filterable through wcct_skipped_order_statuses.
 */
class SkippedStatuses {

    /**
     * Skipped by default.
     */
    const DEFAULTS = array( 'failed', 'cancelled' );

    /**
     * The skipped statuses, without the wc- prefix.
     *
     * @return string[]
     */
    public static function get() {
        /**
         * Order statuses that never send a Purchase, without the wc- prefix.
         *
         * @since 3.0.0
         *
         * @param string[] $statuses Default: failed, cancelled.
         */
        return self::normalise( apply_filters( 'wcct_skipped_order_statuses', self::DEFAULTS ) );
    }

    /**
     * Whether an order status is skipped.
     *
     * @param string        $status  Order status, with or without the wc- prefix.
     * @param string[]|null $skipped Skipped statuses; the filtered list when null.
     *
     * @return bool
     */
    public static function is_skipped( $status, $skipped = null ) {
        $status  = self::normalise( array( $status ) );
        $skipped = null === $skipped ? self::get() : self::normalise( $skipped );

        return $status && in_array( $status[0], $skipped, true );
    }

    /**
     * Clean a status list: strings only, lower case, no wc- prefix, no duplicates. A non-array gives the defaults.
     *
     * @param mixed $statuses Raw list.
     *
     * @return string[]
     */
    public static function normalise( $statuses ) {
        if ( ! is_array( $statuses ) ) {
            return self::DEFAULTS;
        }

        $clean = array();

        foreach ( $statuses as $status ) {
            if ( ! is_string( $status ) ) {
                continue;
            }

            $status = strtolower( trim( $status ) );
            $status = 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status;

            if ( '' !== $status ) {
                $clean[] = $status;
            }
        }

        return array_values( array_unique( $clean ) );
    }
}
