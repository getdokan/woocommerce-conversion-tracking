<?php

namespace StorePulse\ConversionTracking\Doctor;

use StorePulse\ConversionTracking\Alerts\Alerts;
use StorePulse\ConversionTracking\Platforms\Pause;

/**
 * The quiet re-check of the local checks: after a plugin is activated (once, at the end of the request), after the active plugins change otherwise, such as a deactivation (on the next admin page load that runs without the old list), and after a settings save, a pause or resume, or wcct_doctor_refresh (at once, so the response is up to date).
 */
class Recheck {

    /**
     * Option holding a fingerprint of the active plugins the last admin page load ran with.
     */
    const PLUGINS = 'wcct_doctor_plugins';

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
        add_action( 'admin_init', array( $this, 'maybe_refresh' ), 10, 0 );
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
     * On an admin page load: re-check once if the active plugins (this site's and the network's) differ from the last load's.
     * It compares the list this request loaded, so a request that still runs a just-deactivated plugin changes nothing, and it sees changes made anywhere (network admin, WP-CLI, a removed folder).
     *
     * @return void
     */
    public function maybe_refresh() {
        $active = (array) get_option( 'active_plugins', array() );

        if ( is_multisite() ) {
            $active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
        }

        $active = array_unique( array_map( 'strval', $active ) );
        sort( $active );

        $print = md5( implode( "\n", $active ) );
        $last  = (string) get_option( self::PLUGINS, '' );

        if ( $print === $last ) {
            return;
        }

        update_option( self::PLUGINS, $print );

        // The first load only records the list.
        if ( '' !== $last ) {
            $this->refresh();
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
