<?php

namespace StorePulse\ConversionTracking\Doctor;

/**
 * The quiet re-check: after a plugin is activated, the local checks run once, at the end of the request.
 */
class Recheck {

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
}
