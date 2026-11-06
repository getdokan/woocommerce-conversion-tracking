<?php

namespace StorePulse\ConversionTracking\Log;

/**
 * Daily Action Scheduler job that deletes order log rows older than 24 hours.
 */
class Prune {

    /**
     * Action hook the job runs on.
     */
    const HOOK = 'wcct_prune_order_log';

    /**
     * Action Scheduler group for the plugin's jobs.
     */
    const GROUP = 'wcct';

    /**
     * Hook into WordPress.
     *
     * @return void
     */
    public static function init_hooks() {
        add_action( self::HOOK, array( self::class, 'run' ) );
        // Front-end traffic too, so a store nobody opens wp-admin on still prunes; the check is one indexed query.
        add_action( 'action_scheduler_init', array( self::class, 'schedule' ) );
        add_action( 'admin_init', array( self::class, 'schedule' ) );
        add_action( 'woocommerce_order_status_changed', array( self::class, 'order_status_changed' ), 10, 3 );
    }

    /**
     * Delete the old rows.
     *
     * @return void
     */
    public static function run() {
        OrderLog::prune();
    }

    /**
     * Schedule the daily job when it isn't; returns whether it is scheduled.
     *
     * @return bool
     */
    public static function schedule() {
        if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
            return false;
        }

        if ( as_has_scheduled_action( self::HOOK, array(), self::GROUP ) ) {
            return true;
        }

        return (bool) as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::HOOK, array(), self::GROUP );
    }

    /**
     * Remove the job, on deactivation.
     *
     * @return void
     */
    public static function unschedule() {
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( self::HOOK, array(), self::GROUP );
        }
    }

    /**
     * Keep the rows' order status current.
     *
     * @param int    $order_id Order ID.
     * @param string $from     Old status.
     * @param string $to       New status.
     *
     * @return void
     */
    public static function order_status_changed( $order_id, $from, $to ) {
        OrderLog::update_status( $order_id, $to );
    }
}
