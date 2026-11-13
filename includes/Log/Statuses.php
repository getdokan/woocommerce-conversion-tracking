<?php

namespace StorePulse\ConversionTracking\Log;

use StorePulse\ConversionTracking\Events\PurchaseGate;
use StorePulse\ConversionTracking\Events\SkippedStatuses;
use StorePulse\ConversionTracking\Platforms\Pause;

/**
 * What happened to an order on each platform, worked out on read from the order and its log rows; nothing is stored.
 */
class Statuses {

    /**
     * Handed over on a paid order: "Counted once".
     */
    const CONFIRMED = 'confirmed';

    /**
     * Checked in, but the platform didn't confirm within the settle time: "Not confirmed on <platform>".
     */
    const NOT_CONFIRMED = 'not_confirmed';

    /**
     * Never checked in: no thank-you page visit, or the page never reported back. Never counted as not confirmed.
     */
    const NO_CHECKIN = 'no_checkin';

    /**
     * Sent on the thank-you page, payment still pending: "Pending payment".
     */
    const PENDING = 'pending';

    /**
     * Too early to tell: inside the settle time after the check-in or the order.
     */
    const WAITING = 'waiting';

    /**
     * Nothing sent, by design: declined cookies, a failed or cancelled order, or every platform paused.
     */
    const SKIPPED = 'skipped';

    /**
     * Platform only: paused, so nothing was sent to it.
     */
    const PAUSED = 'paused';

    /**
     * Causes: the shopper declined cookies.
     */
    const CAUSE_CONSENT = 'consent';

    /**
     * Causes: the platform is paused.
     */
    const CAUSE_PAUSED = 'paused';

    /**
     * Causes: a failed or cancelled order never sends.
     */
    const CAUSE_ORDER_STATUS = 'order_status';

    /**
     * Causes: no thank-you page visit at all.
     */
    const CAUSE_NOT_REACHED = 'not_reached';

    /**
     * Causes: the thank-you page rendered (server flag) but never checked in.
     */
    const CAUSE_NOT_REPORTED = 'not_reported';

    /**
     * Seconds after the check-in (or the order, without one) before a result is final; a reload can still confirm until then.
     */
    const SETTLE = 600;

    /**
     * created_via values that are never listed: admin, POS, REST API and subscription renewals.
     */
    const SKIPPED_VIA = array( 'admin', 'pos', 'pos-rest-api', 'woocommerce-pos', 'rest-api', 'subscription' );

    /**
     * Order statuses that aren't real orders yet, or anymore.
     */
    const NEVER_LISTED = array( 'checkout-draft', 'draft', 'auto-draft', 'trash' );

    /**
     * Activity log tabs.
     */
    const TAB_ALL           = 'all';
    const TAB_TRACKED       = 'tracked';
    const TAB_NOT_CONFIRMED = 'not-confirmed';
    const TAB_PENDING       = 'pending';

    /**
     * Platform statuses, most telling first: a merged platform and the order take the first one present.
     */
    const RANK = array( self::NOT_CONFIRMED, self::WAITING, self::PENDING, self::CONFIRMED, self::NO_CHECKIN, self::SKIPPED, self::PAUSED );

    /**
     * The order and per-platform statuses, or null when the order isn't listed.
     *
     * @param array    $order  Summary: id, number, status, created_via, created, paid, thankyou (Unix times, 0 for none), total, currency, renewal, counted_before.
     * @param array[]  $rows   The order's log rows.
     * @param string[] $active Integration IDs that check in on the thank-you page now.
     * @param array    $paused Integration IDs that are set up but paused, as a list or as ID => when the pause started (Unix time, 0 for unknown).
     * @param int      $now    Unix time.
     *
     * @return array|null The summary plus status, cause, settles_at and platforms (id, integrations, status, cause, sent, checked_in_at, sent_at, consent, event_id, unknown).
     */
    public static function evaluate( array $order, array $rows, array $active, array $paused, $now ) {
        $order = array_merge(
            array(
                'status'         => '',
                'created_via'    => '',
                'created'        => 0,
                'paid'           => 0,
                'thankyou'       => 0,
                'renewal'        => false,
                'counted_before' => false,
            ),
            $order
        );
        $rows  = self::index_rows( $rows );

        if ( ! self::is_listed( $order, (bool) $rows ) ) {
            return null;
        }

        $now    = (int) $now;
        $paused = self::paused_since( $paused );
        $unpaid = PurchaseGate::is_unpaid_status( $order['status'] );
        $sent   = (bool) array_filter( array_column( $rows, 'library_loaded' ) );
        $ids    = array_values( array_unique( array_merge( array_keys( $rows ), $active, array_keys( $paused ) ) ) );

        // An unpaid order that never reached the thank-you page is an abandoned payment, not a sale.
        if ( $unpaid && ! $rows && ! $order['thankyou'] ) {
            return null;
        }

        // Failed, cancelled and unpaid statuses filtered out of wcct_unpaid_order_statuses never send; one handed over before the status changed still counts.
        if ( ! PurchaseGate::status_sends( $order['status'] ) && ! $sent ) {
            return $ids ? self::result( $order, self::SKIPPED, self::CAUSE_ORDER_STATUS, 0, self::same_status( $ids, self::SKIPPED, self::CAUSE_ORDER_STATUS ) ) : null;
        }

        // The settle time runs from the latest of the order, its payment and the thank-you page: an order paid later (off-site, or on the pay page) gets its full 10 minutes from the payment.
        $anchor     = max( (int) $order['created'], (int) $order['paid'], (int) $order['thankyou'] );
        $entries    = array();
        $settles_at = 0;

        foreach ( $ids as $id ) {
            $row = isset( $rows[ $id ] ) ? $rows[ $id ] : null;

            if ( $row ) {
                $entry = self::from_row( $id, $row, $unpaid, $now );
            } elseif ( isset( $paused[ $id ] ) && (int) $order['created'] >= $paused[ $id ] ) {
                $entry = self::entry( $id, self::PAUSED, self::CAUSE_PAUSED );
            } elseif ( $rows ) {
                // The order checked in without this platform: listed in the detail, never judged.
                $entry = array_merge( self::entry( $id, self::NO_CHECKIN, $order['thankyou'] ? self::CAUSE_NOT_REPORTED : self::CAUSE_NOT_REACHED ), array( 'unknown' => true ) );
            } elseif ( $now < $anchor + self::SETTLE ) {
                $entry = self::entry( $id, self::WAITING, '' );
            } else {
                $entry = self::entry( $id, self::NO_CHECKIN, $order['thankyou'] ? self::CAUSE_NOT_REPORTED : self::CAUSE_NOT_REACHED );
            }

            if ( self::WAITING === $entry['status'] ) {
                $settles_at = max( $settles_at, $row ? self::checked_in_at( $row ) + self::SETTLE : $anchor + self::SETTLE );
            }

            $entries[] = $entry;
        }

        $platforms = self::merge( $entries );

        if ( ! $platforms ) {
            return null;
        }

        list( $status, $cause ) = self::fold( $platforms, $order );

        return self::result( $order, $status, $cause, $settles_at, $platforms );
    }

    /**
     * One order's status now, read through WooCommerce CRUD and the log: on one platform, or for the whole order when the platform is empty.
     *
     * @param \WC_Order|int $order    Order or order ID.
     * @param string        $platform Settings platform or integration ID (ga4 and adwords read as google); empty for the order.
     * @param int|null      $now      Unix time, default now.
     *
     * @return string A status constant; empty when the order isn't listed (also outside the 24-hour window, see in_window()) or the platform has no status for it.
     */
    public static function for_order( $order, $platform = '', $now = null ) {
        $order = is_numeric( $order ) && function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order ) : $order;

        if ( ! $order instanceof \WC_Order ) {
            return '';
        }

        $now     = null === $now ? time() : (int) $now;
        $summary = Activity::summary( $order );
        $rows    = OrderLog::is_installed() ? OrderLog::for_orders( array( $order->get_id() ) ) : array();

        if ( ! self::in_window( $summary['created'], $rows, $now ) ) {
            return '';
        }

        $platforms = Activity::platforms();
        $result    = self::evaluate( $summary, $rows, $platforms['active'], $platforms['paused'], $now );

        if ( ! $result ) {
            return '';
        }

        if ( '' === (string) $platform ) {
            return $result['status'];
        }

        foreach ( $result['platforms'] as $entry ) {
            if ( self::platform_of( $platform ) === $entry['id'] ) {
                return $entry['status'];
            }
        }

        return '';
    }

    /**
     * Whether an order is in the 24-hour window: created in it, or checked in in it (an order paid later on the pay page).
     *
     * @param int     $created Unix time the order was created.
     * @param array[] $rows    The order's log rows.
     * @param int     $now     Unix time.
     *
     * @return bool
     */
    public static function in_window( $created, array $rows, $now ) {
        $since = (int) $now - OrderLog::RETENTION;

        if ( (int) $created >= $since ) {
            return true;
        }

        foreach ( $rows as $row ) {
            if ( isset( $row['created_at'] ) && self::time( $row['created_at'] ) >= $since ) {
                return true;
            }
        }

        return false;
    }

    /**
     * The tab an order shows under besides All; empty for All only (No check-in, Skipped, Waiting).
     *
     * @param string $status Order status from evaluate().
     *
     * @return string
     */
    public static function tab( $status ) {
        $tabs = array(
            self::CONFIRMED     => self::TAB_TRACKED,
            self::NOT_CONFIRMED => self::TAB_NOT_CONFIRMED,
            self::PENDING       => self::TAB_PENDING,
        );

        return isset( $tabs[ $status ] ) ? $tabs[ $status ] : '';
    }

    /**
     * Whether an evaluated order shows under a tab.
     *
     * @param array  $order Result of evaluate().
     * @param string $tab   Tab.
     *
     * @return bool
     */
    public static function in_tab( array $order, $tab ) {
        return self::TAB_ALL === $tab || ( '' !== $tab && self::tab( $order['status'] ) === $tab );
    }

    /**
     * Orders per tab.
     *
     * @param array[] $orders Results of evaluate().
     *
     * @return int[] Tab => count.
     */
    public static function counts( array $orders ) {
        $counts = array_fill_keys( array( self::TAB_ALL, self::TAB_TRACKED, self::TAB_NOT_CONFIRMED, self::TAB_PENDING ), 0 );

        foreach ( $orders as $order ) {
            ++$counts[ self::TAB_ALL ];

            $tab = self::tab( $order['status'] );

            if ( '' !== $tab ) {
                ++$counts[ $tab ];
            }
        }

        return $counts;
    }

    /**
     * The platform an integration shows under; Google's ga4 and adwords are one platform.
     *
     * @param string $integration Integration ID.
     *
     * @return string
     */
    public static function platform_of( $integration ) {
        return Pause::platform_of( (string) $integration );
    }

    /**
     * Whether a created_via value or a renewal keeps an order out of the log.
     *
     * @param string $created_via Order created_via.
     * @param bool   $renewal     Whether it's a subscription renewal.
     *
     * @return bool
     */
    public static function is_skipped_source( $created_via, $renewal = false ) {
        /**
         * created_via values whose orders the Activity log never lists (no thank-you page in the shopper's browser).
         *
         * @since 3.0.0
         *
         * @param string[] $values Default: admin, pos, pos-rest-api, woocommerce-pos, rest-api, subscription.
         */
        $skipped = apply_filters( 'wcct_log_skipped_created_via', self::SKIPPED_VIA );

        return $renewal || in_array( strtolower( (string) $created_via ), is_array( $skipped ) ? $skipped : self::SKIPPED_VIA, true );
    }

    /**
     * Whether an order can be listed at all.
     *
     * @param array $order      Summary.
     * @param bool  $checked_in Whether it has a check-in row.
     *
     * @return bool
     */
    private static function is_listed( array $order, $checked_in ) {
        $status = SkippedStatuses::normalise( array( $order['status'] ) );

        if ( ! $status || in_array( $status[0], self::NEVER_LISTED, true ) || $order['counted_before'] ) {
            return false;
        }

        // An admin or renewal order paid on the pay page really sent a Purchase, so a check-in lists it.
        return $checked_in || ! self::is_skipped_source( $order['created_via'], ! empty( $order['renewal'] ) );
    }

    /**
     * Paused IDs => when each pause started; a plain list means unknown starts (0).
     *
     * @param array $paused List of IDs, or ID => Unix time.
     *
     * @return int[]
     */
    private static function paused_since( array $paused ) {
        $since = array();

        foreach ( $paused as $key => $value ) {
            if ( is_int( $key ) ) {
                $since[ (string) $value ] = 0;
            } else {
                $since[ $key ] = (int) $value;
            }
        }

        return $since;
    }

    /**
     * Browser rows keyed by integration ID.
     *
     * @param array[] $rows Log rows.
     *
     * @return array[]
     */
    private static function index_rows( array $rows ) {
        $indexed = array();

        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) || empty( $row['platform'] ) || ( isset( $row['source'] ) && OrderLog::SOURCE_BROWSER !== $row['source'] ) ) {
                continue;
            }

            $indexed[ (string) $row['platform'] ] = $row;
        }

        return $indexed;
    }

    /**
     * A platform's status from its check-in row.
     *
     * @param string $id     Integration ID.
     * @param array  $row    Log row.
     * @param bool   $unpaid Whether the order is still unpaid.
     * @param int    $now    Unix time.
     *
     * @return array
     */
    private static function from_row( $id, array $row, $unpaid, $now ) {
        $sent    = ! empty( $row['library_loaded'] );
        $consent = isset( $row['consent'] ) ? (string) $row['consent'] : '';

        if ( $sent ) {
            $entry = self::entry( $id, $unpaid ? self::PENDING : self::CONFIRMED, '' );
        } elseif ( 'denied' === $consent ) {
            $entry = self::entry( $id, self::SKIPPED, self::CAUSE_CONSENT );
        } elseif ( $now < self::checked_in_at( $row ) + self::SETTLE ) {
            $entry = self::entry( $id, self::WAITING, '' );
        } else {
            $entry = self::entry( $id, $unpaid ? self::PENDING : self::NOT_CONFIRMED, isset( $row['reason'] ) ? (string) $row['reason'] : '' );
        }

        return array_merge(
            $entry,
            array(
                'sent'          => $sent,
                'checked_in_at' => self::checked_in_at( $row ),
                'sent_at'       => $sent ? self::time( isset( $row['sent_at'] ) ? $row['sent_at'] : '' ) : 0,
                'consent'       => $consent,
                'event_id'      => isset( $row['event_id'] ) ? (string) $row['event_id'] : '',
            )
        );
    }

    /**
     * A platform entry without a row.
     *
     * @param string $id     Integration ID.
     * @param string $status Status.
     * @param string $cause  Cause.
     *
     * @return array
     */
    private static function entry( $id, $status, $cause ) {
        return array(
            'id'            => self::platform_of( $id ),
            'integrations'  => array( (string) $id ),
            'status'        => $status,
            'cause'         => $cause,
            'sent'          => false,
            'checked_in_at' => 0,
            'sent_at'       => 0,
            'consent'       => '',
            'event_id'      => '',
            'unknown'       => false,
        );
    }

    /**
     * The same status for every integration.
     *
     * @param string[] $ids    Integration IDs.
     * @param string   $status Status.
     * @param string   $cause  Cause.
     *
     * @return array[]
     */
    private static function same_status( array $ids, $status, $cause ) {
        $entries = array();

        foreach ( $ids as $id ) {
            $entries[] = self::entry( $id, $status, $cause );
        }

        return self::merge( $entries );
    }

    /**
     * One entry per platform: grouped integrations take the most telling status.
     *
     * @param array[] $entries Integration entries.
     *
     * @return array[]
     */
    private static function merge( array $entries ) {
        $merged = array();

        foreach ( $entries as $entry ) {
            $id = $entry['id'];

            if ( ! isset( $merged[ $id ] ) ) {
                $merged[ $id ] = $entry;
                continue;
            }

            $kept = $merged[ $id ];
            $lead = self::rank( $entry['status'] ) < self::rank( $kept['status'] ) ? $entry : $kept;

            $merged[ $id ] = array_merge(
                $lead,
                array(
                    'integrations'  => array_merge( $kept['integrations'], $entry['integrations'] ),
                    'sent'          => $kept['sent'] || $entry['sent'],
                    'checked_in_at' => self::earliest( $kept['checked_in_at'], $entry['checked_in_at'] ),
                    'sent_at'       => self::earliest( $kept['sent_at'], $entry['sent_at'] ),
                    'event_id'      => '' !== $kept['event_id'] ? $kept['event_id'] : $entry['event_id'],
                    'unknown'       => $kept['unknown'] && $entry['unknown'],
                )
            );
        }

        return array_values( $merged );
    }

    /**
     * The order's status and cause from its platforms.
     *
     * @param array[] $platforms Platform entries.
     * @param array   $order     Summary.
     *
     * @return string[] Status and cause.
     */
    private static function fold( array $platforms, array $order ) {
        $judged   = array_filter(
            $platforms,
            function ( $platform ) {
                return empty( $platform['unknown'] );
            }
        );
        $statuses = array_column( $judged, 'status' );

        foreach ( array( self::NOT_CONFIRMED, self::WAITING ) as $status ) {
            if ( in_array( $status, $statuses, true ) ) {
                return array( $status, '' );
            }
        }

        if ( in_array( self::NO_CHECKIN, $statuses, true ) ) {
            return array( self::NO_CHECKIN, $order['thankyou'] ? self::CAUSE_NOT_REPORTED : self::CAUSE_NOT_REACHED );
        }

        foreach ( array( self::PENDING, self::CONFIRMED ) as $status ) {
            if ( in_array( $status, $statuses, true ) ) {
                return array( $status, '' );
            }
        }

        return array( self::SKIPPED, in_array( self::CAUSE_CONSENT, array_column( $judged, 'cause' ), true ) ? self::CAUSE_CONSENT : self::CAUSE_PAUSED );
    }

    /**
     * The evaluated order.
     *
     * @param array   $order      Summary.
     * @param string  $status     Status.
     * @param string  $cause      Cause.
     * @param int     $settles_at When a waiting result becomes final, 0 when it is.
     * @param array[] $platforms  Platform entries.
     *
     * @return array
     */
    private static function result( array $order, $status, $cause, $settles_at, array $platforms ) {
        return array_merge(
            $order,
            array(
                'status'       => $status,
                'cause'        => $cause,
                'order_status' => (string) $order['status'],
                'settles_at'   => self::WAITING === $status ? (int) $settles_at : 0,
                'platforms'    => $platforms,
            )
        );
    }

    /**
     * When a row first checked in, as a Unix time.
     *
     * @param array $row Log row.
     *
     * @return int
     */
    private static function checked_in_at( array $row ) {
        $time = self::time( isset( $row['thankyou_seen_at'] ) ? $row['thankyou_seen_at'] : '' );

        return $time ? $time : self::time( isset( $row['created_at'] ) ? $row['created_at'] : '' );
    }

    /**
     * A GMT datetime from the table as a Unix time, 0 for none.
     *
     * @param mixed $datetime GMT datetime.
     *
     * @return int
     */
    private static function time( $datetime ) {
        $time = is_string( $datetime ) && '' !== $datetime ? strtotime( $datetime . ' UTC' ) : false;

        return false === $time ? 0 : (int) $time;
    }

    /**
     * The earlier of two Unix times, ignoring 0.
     *
     * @param int $a Unix time.
     * @param int $b Unix time.
     *
     * @return int
     */
    private static function earliest( $a, $b ) {
        return $a && $b ? min( $a, $b ) : max( $a, $b );
    }

    /**
     * Position of a status in RANK.
     *
     * @param string $status Status.
     *
     * @return int
     */
    private static function rank( $status ) {
        $rank = array_search( $status, self::RANK, true );

        return false === $rank ? count( self::RANK ) : (int) $rank;
    }
}
