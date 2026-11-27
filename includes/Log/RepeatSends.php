<?php

namespace StorePulse\ConversionTracking\Log;

/**
 * Purchases a platform took a second time for the same order (a second hand-over check-in), kept 24 hours for the Doctor.
 */
class RepeatSends {

    /**
     * Option holding [ { order_id, platform, time } ], newest last.
     */
    const OPTION = 'wcct_order_log_repeats';

    /**
     * Entries kept at most.
     */
    const MAX = 100;

    /**
     * Record a repeat once per order and platform.
     *
     * @param int      $order_id Order ID.
     * @param string   $platform Platform ID.
     * @param int|null $now      Unix time, default now.
     *
     * @return void
     */
    public static function record( $order_id, $platform, $now = null ) {
        $now     = null === $now ? time() : (int) $now;
        $entries = self::since( $now - OrderLog::RETENTION );

        foreach ( $entries as $entry ) {
            if ( (int) $order_id === $entry['order_id'] && (string) $platform === $entry['platform'] ) {
                return;
            }
        }

        $entries[] = array(
            'order_id' => (int) $order_id,
            'platform' => (string) $platform,
            'time'     => $now,
        );

        update_option( self::OPTION, array_slice( $entries, -self::MAX ), false );
    }

    /**
     * Repeats at or after a Unix time, oldest first.
     *
     * @param int $since Unix time.
     *
     * @return array[] { order_id, platform, time }.
     */
    public static function since( $since ) {
        $stored  = get_option( self::OPTION, array() );
        $entries = array();

        foreach ( is_array( $stored ) ? $stored : array() as $entry ) {
            if ( ! is_array( $entry ) || ! isset( $entry['order_id'], $entry['platform'], $entry['time'] ) || (int) $entry['time'] < (int) $since ) {
                continue;
            }

            $entries[] = array(
                'order_id' => (int) $entry['order_id'],
                'platform' => (string) $entry['platform'],
                'time'     => (int) $entry['time'],
            );
        }

        return $entries;
    }
}
