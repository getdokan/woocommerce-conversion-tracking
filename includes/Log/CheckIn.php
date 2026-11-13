<?php

namespace StorePulse\ConversionTracking\Log;

use StorePulse\ConversionTracking\Events\PurchaseGate;
use StorePulse\ConversionTracking\Events\SentFlags;
use StorePulse\ConversionTracking\Platforms\Registry;

/**
 * A thank-you page check-in for one platform: the one writer of the sent flag (send again or not), the log row and the last purchase.
 */
class CheckIn {

    /**
     * REST route the browser checks in on (REST\EventsController).
     */
    const ROUTE = 'wcct/v1/events';

    /**
     * A row that wasn't handed over is updated at most once per this many seconds.
     */
    const THROTTLE = 60;

    /**
     * Consent states the browser reports: allowed, refused, or no consent layer on the page.
     */
    const CONSENT = array( 'granted', 'denied', 'off' );

    /**
     * Why a platform wasn't handed over: no hand-over within the wait, or the page was hidden first.
     */
    const REASONS = array( 'timeout', 'hidden' );

    /**
     * Order meta written on template_redirect when the thank-you page rendered.
     */
    const THANKYOU_META = '_wcct_thankyou_seen';

    /**
     * Results of record().
     */
    const RECORDED  = 'recorded';
    const UNCHANGED = 'unchanged';
    const SKIPPED   = 'skipped';

    /**
     * The check-in URL for the browser.
     *
     * @return string
     */
    public static function url() {
        return rest_url( self::ROUTE );
    }

    /**
     * Clean the check-in fields: loaded, consent, reason, event_id and load (the page load's token).
     *
     * @param array $input Raw fields.
     *
     * @return array
     */
    public static function normalise( array $input ) {
        $loaded   = isset( $input['loaded'] ) && in_array( $input['loaded'], array( true, 1, '1', 'true' ), true );
        $consent  = isset( $input['consent'] ) && in_array( $input['consent'], self::CONSENT, true ) ? $input['consent'] : 'off';
        $reason   = ! $loaded && isset( $input['reason'] ) && in_array( $input['reason'], self::REASONS, true ) ? $input['reason'] : '';
        $event_id = isset( $input['event_id'] ) && is_string( $input['event_id'] ) ? substr( preg_replace( '/[^A-Za-z0-9_\-]/', '', $input['event_id'] ), 0, 64 ) : '';
        $load     = isset( $input['load'] ) && is_string( $input['load'] ) ? substr( preg_replace( '/[^A-Za-z0-9]/', '', $input['load'] ), 0, 32 ) : '';

        return array(
            'loaded'   => $loaded,
            'consent'  => $consent,
            'reason'   => $reason,
            'event_id' => $event_id,
            'load'     => $load,
        );
    }

    /**
     * The check-in columns to write over an existing row, or null when nothing changes.
     *
     * @param array|null $row     Existing row, null for none.
     * @param array      $checkin Normalised check-in.
     * @param int        $now     Unix time.
     *
     * @return array|null
     */
    public static function merge( $row, array $checkin, $now ) {
        $checkin = self::normalise( $checkin );
        $stamp   = OrderLog::datetime( $now );

        // Handed over is final; later check-ins (a reload, a second tab) change nothing.
        if ( is_array( $row ) && ! empty( $row['library_loaded'] ) ) {
            return null;
        }

        // A timeout after a hidden page is stronger evidence, so it isn't throttled.
        $upgrade = isset( $row['reason'] ) && 'hidden' === $row['reason'] && 'timeout' === $checkin['reason'];

        if ( is_array( $row ) && ! $checkin['loaded'] && ! $upgrade && isset( $row['updated_at'] ) && $now - (int) strtotime( $row['updated_at'] . ' UTC' ) < self::THROTTLE ) {
            return null;
        }

        return array(
            'library_loaded' => $checkin['loaded'] ? 1 : 0,
            'sent_at'        => $checkin['loaded'] ? $stamp : null,
            'consent'        => $checkin['consent'],
            'reason'         => $checkin['reason'],
            'event_id'       => $checkin['event_id'],
            'load_id'        => '' !== $checkin['load'] ? $checkin['load'] : null,
            'updated_at'     => $stamp,
        );
    }

    /**
     * Record a check-in for an order and platform whose key was already checked.
     *
     * @param \WC_Order $order    Order.
     * @param string    $platform Platform ID.
     * @param array     $input    Check-in fields, see normalise().
     * @param int|null  $now      Unix time, default now.
     *
     * @return string One of the result constants.
     */
    public static function record( $order, $platform, array $input, $now = null ) {
        $now = null === $now ? time() : (int) $now;

        // Failed and cancelled orders never send, so they never check in.
        if ( ! PurchaseGate::status_sends( $order->get_status() ) ) {
            return self::SKIPPED;
        }

        $checkin = self::normalise( $input );
        $row     = OrderLog::get( $order->get_id(), $platform );
        $changes = self::merge( $row, $checkin, $now );

        // A hand-over from another page load means the platform took this Purchase twice; the admin-ajax retry of the same load is not one.
        if ( self::is_repeat( $row, $checkin ) ) {
            RepeatSends::record( $order->get_id(), $platform, $now );
        }

        // The flag follows the flag and the row follows the row: a hand-over always makes sure the flag is there (mark_sent() is idempotent).
        $flagged = $checkin['loaded'] && SentFlags::mark_sent( $order, $platform );

        if ( $flagged || ( $changes && $changes['library_loaded'] ) ) {
            LastPurchase::record( $platform, $order, $now );
        }

        if ( null !== $changes ) {
            OrderLog::upsert( array_merge( self::order_columns( $order, $now ), $changes, array( 'platform' => (string) $platform, 'created_at' => OrderLog::datetime( $now ) ) ) );
        }

        if ( $flagged || null !== $changes ) {
            Activity::flush();

            return self::RECORDED;
        }

        return self::UNCHANGED;
    }

    /**
     * Validate a check-in request and record it; shared by the REST route and the admin-ajax fallback.
     *
     * @param array    $params     Request fields: order_id, key, platform, loaded, consent, reason, event_id, load.
     * @param string[] $platforms  Active platform IDs.
     * @param callable $find_order Receives ( order ID, key ), returns the order or false.
     * @param callable $record     Receives ( order, platform, fields ), returns a result constant.
     *
     * @return array status (HTTP), code (error code or empty) and result.
     */
    public static function process( array $params, array $platforms, callable $find_order, callable $record ) {
        $platform = isset( $params['platform'] ) && is_string( $params['platform'] ) ? $params['platform'] : '';

        if ( '' === $platform || ! preg_match( '/^[A-Za-z0-9_\-]{1,32}$/', $platform ) || ! in_array( $platform, $platforms, true ) ) {
            return array( 'status' => 400, 'code' => 'wcct_rest_unknown_platform', 'result' => '' );
        }

        $order_id = isset( $params['order_id'] ) && is_numeric( $params['order_id'] ) ? (int) $params['order_id'] : 0;
        $order    = $order_id > 0 ? call_user_func( $find_order, $order_id, isset( $params['key'] ) ? $params['key'] : '' ) : false;

        if ( ! $order ) {
            return array( 'status' => 403, 'code' => 'wcct_rest_invalid_order', 'result' => '' );
        }

        $fields = array_intersect_key( $params, array_flip( array( 'loaded', 'consent', 'reason', 'event_id', 'load' ) ) );

        return array( 'status' => 200, 'code' => '', 'result' => (string) call_user_func( $record, $order, $platform, $fields ) );
    }

    /**
     * Whether a check-in hands over a Purchase that another page load already handed over.
     *
     * @param array|null $row     Existing row, null for none.
     * @param array      $checkin Check-in fields.
     *
     * @return bool
     */
    public static function is_repeat( $row, array $checkin ) {
        $checkin = self::normalise( $checkin );
        $before  = is_array( $row ) && isset( $row['load_id'] ) && is_string( $row['load_id'] ) ? $row['load_id'] : '';

        // Without both tokens (rows from before the token, old cached pages) we can't tell, so it isn't counted.
        return $checkin['loaded'] && is_array( $row ) && ! empty( $row['library_loaded'] ) && '' !== $before && '' !== $checkin['load'] && $before !== $checkin['load'];
    }

    /**
     * IDs of the active platforms that send in the browser.
     *
     * @return string[]
     */
    public static function platform_ids() {
        $platforms = function_exists( 'wcct_init' ) && isset( wcct_init()->manager ) ? wcct_init()->manager->get_active_integrations() : array();

        return array_map( 'strval', array_keys( Registry::browser( Registry::active( Registry::normalise( $platforms ) ) ) ) );
    }

    /**
     * When the thank-you page rendered for the order, as a Unix time; 0 when it didn't.
     *
     * @param \WC_Order|mixed $order Order.
     *
     * @return int
     */
    public static function thankyou_seen( $order ) {
        $seen = is_object( $order ) && method_exists( $order, 'get_meta' ) ? $order->get_meta( self::THANKYOU_META, true ) : '';

        return is_numeric( $seen ) ? (int) $seen : 0;
    }

    /**
     * The order's columns, read through WooCommerce CRUD; no customer data.
     *
     * @param \WC_Order $order Order.
     * @param int       $now   Unix time.
     *
     * @return array
     */
    private static function order_columns( $order, $now ) {
        $created = $order->get_date_created();
        $seen    = self::thankyou_seen( $order );

        return array(
            'order_id'         => (int) $order->get_id(),
            'source'           => OrderLog::SOURCE_BROWSER,
            'status'           => (string) $order->get_status(),
            'total'            => (string) wc_format_decimal( $order->get_total(), 8 ),
            'currency'         => substr( (string) $order->get_currency(), 0, 3 ),
            'created_via'      => substr( (string) $order->get_created_via(), 0, 64 ),
            'order_created_at' => $created instanceof \DateTimeInterface ? OrderLog::datetime( $created->getTimestamp() ) : null,
            // A check-in proves the page rendered, also where the server flag didn't run (funnel pages).
            'thankyou_seen_at' => OrderLog::datetime( $seen ? $seen : $now ),
        );
    }
}
