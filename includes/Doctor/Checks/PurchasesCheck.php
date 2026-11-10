<?php

namespace StorePulse\ConversionTracking\Doctor\Checks;

use StorePulse\ConversionTracking\Doctor\AbstractCheck;
use StorePulse\ConversionTracking\Doctor\Check;
use StorePulse\ConversionTracking\Doctor\Context;
use StorePulse\ConversionTracking\Doctor\Finding;
use StorePulse\ConversionTracking\Doctor\Platforms;
use StorePulse\ConversionTracking\Events\PurchaseGate;
use StorePulse\ConversionTracking\Log\OrderLog;
use StorePulse\ConversionTracking\Log\RepeatSends;

/**
 * The last 24 hours' purchases: sent, none twice, and whether shoppers' browsers can reach the check-in route.
 * Remote: it also asks the check-in route as a logged-out visitor, so the quiet re-check after an activation skips it.
 */
class PurchasesCheck extends AbstractCheck {

    /**
     * A check-in not handed over within this many seconds is "Not confirmed"; same as Log\Statuses::SETTLE (#113).
     */
    const SETTLE = 600;

    /**
     * Orders read at most.
     */
    const MAX_ORDERS = 1000;

    /**
     * Orders that never reach a thank-you page from a shopper's browser; same list as Log\Statuses::SKIPPED_VIA (#113).
     */
    const EXCLUDED_CREATED_VIA = array( 'admin', 'pos', 'pos-rest-api', 'woocommerce-pos', 'rest-api', 'subscription' );

    /**
     * Statuses per row, see status(); the same strings as Log\Statuses (#113), so switching to it is a small change.
     */
    const CONFIRMED     = 'confirmed';
    const NOT_CONFIRMED = 'not_confirmed';
    const PENDING       = 'pending';
    const SKIPPED       = 'skipped';

    /**
     * Working line, set by run().
     *
     * @var string
     */
    private $working = '';

    /**
     * Check ID.
     *
     * @return string
     */
    public function id() {
        return 'purchases-24h';
    }

    /**
     * Remote: it makes one request to this site.
     *
     * @return string
     */
    public function scope() {
        return Check::REMOTE;
    }

    /**
     * Working line: "No purchases in the last 24 hours" when there were none.
     *
     * @return string
     */
    public function label() {
        return '' !== $this->working ? $this->working : __( 'Purchases in the last 24 hours were sent, and none of them twice by this plugin', 'woocommerce-conversion-tracking' );
    }

    /**
     * Read the order log of the last 24 hours and ask the check-in route.
     *
     * @param Context $context Run context.
     *
     * @return Finding[]
     */
    public function run( Context $context ) {
        $now     = time();
        $orders  = self::orders( $now );
        $rows    = $orders && OrderLog::is_installed() ? OrderLog::for_orders( array_keys( $orders ) ) : array();
        $repeats = RepeatSends::since( $now - OrderLog::RETENTION );
        $summary = self::summarise( $orders, $rows, $repeats, $now );

        return $this->report( $summary, Platforms::from_context( $context ), self::route_answer( $context ) );
    }

    /**
     * Findings and the Working line from a summary.
     *
     * @param array      $summary From summarise().
     * @param array      $states  Platform states, for platform names.
     * @param array|null $route   From route_answer(); null when the route works or we couldn't tell.
     *
     * @return Finding[]
     */
    public function report( array $summary, array $states, $route = null ) {
        $findings = array();

        if ( 0 === $summary['orders'] ) {
            $this->working = __( 'No purchases in the last 24 hours', 'woocommerce-conversion-tracking' );
        } elseif ( $summary['sent'] > 0 ) {
            $this->working = '';
        } elseif ( $summary['pending'] > 0 ) {
            $this->working = __( 'The latest purchases are still being confirmed; check again in a few minutes', 'woocommerce-conversion-tracking' );
        } else {
            $this->working = __( 'No purchase in the last 24 hours has checked in from the thank-you page yet', 'woocommerce-conversion-tracking' );
        }

        if ( $summary['not_confirmed'] ) {
            $findings[] = $this->not_confirmed( $summary['not_confirmed'], $states );
        }

        if ( $summary['repeats'] ) {
            $findings[] = $this->repeats( $summary['repeats'], $states );
        }

        if ( $route ) {
            $findings[] = $this->route_blocked( $route );
        }

        return $findings;
    }

    /**
     * Count the 24 hours: orders, orders sent to at least one platform, not confirmed and repeated pairs.
     *
     * @param array $orders  Order ID => { created: Unix time }.
     * @param array $rows    Order log rows of those orders.
     * @param array $repeats From RepeatSends::since().
     * @param int   $now     Unix time.
     *
     * @return array { orders: int, sent: int, pending: int (orders with a check-in still in its window), not_confirmed: [ order_id:platform ], repeats: [ order_id:platform ] }.
     */
    public static function summarise( array $orders, array $rows, array $repeats, $now ) {
        $sent          = array();
        $pending       = array();
        $not_confirmed = array();
        $repeated      = array();

        foreach ( $rows as $row ) {
            $order_id = isset( $row['order_id'] ) ? (int) $row['order_id'] : 0;

            if ( ! isset( $orders[ $order_id ] ) ) {
                continue;
            }

            $status = self::status( $orders[ $order_id ], $row, $now );
            $pair   = $order_id . ':' . ( isset( $row['platform'] ) ? (string) $row['platform'] : '' );

            if ( self::CONFIRMED === $status ) {
                $sent[ $order_id ] = true;
            } elseif ( self::NOT_CONFIRMED === $status ) {
                $not_confirmed[] = $pair;
            } elseif ( self::PENDING === $status ) {
                $pending[ $order_id ] = true;
            }
        }

        foreach ( $repeats as $repeat ) {
            $repeated[] = (int) $repeat['order_id'] . ':' . (string) $repeat['platform'];
        }

        sort( $not_confirmed );
        sort( $repeated );

        return array(
            'orders'        => count( $orders ),
            'sent'          => count( $sent ),
            'pending'       => count( $pending ),
            'not_confirmed' => array_values( array_unique( $not_confirmed ) ),
            'repeats'       => array_values( array_unique( $repeated ) ),
        );
    }

    /**
     * Status of one order log row.
     * Seam for #113: whichever of #113 and #116 lands second makes this Log\Statuses::for_order() (or reads
     * Log\Activity::orders()) and drops these rules. The 10 minutes run from the check-in, as in #113.
     *
     * @param array $order { created: Unix time }.
     * @param array $row   Order log row.
     * @param int   $now   Unix time.
     *
     * @return string One of the status constants.
     */
    public static function status( array $order, array $row, $now ) {
        if ( ! empty( $row['library_loaded'] ) ) {
            return self::CONFIRMED;
        }

        // Declined cookies: nothing is handed over before consent, by design.
        if ( isset( $row['consent'] ) && 'denied' === $row['consent'] ) {
            return self::SKIPPED;
        }

        return $now - self::checked_in_at( $order, $row ) < self::SETTLE ? self::PENDING : self::NOT_CONFIRMED;
    }

    /**
     * When a row's check-in started, as a Unix time: the thank-you page time, else the row's creation, else the order's.
     *
     * @param array $order { created: Unix time }.
     * @param array $row   Order log row.
     *
     * @return int
     */
    private static function checked_in_at( array $order, array $row ) {
        foreach ( array( 'thankyou_seen_at', 'created_at' ) as $column ) {
            $time = ! empty( $row[ $column ] ) && is_string( $row[ $column ] ) ? strtotime( $row[ $column ] . ' UTC' ) : false;

            if ( $time ) {
                return (int) $time;
            }
        }

        return isset( $order['created'] ) ? (int) $order['created'] : 0;
    }

    /**
     * Shopper orders of the last 24 hours whose status sends a Purchase, through WooCommerce CRUD.
     *
     * @param int $now Unix time.
     *
     * @return array Order ID => { created: Unix time }.
     */
    public static function orders( $now ) {
        if ( ! function_exists( 'wc_get_orders' ) || ! function_exists( 'wc_get_order_statuses' ) ) {
            return array();
        }

        $statuses = array_values( array_filter( array_keys( wc_get_order_statuses() ), array( PurchaseGate::class, 'status_sends' ) ) );

        if ( ! $statuses ) {
            return array();
        }

        $found = wc_get_orders(
            array(
                'type'         => 'shop_order',
                'status'       => $statuses,
                'date_created' => '>=' . ( (int) $now - OrderLog::RETENTION ),
                'limit'        => self::MAX_ORDERS,
                'orderby'      => 'date',
                'order'        => 'DESC',
            )
        );

        $orders = array();

        /**
         * Filter how orders are created that never reach a thank-you page in the shopper's browser; the Doctor leaves them out.
         *
         * @since 3.0.0
         *
         * @param string[] $excluded created_via values.
         */
        $excluded = (array) apply_filters( 'wcct_doctor_excluded_created_via', self::EXCLUDED_CREATED_VIA );

        foreach ( is_array( $found ) ? $found : array() as $order ) {
            if ( ! is_object( $order ) || ! method_exists( $order, 'get_created_via' ) || in_array( (string) $order->get_created_via(), $excluded, true ) ) {
                continue;
            }

            $created = $order->get_date_created();

            $orders[ (int) $order->get_id() ] = array(
                'created' => $created instanceof \DateTimeInterface ? $created->getTimestamp() : (int) $now,
            );
        }

        return $orders;
    }

    /**
     * Ask the check-in route as a logged-out visitor with a token that can't exist: the route answers 403 wcct_rest_invalid_token.
     *
     * @param Context $context Run context.
     *
     * @return array|null { code, error } when something else answered; null when the route works or we couldn't tell.
     */
    public static function route_answer( Context $context ) {
        $response = $context->loopback(
            'POST',
            '/wcct/v1/events',
            array(
                'body' => array(
                    'type'  => 'seen',
                    'token' => 'x',
                ),
            )
        );

        // No answer (loopback requests off, time budget spent) or a redirect (not followed): we can't tell.
        if ( 0 === $response['code'] || ( $response['code'] >= 300 && $response['code'] < 400 ) ) {
            return null;
        }

        $body = json_decode( $response['body'], true );
        $code = is_array( $body ) && isset( $body['code'] ) && is_string( $body['code'] ) ? $body['code'] : '';

        // Our own answer to an unknown token; a rate limit means the request got through to the site too.
        if ( ( 403 === $response['code'] && 'wcct_rest_invalid_token' === $code ) || 429 === $response['code'] ) {
            return null;
        }

        return array(
            'code'  => $response['code'],
            'error' => substr( (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', $code ), 0, 64 ),
        );
    }

    /**
     * Platform names of some order:platform pairs.
     *
     * @param string[] $pairs  Pairs.
     * @param array    $states Platform states.
     *
     * @return string[]
     */
    private static function names( array $pairs, array $states ) {
        $names = array();

        foreach ( $pairs as $pair ) {
            $parts   = explode( ':', $pair, 2 );
            $names[] = Platforms::label_for( $states, isset( $parts[1] ) ? $parts[1] : '' );
        }

        return array_values( array_unique( $names ) );
    }

    /**
     * Order IDs in some order:platform pairs, sorted.
     *
     * @param string[] $pairs Pairs.
     *
     * @return int[]
     */
    private static function order_ids( array $pairs ) {
        $orders = array();

        foreach ( $pairs as $pair ) {
            $orders[ (int) strtok( $pair, ':' ) ] = true;
        }

        $orders = array_keys( $orders );
        sort( $orders );

        return $orders;
    }

    /**
     * A list of names for a sentence: "Meta, X and Google".
     *
     * @param string[] $names Names.
     *
     * @return string
     */
    private static function sentence( array $names ) {
        return function_exists( 'wp_sprintf_l' ) ? wp_sprintf_l( '%l', $names ) : implode( ', ', $names );
    }

    /**
     * Purchases the thank-you page checked in but a platform didn't confirm.
     *
     * @param string[] $pairs  order_id:platform.
     * @param array    $states Platform states.
     *
     * @return Finding
     */
    private function not_confirmed( array $pairs, array $states ) {
        $orders = self::order_ids( $pairs );
        $count  = count( $orders );
        $names  = self::names( $pairs, $states );

        return $this->finding(
            array(
                'key'        => 'not-confirmed',
                /* translators: %d: number of purchases */
                'found'      => sprintf( _n( 'We couldn\'t confirm %d purchase from the last 24 hours', 'We couldn\'t confirm %d purchases from the last 24 hours', $count, 'woocommerce-conversion-tracking' ), $count ),
                /* translators: %s: platform names, for example "Meta, X" */
                'why'        => sprintf( __( 'The thank-you page loaded, but we couldn\'t confirm the purchase was sent to %s. The Activity log shows each order.', 'woocommerce-conversion-tracking' ), self::sentence( $names ) ),
                'label'      => __( 'Purchases', 'woocommerce-conversion-tracking' ),
                'tags'       => $names,
                'confidence' => Finding::SURE,
                'action'     => array(
                    'label' => __( 'Open the Activity log', 'woocommerce-conversion-tracking' ),
                    'url'   => '#/log',
                ),
                'evidence'   => $orders,
            )
        );
    }

    /**
     * Purchases a platform took twice.
     *
     * @param string[] $pairs  order_id:platform.
     * @param array    $states Platform states.
     *
     * @return Finding
     */
    private function repeats( array $pairs, array $states ) {
        $orders = self::order_ids( $pairs );
        $count  = count( $orders );
        $names  = self::names( $pairs, $states );

        return $this->finding(
            array(
                'key'        => 'repeats',
                /* translators: %d: number of purchases */
                'found'      => sprintf( _n( '%d purchase was sent twice in the last 24 hours', '%d purchases were sent twice in the last 24 hours', $count, 'woocommerce-conversion-tracking' ), $count ),
                /* translators: %s: platform names, for example "Meta, X" */
                'why'        => sprintf( __( 'The thank-you page sent the same order to %s twice, for example when it loaded twice before the first send was confirmed. Your results may count these sales twice.', 'woocommerce-conversion-tracking' ), self::sentence( $names ) ),
                'label'      => __( 'Duplicate', 'woocommerce-conversion-tracking' ),
                'tags'       => $names,
                'confidence' => Finding::SURE,
                'action'     => array(
                    'label' => __( 'Open the Activity log', 'woocommerce-conversion-tracking' ),
                    'url'   => '#/log',
                ),
                'evidence'   => $orders,
            )
        );
    }

    /**
     * Good to know: the check-in route refuses logged-out visitors, so check-ins take the backup route.
     *
     * @param array $route { code, error }.
     *
     * @return Finding
     */
    private function route_blocked( array $route ) {
        return $this->finding(
            array(
                'key'        => 'route',
                'found'      => __( 'Purchase check-ins use the backup route', 'woocommerce-conversion-tracking' ),
                'why'        => __( 'Your site refuses the check-in address (wp-json/wcct/v1/events) for logged-out visitors, often because of a security plugin. Check-ins then take the backup route through admin-ajax, so purchases are still confirmed; if that is blocked too, they show as not confirmed.','woocommerce-conversion-tracking' ),
                'label'      => __( 'Check-ins', 'woocommerce-conversion-tracking' ),
                'tags'       => array( __( 'All platforms', 'woocommerce-conversion-tracking' ) ),
                'severity'   => Finding::INFO,
                'confidence' => Finding::UNSURE,
                'steps'      => array(
                    __( 'In your security plugin, allow logged-out visitors to reach wp-json/wcct/v1/events.', 'woocommerce-conversion-tracking' ),
                    __( 'Then check again.', 'woocommerce-conversion-tracking' ),
                ),
                'action'     => array(
                    'type'  => Finding::ACTION_RECHECK,
                    'label' => __( 'Check again', 'woocommerce-conversion-tracking' ),
                ),
                'evidence'   => $route,
            )
        );
    }
}
