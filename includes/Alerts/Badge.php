<?php

namespace StorePulse\ConversionTracking\Alerts;

use StorePulse\ConversionTracking\Log\Activity;
use StorePulse\ConversionTracking\Log\OrderLog;
use StorePulse\ConversionTracking\Log\Statuses;
use StorePulse\ConversionTracking\Platforms\Pause;

/**
 * The count bubble on the Conversion Tracking menu item: orders a platform didn't confirm in the last 24 hours.
 * Every admin page reads it, so it reads only the orders with a check-in that wasn't handed over (up to the Activity log's limit, newest first), and caches the count.
 */
class Badge {

    /**
     * Transient holding { count }.
     */
    const CACHE = 'wcct_alert_badge';

    /**
     * Seconds the count is cached; pauses and settings saves drop it sooner. Check-ins don't: one changes the count by at most one order, and busy stores check in every few seconds.
     */
    const CACHE_TTL = 60;

    /**
     * Counts above this show as "99+", as core's counts do.
     */
    const MAX_SHOWN = 99;

    /**
     * Menu slug of the Conversion Tracking page.
     */
    const MENU_SLUG = 'conversion-tracking';

    /**
     * Hook into WordPress.
     *
     * @return void
     */
    public static function register() {
        add_action( 'admin_menu', array( self::class, 'add_to_menu' ), 20 );
        add_action( Pause::CHANGED_ACTION, array( self::class, 'flush' ) );

        foreach ( Activity::SETTINGS_OPTIONS as $option ) {
            add_action( 'add_option_' . $option, array( self::class, 'flush' ) );
            add_action( 'update_option_' . $option, array( self::class, 'flush' ) );
        }
    }

    /**
     * Not confirmed orders now, or 0 while the alert is off.
     *
     * @return int
     */
    public static function count() {
        if ( ! Alerts::is_on( Types::NOT_CONFIRMED ) ) {
            return 0;
        }

        $cached = get_transient( self::CACHE );

        if ( is_array( $cached ) && isset( $cached['count'] ) ) {
            return max( 0, (int) $cached['count'] );
        }

        $now   = time();
        $count = count( self::not_confirmed_orders( $now ) );

        self::store( $count );

        return $count;
    }

    /**
     * Most orders read for the count: the Activity log's limit, so the badge and the dashboard count the same orders.
     *
     * @return int
     */
    public static function limit() {
        return Activity::limit();
    }

    /**
     * Cache a count the metrics route worked out, unless either side stopped at its limit (the counts could differ then).
     *
     * @param int      $count     Not confirmed orders.
     * @param bool     $truncated Whether the metrics route's read stopped at its limit.
     * @param int|null $now       Unix time, default now.
     *
     * @return bool Whether it was cached.
     */
    public static function remember( $count, $truncated = false, $now = null ) {
        $now = null === $now ? time() : (int) $now;

        // The badge's own read stops at the limit too; its count would be short.
        if ( $truncated || ( OrderLog::is_installed() && count( OrderLog::not_handed_over_since( $now - OrderLog::RETENTION, self::limit() ) ) >= self::limit() ) ) {
            return false;
        }

        self::store( $count );

        return true;
    }

    /**
     * Drop the cached count.
     *
     * @return void
     */
    public static function flush() {
        if ( function_exists( 'delete_transient' ) ) {
            delete_transient( self::CACHE );
        }
    }

    /**
     * IDs of the orders a platform didn't confirm, read from the check-ins that weren't handed over only.
     *
     * @param int $now Unix time.
     *
     * @return int[]
     */
    public static function not_confirmed_orders( $now ) {
        if ( ! function_exists( 'wc_get_order' ) || ! OrderLog::is_installed() ) {
            return array();
        }

        $ids = OrderLog::not_handed_over_since( (int) $now - OrderLog::RETENTION, self::limit() );

        if ( ! $ids ) {
            return array();
        }

        $rows = array();

        foreach ( OrderLog::for_orders( $ids ) as $row ) {
            $rows[ (int) $row['order_id'] ][] = $row;
        }

        $platforms = Activity::platforms();
        $found     = array();

        foreach ( $ids as $id ) {
            $order = wc_get_order( $id );

            if ( ! $order instanceof \WC_Order || ! isset( $rows[ $id ] ) ) {
                continue;
            }

            $result = Statuses::evaluate( Activity::summary( $order ), $rows[ $id ], $platforms['active'], $platforms['paused'], (int) $now );

            if ( $result && Statuses::NOT_CONFIRMED === $result['status'] ) {
                $found[] = $id;
            }
        }

        return $found;
    }

    /**
     * Add the count bubble to the menu item, for users who can open the page.
     *
     * @return void
     */
    public static function add_to_menu() {
        global $submenu;

        if ( ! is_array( $submenu ) || ! function_exists( 'wcct_manage_cap' ) || ! current_user_can( wcct_manage_cap() ) ) {
            return;
        }

        $count = self::count();

        if ( $count < 1 ) {
            return;
        }

        foreach ( $submenu as $parent => $items ) {
            foreach ( (array) $items as $index => $item ) {
                if ( isset( $item[0], $item[2] ) && self::MENU_SLUG === $item[2] ) {
                    // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- adding a count to our own menu item, as core does for comments.
                    $submenu[ $parent ][ $index ][0] .= self::bubble( $count );

                    return;
                }
            }
        }
    }

    /**
     * The count bubble markup, as core's awaiting-mod counts; kept on the label's line, and "99+" past 99.
     *
     * @param int $count Count.
     *
     * @return string
     */
    public static function bubble( $count ) {
        $count = max( 0, (int) $count );
        $shown = $count > self::MAX_SHOWN ? number_format_i18n( self::MAX_SHOWN ) . '+' : number_format_i18n( $count );
        /* translators: %s: number of purchases */
        $label = sprintf( _n( '%s purchase not confirmed', '%s purchases not confirmed', $count, 'woocommerce-conversion-tracking' ), number_format_i18n( $count ) );

        return sprintf(
            '&nbsp;<span class="awaiting-mod count-%1$d wcct-alert-badge"><span class="pending-count" aria-hidden="true">%2$s</span><span class="screen-reader-text">%3$s</span></span>',
            $count,
            esc_html( $shown ),
            esc_html( $label )
        );
    }

    /**
     * Cache a count.
     *
     * @param int $count Count.
     *
     * @return void
     */
    private static function store( $count ) {
        set_transient( self::CACHE, array( 'count' => max( 0, (int) $count ) ), self::CACHE_TTL );
    }
}
