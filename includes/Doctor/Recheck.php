<?php

namespace StorePulse\ConversionTracking\Doctor;

use StorePulse\ConversionTracking\Alerts\Alerts;
use StorePulse\ConversionTracking\Platforms\Pause;

/**
 * The quiet re-check of the local checks: after a plugin is activated (once, at the end of the request), after the loaded plugins change otherwise, such as a deactivation (on the next admin page load, which only refreshes the results and never lights the badge), and after a settings save, a pause or resume, or wcct_doctor_refresh (at once, so the response is up to date).
 */
class Recheck {

    /**
     * Option holding a fingerprint of the active plugins the last admin page load ran with.
     */
    const PLUGINS = 'wcct_doctor_plugins';

    /**
     * Basenames of the plugins this request loaded, taken at plugins_loaded; null before.
     *
     * @var string[]|null
     */
    private $loaded = null;

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
        add_action( 'plugins_loaded', array( $this, 'snapshot' ), 0, 0 );
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
     * Note the plugins this request loaded, before any of them can change the active list.
     *
     * @return void
     */
    public function snapshot() {
        $this->loaded = Plugins::active_basenames();
    }

    /**
     * On an admin page load: re-check once if the plugins it loaded differ from the last page load's.
     * AJAX and admin-post requests are skipped (some stores load fewer plugins there), and the re-check never lights the badge, so a plugin update's brief deactivation doesn't flag its warning as new.
     *
     * @return void
     */
    public function maybe_refresh() {
        if ( null === $this->loaded || wp_doing_ajax() || ( isset( $GLOBALS['pagenow'] ) && 'admin-post.php' === $GLOBALS['pagenow'] ) ) {
            return;
        }

        $active = array_unique( $this->loaded );
        sort( $active );

        $print = md5( implode( "\n", $active ) );
        $last  = (string) get_option( self::PLUGINS, '' );

        if ( $print === $last ) {
            return;
        }

        update_option( self::PLUGINS, $print );

        // The first load only records the list.
        if ( '' !== $last ) {
            $this->refresh( false );
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
     * @param bool $badge Whether new findings may light the badge.
     *
     * @return void
     */
    public function refresh( $badge = true ) {
        try {
            Doctor::recheck( $badge && Alerts::is_on( Alerts::DOCTOR ) );
        } catch ( \Throwable $e ) {
            return;
        }
    }
}
