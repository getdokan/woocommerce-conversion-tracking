<?php

/**
 * The welcome class after install
 *
 * @since 1.1.0
 */
class WCCT_Welcome_20 {

    function __construct() {
        add_action( 'admin_init', array( $this, 'redirect_to_page' ), 9999 );
    }

    /**
     * Redirect to the welcome page once the plugin is installed
     *
     * @return void
     */
    public function redirect_to_page() {
        if ( ! get_transient( 'wcct_upgrade_to_20' ) ) {
            return;
        }

        delete_transient( 'wcct_upgrade_to_20' );

        // Only do this for single site installs.
        if ( is_network_admin() || isset( $_GET['activate-multi'] ) ) {
            return;
        }

        wp_safe_redirect( admin_url( 'admin.php?page=conversion-tracking&notice=welcome' ) );
        exit;
    }
}
