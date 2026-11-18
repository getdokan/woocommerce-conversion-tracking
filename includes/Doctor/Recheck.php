<?php

namespace StorePulse\ConversionTracking\Doctor;

use StorePulse\ConversionTracking\Alerts\Alerts;
use StorePulse\ConversionTracking\Platforms\Pause;

/**
 * The quiet re-check of the local checks: after a plugin is activated (once, at the end of the request), after one is deactivated (in a cron request, once its code is gone), and after a settings save, a pause or resume, or wcct_doctor_refresh (at once, so the response is up to date).
 */
class Recheck {

    /**
     * Cron hook of the re-check a deactivation schedules.
     */
    const CRON = 'wcct_doctor_recheck';

    /**
     * Whether a re-check is queued for this request.
     *
     * @var bool
     */
    private $queued = false;

    /**
     * Hook into WordPress.
     *
     * @return void
     */
    public function register() {
        add_action( 'activated_plugin', array( $this, 'queue' ) );
        add_action( 'deactivated_plugin', array( $this, 'queue_next' ) );
        add_action( self::CRON, array( $this, 'refresh' ), 10, 0 );
        // After the consent sync (priority 10): it mirrors consent mode into the option the consent check reads.
        add_action( 'wcct_settings_saved', array( $this, 'refresh' ), 20, 0 );
        add_action( Pause::CHANGED_ACTION, array( $this, 'refresh' ), 10, 0 );

        /**
         * Re-check the Doctor's local checks now, after saving something they read outside the settings (for example Pro's catalog).
         *
         * @since 3.0.0
         */
        add_action( 'wcct_doctor_refresh', array( $this, 'refresh' ), 10, 0 );
    }

    /**
     * Queue one re-check; bulk activation runs it once, and activating this plugin itself runs none.
     *
     * @param string $plugin Plugin basename.
     *
     * @return void
     */
    public function queue( $plugin = '' ) {
        if ( $this->queued || ( function_exists( 'wp_installing' ) && wp_installing() ) || plugin_basename( WCCT_FILE ) === $plugin ) {
            return;
        }

        /**
         * Whether the Doctor re-checks the store after a plugin is activated.
         *
         * @since 3.0.0
         *
         * @param bool $enabled On by default.
         */
        if ( ! apply_filters( 'wcct_doctor_recheck_on_activation', true ) ) {
            return;
        }

        $this->queued = true;

        add_action( 'shutdown', array( $this, 'run' ) );
    }

    /**
     * After a deactivation: re-check in a cron request, where the plugin's constants and classes (which the checks look for) are no longer loaded; once for a bulk deactivation.
     *
     * @param string $plugin Plugin basename.
     *
     * @return void
     */
    public function queue_next( $plugin = '' ) {
        if ( plugin_basename( WCCT_FILE ) !== $plugin && ! wp_next_scheduled( self::CRON ) ) {
            wp_schedule_single_event( time(), self::CRON );
        }
    }

    /**
     * Run the local checks; never breaks the request.
     *
     * @return void
     */
    public function run() {
        try {
            Doctor::recheck();
        } catch ( \Throwable $e ) {
            return;
        }
    }

    /**
     * Re-check now, so no warning outlives its cause; it runs with the Doctor alert off too, which only keeps new findings off the badge.
     *
     * @return void
     */
    public function refresh() {
        try {
            Doctor::recheck( Alerts::is_on( Alerts::DOCTOR ) );
        } catch ( \Throwable $e ) {
            return;
        }
    }
}
