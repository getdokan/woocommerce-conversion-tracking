<?php

namespace StorePulse\ConversionTracking\Log;

use StorePulse\ConversionTracking\Events\PurchaseGate;
use StorePulse\ConversionTracking\Platforms\Pause;
use StorePulse\ConversionTracking\Platforms\Registry;

/**
 * The last 24 hours of orders with their statuses (Statuses), read through WooCommerce CRUD; the Activity log, the dashboard and the alerts read it.
 */
class Activity {

    /**
     * Most orders read per request; the newest are kept.
     */
    const LIMIT = 500;

    /**
     * Transient holding the evaluated list for callers other than the log route.
     */
    const CACHE = 'wcct_log_activity';

    /**
     * Seconds the cached list is used.
     */
    const CACHE_TTL = 60;

    /**
     * Results per Unix time (or 'now'), for the rest of the request.
     *
     * @var array[]
     */
    private static $memo = array();

    /**
     * Whether the last evaluation stopped at the order limit.
     *
     * @var bool
     */
    private static $truncated = false;

    /**
     * Drop the cached list when check-ins, pauses or settings change.
     *
     * @return void
     */
    public static function init_hooks() {
        add_action( Pause::CHANGED_ACTION, array( self::class, 'flush' ) );

        foreach ( array( 'wcct_settings_v3', 'wcct_settings' ) as $option ) {
            add_action( 'add_option_' . $option, array( self::class, 'flush' ) );
            add_action( 'update_option_' . $option, array( self::class, 'flush' ) );
        }
    }

    /**
     * Orders of the window the log lists (created or checked in in it), newest first, evaluated; without a time from the 60-second cache, fresh() for the log route.
     *
     * @param int|null $now Unix time; null for the cached list.
     *
     * @return array[]
     */
    public static function orders( $now = null ) {
        if ( null === $now ) {
            return self::cached();
        }

        $key = (string) (int) $now;

        if ( ! isset( self::$memo[ $key ] ) ) {
            self::$memo[ $key ] = self::evaluate( (int) $now );
        }

        return self::$memo[ $key ];
    }

    /**
     * A fresh read now, for the Activity log route.
     *
     * @return array[]
     */
    public static function fresh() {
        self::reset();

        return self::orders( time() );
    }

    /**
     * Orders under a tab.
     *
     * @param string   $tab Tab (Statuses::TAB_*).
     * @param int|null $now Unix time, default now.
     *
     * @return array[]
     */
    public static function in_tab( $tab, $now = null ) {
        return array_values(
            array_filter(
                self::orders( $now ),
                function ( $order ) use ( $tab ) {
                    return Statuses::in_tab( $order, $tab );
                }
            )
        );
    }

    /**
     * Whether the last evaluation in this request stopped at the order limit, so its counts may be short.
     *
     * @return bool
     */
    public static function truncated() {
        return self::$truncated;
    }

    /**
     * Orders a platform didn't confirm, for the dashboard badge and card.
     *
     * @param int|null $now Unix time, default now.
     *
     * @return array[]
     */
    public static function not_confirmed( $now = null ) {
        return self::in_tab( Statuses::TAB_NOT_CONFIRMED, $now );
    }

    /**
     * Forget the results of this request, after orders or rows changed.
     *
     * @return void
     */
    public static function reset() {
        self::$memo = array();
    }

    /**
     * Forget the results of this request and the cached list.
     *
     * @return void
     */
    public static function flush() {
        self::reset();

        if ( function_exists( 'delete_transient' ) ) {
            delete_transient( self::CACHE );
        }

        /**
         * Fires after the cached Activity list is dropped (a check-in, a pause or a settings save).
         *
         * @since 3.0.0
         */
        do_action( 'wcct_activity_flushed' );
    }

    /**
     * The order fields Statuses reads; no customer data.
     *
     * @param \WC_Order $order Order.
     *
     * @return array
     */
    public static function summary( $order ) {
        $created = $order->get_date_created();
        $paid    = $order->get_date_paid();

        return array(
            'id'             => (int) $order->get_id(),
            'number'         => (string) $order->get_order_number(),
            'status'         => (string) $order->get_status(),
            'created_via'    => (string) $order->get_created_via(),
            'created'        => $created instanceof \DateTimeInterface ? $created->getTimestamp() : 0,
            'paid'           => $paid instanceof \DateTimeInterface ? $paid->getTimestamp() : 0,
            'thankyou'       => CheckIn::thankyou_seen( $order ),
            'total'          => (string) $order->get_total(),
            'currency'       => (string) $order->get_currency(),
            'renewal'        => self::is_renewal( $order ),
            'counted_before' => PurchaseGate::counted_before( $order ),
        );
    }

    /**
     * Integration IDs that check in now (active) and the set-up ones that are paused (Platforms\Pause); browser platforms only.
     *
     * @return array active (list of integration IDs) and paused (integration ID => when the pause started, 0 for unknown).
     */
    public static function platforms() {
        $all     = function_exists( 'wcct_init' ) && isset( wcct_init()->manager ) ? wcct_init()->manager->get_integrations() : array();
        $browser = Registry::browser( Registry::normalise( $all ) );
        $paused  = array();

        foreach ( $browser as $id => $platform ) {
            if ( $platform->is_enabled() && Pause::is_paused( (string) $id ) ) {
                $paused[ (string) $id ] = Pause::paused_since( (string) $id );
            }
        }

        return array(
            'active' => array_map( 'strval', array_keys( Registry::active( $browser ) ) ),
            'paused' => $paused,
        );
    }

    /**
     * Display names per registered platform; Google is one platform.
     *
     * @return string[] Platform ID => name.
     */
    public static function platform_names() {
        $all   = function_exists( 'wcct_init' ) && isset( wcct_init()->manager ) ? wcct_init()->manager->get_integrations() : array();
        $names = array();

        foreach ( Registry::normalise( $all ) as $id => $platform ) {
            $names[ Statuses::platform_of( $id ) ] = (string) $platform->get_name();
        }

        // The 3.0 names; the integrations keep their 2.x names for the classic screens.
        $known = array(
            'facebook' => __( 'Meta', 'woocommerce-conversion-tracking' ),
            'google'   => __( 'Google', 'woocommerce-conversion-tracking' ),
            'twitter'  => __( 'X', 'woocommerce-conversion-tracking' ),
            'bing_ads' => __( 'Microsoft Advertising', 'woocommerce-conversion-tracking' ),
            'custom'   => __( 'Custom script', 'woocommerce-conversion-tracking' ),
        );

        return array_merge( $names, array_intersect_key( $known, $names ) );
    }

    /**
     * The list from the cache, or evaluated now and cached.
     *
     * @return array[]
     */
    private static function cached() {
        if ( isset( self::$memo['now'] ) ) {
            return self::$memo['now'];
        }

        $now    = time();
        $cached = get_transient( self::CACHE );

        if ( is_array( $cached ) && isset( $cached['at'], $cached['orders'] ) && is_array( $cached['orders'] ) && $now - (int) $cached['at'] < self::CACHE_TTL ) {
            $list = $cached['orders'];
        } else {
            $list = self::evaluate( $now );
            set_transient( self::CACHE, array( 'at' => $now, 'orders' => $list ), self::CACHE_TTL );
        }

        self::$memo['now'] = $list;

        return $list;
    }

    /**
     * Evaluate the orders of the window.
     *
     * @param int $now Unix time.
     *
     * @return array[]
     */
    private static function evaluate( $now ) {
        $since     = $now - OrderLog::RETENTION;
        $orders    = self::recent( $since );
        $orders   += self::checked_in_since( $since, $orders );
        $rows      = self::rows( array_keys( $orders ) );

        self::$truncated = count( $orders ) >= self::limit();

        $platforms = self::platforms();
        $list      = array();

        foreach ( $orders as $id => $order ) {
            $result = Statuses::evaluate( self::summary( $order ), isset( $rows[ $id ] ) ? $rows[ $id ] : array(), $platforms['active'], $platforms['paused'], $now );

            if ( $result ) {
                $list[] = $result;
            }
        }

        // Newest first, also for orders created before the window and paid in it.
        usort(
            $list,
            function ( $a, $b ) {
                return $b['created'] <=> $a['created'] ?: $b['id'] <=> $a['id'];
            }
        );

        return $list;
    }

    /**
     * Orders created before the window that checked in in it, keyed by ID.
     *
     * @param int         $since  Unix time.
     * @param \WC_Order[] $loaded Orders already read, by ID.
     *
     * @return \WC_Order[]
     */
    private static function checked_in_since( $since, array $loaded ) {
        if ( ! OrderLog::is_installed() || ! function_exists( 'wc_get_order' ) ) {
            return array();
        }

        $orders = array();

        foreach ( array_unique( array_map( 'intval', array_column( OrderLog::since( $since ), 'order_id' ) ) ) as $id ) {
            if ( isset( $loaded[ $id ] ) || count( $loaded ) + count( $orders ) >= self::limit() ) {
                continue;
            }

            $order = wc_get_order( $id );

            if ( $order instanceof \WC_Order ) {
                $orders[ $id ] = $order;
            }
        }

        return $orders;
    }

    /**
     * Most orders read per request (filter wcct_log_order_limit).
     *
     * @return int
     */
    public static function limit() {
        /**
         * Most orders the Activity log reads per request, newest first.
         *
         * @since 3.0.0
         *
         * @param int $limit Default 500.
         */
        return max( 1, (int) apply_filters( 'wcct_log_order_limit', self::LIMIT ) );
    }

    /**
     * Orders created at or after a Unix time, newest first, keyed by ID.
     *
     * @param int $since Unix time.
     *
     * @return \WC_Order[]
     */
    private static function recent( $since ) {
        if ( ! function_exists( 'wc_get_orders' ) ) {
            return array();
        }

        $limit  = self::limit();
        $orders = wc_get_orders(
            array(
                'type'         => 'shop_order',
                'date_created' => '>=' . (int) $since,
                'orderby'      => 'date',
                'order'        => 'DESC',
                'limit'        => $limit,
            )
        );
        $keyed  = array();

        foreach ( is_array( $orders ) ? $orders : array() as $order ) {
            if ( $order instanceof \WC_Order ) {
                $keyed[ (int) $order->get_id() ] = $order;
            }
        }

        return $keyed;
    }

    /**
     * Log rows grouped by order ID.
     *
     * @param int[] $order_ids Order IDs.
     *
     * @return array[]
     */
    private static function rows( array $order_ids ) {
        if ( ! $order_ids || ! OrderLog::is_installed() ) {
            return array();
        }

        $grouped = array();

        foreach ( OrderLog::for_orders( $order_ids ) as $row ) {
            $grouped[ (int) $row['order_id'] ][] = $row;
        }

        return $grouped;
    }

    /**
     * Whether the order is a subscription renewal.
     *
     * @param \WC_Order $order Order.
     *
     * @return bool
     */
    private static function is_renewal( $order ) {
        if ( function_exists( 'wcs_order_contains_renewal' ) && wcs_order_contains_renewal( $order ) ) {
            return true;
        }

        return '' !== (string) $order->get_meta( '_subscription_renewal', true );
    }
}
