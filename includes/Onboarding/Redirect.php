<?php

namespace StorePulse\ConversionTracking\Onboarding;

use StorePulse\ConversionTracking\Install;

/**
 * Opens the setup wizard once, on the first admin page after a fresh single-site activation.
 */
class Redirect {

    /**
     * Option holding the redirect: pending until the next admin page, then done for good.
     */
    const OPTION = 'wcct_onboarding_redirect';

    /**
     * Queued by the activation, not yet redirected.
     */
    const PENDING = 'pending';

    /**
     * Redirected, or dropped; never queued again.
     */
    const DONE = 'done';

    /**
     * The wizard, relative to wp-admin.
     */
    const PATH = 'admin.php?page=conversion-tracking#/onboarding';

    /**
     * The request, as the rules read it; every flag off by default.
     */
    const REQUEST = array(
        'network_wide'  => false,
        'network_admin' => false,
        'bulk'          => false,
        'cli'           => false,
        'ajax'          => false,
        'cron'          => false,
        'rest'          => false,
        'iframe'        => false,
        'get'           => false,
        'can'           => false,
        'woocommerce'   => false,
    );

    /**
     * Hook the redirect.
     *
     * @return void
     */
    public function register() {
        add_action( 'admin_init', array( $this, 'maybe_redirect' ), 9999 );
    }

    /**
     * Activation hook: queue the redirect for a fresh single-site activation.
     *
     * @param bool $network_wide Network activation.
     *
     * @return void
     */
    public static function on_activation( $network_wide ) {
        self::queue( self::current_request( (bool) $network_wide ) );
    }

    /**
     * Redirect to the wizard when it is queued and this request can show it.
     *
     * @return void
     */
    public function maybe_redirect() {
        $url = self::target( self::current_request() );

        if ( '' === $url ) {
            return;
        }

        wp_safe_redirect( $url );
        exit;
    }

    /**
     * Queue the redirect unless the activation is bulk, network, WP-CLI, AJAX, REST or cron, the user can't manage tracking, or the store isn't new.
     *
     * @param array $request Request flags, see REQUEST.
     *
     * @return bool Whether it was queued.
     */
    public static function queue( array $request ): bool {
        $request = array_merge( self::REQUEST, $request );

        foreach ( array( 'network_wide', 'network_admin', 'bulk', 'cli', 'ajax', 'cron', 'rest' ) as $flag ) {
            if ( $request[ $flag ] ) {
                return false;
            }
        }

        if ( ! $request['can'] || ! self::first_run() ) {
            return false;
        }

        // Only the first activation ever queues it.
        return add_option( self::OPTION, self::PENDING );
    }

    /**
     * The wizard URL when this admin request redirects, else an empty string; a queued redirect is used once.
     *
     * @param array $request Request flags, see REQUEST.
     *
     * @return string
     */
    public static function target( array $request ): string {
        if ( self::PENDING !== get_option( self::OPTION ) ) {
            return '';
        }

        $request = array_merge( self::REQUEST, $request );

        // Background requests, form posts and other users leave it for the next admin page.
        if ( $request['cli'] || $request['ajax'] || $request['cron'] || $request['rest'] || $request['iframe'] || ! $request['get'] || ! $request['can'] ) {
            return '';
        }

        update_option( self::OPTION, self::DONE );

        // A late redirect would land on someone else's screen, so these drop it for good.
        if ( $request['bulk'] || $request['network_admin'] || ! $request['woocommerce'] || ! self::first_run() ) {
            return '';
        }

        return admin_url( self::PATH );
    }

    /**
     * Whether the store is new to Conversion Tracking and the wizard was never opened.
     *
     * @return bool
     */
    public static function first_run(): bool {
        if ( Install::is_upgrade() ) {
            return false;
        }

        return 'new' === State::normalize( get_option( State::OPTION, array() ), array() )['status'];
    }

    /**
     * Flags of the current request.
     *
     * @param bool $network_wide Network activation.
     *
     * @return array
     */
    public static function current_request( bool $network_wide = false ): array {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only checks of the request WordPress already handles.
        $actions = array();

        foreach ( array( 'action', 'action2' ) as $key ) {
            if ( isset( $_REQUEST[ $key ] ) && is_string( $_REQUEST[ $key ] ) ) {
                $actions[] = sanitize_key( wp_unslash( $_REQUEST[ $key ] ) );
            }
        }

        $bulk = isset( $_GET['activate-multi'] ) || in_array( 'activate-selected', $actions, true );
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        return array(
            'network_wide'  => $network_wide,
            'network_admin' => is_network_admin(),
            'bulk'          => $bulk,
            'cli'           => defined( 'WP_CLI' ) && WP_CLI,
            'ajax'          => wp_doing_ajax(),
            'cron'          => wp_doing_cron(),
            'rest'          => defined( 'REST_REQUEST' ) && REST_REQUEST,
            'iframe'        => defined( 'IFRAME_REQUEST' ) && IFRAME_REQUEST,
            'get'           => isset( $_SERVER['REQUEST_METHOD'] ) && 'GET' === strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ),
            'can'           => current_user_can( wcct_manage_cap() ),
            'woocommerce'   => class_exists( 'WooCommerce' ),
        );
    }
}
