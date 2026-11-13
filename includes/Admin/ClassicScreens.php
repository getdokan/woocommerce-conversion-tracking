<?php

namespace StorePulse\ConversionTracking\Admin;

/**
 * What is left of the 2.x settings screens: their links open the app, and their hooks are deprecated no-ops.
 */
class ClassicScreens {

    /**
     * 2.x ?tab= => the app route that replaced it.
     */
    const TABS = array(
        'integrations'    => '#/platforms',
        'product-catalog' => '#/catalog',
        'settings'        => '#/settings/access',
    );

    /**
     * Hooks of the 2.x screens; they still accept callbacks but never run.
     */
    const HOOKS = array( 'wcct_nav_tab', 'wcct_sidebar', 'wcct_before_nav', 'wcct_save_integrations_settings' );

    /**
     * Prefixes of the 2.x screens' per-tab and per-integration hooks.
     */
    const HOOK_PREFIXES = array( 'wcct_nav_content_', 'wcct_integration_' );

    /**
     * Runs when the Conversion Tracking page loads, before any output.
     *
     * @return void
     */
    public static function load() {
        self::deprecate_hooks();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect.
        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : null;

        if ( null === $tab ) {
            return;
        }

        wp_safe_redirect( self::redirect_url( $tab ) );
        exit;
    }

    /**
     * The app page that replaced a 2.x tab; unknown tabs open the dashboard.
     *
     * @param string $tab The 2.x tab.
     *
     * @return string
     */
    public static function redirect_url( string $tab ): string {
        $route = isset( self::TABS[ $tab ] ) ? self::TABS[ $tab ] : '#/dashboard';

        return admin_url( 'admin.php?page=conversion-tracking' ) . $route;
    }

    /**
     * The 2.x screen hooks that have callbacks; they are kept as no-ops for one release.
     *
     * @param array|null $filters Hook name => callbacks, $wp_filter by default.
     *
     * @return string[]
     */
    public static function hooked( ?array $filters = null ): array {
        if ( null === $filters ) {
            $filters = isset( $GLOBALS['wp_filter'] ) && is_array( $GLOBALS['wp_filter'] ) ? $GLOBALS['wp_filter'] : array();
        }

        $hooked = array();

        foreach ( $filters as $name => $callbacks ) {
            $name = (string) $name;

            if ( ! self::is_classic_hook( $name ) ) {
                continue;
            }

            if ( $callbacks instanceof \WP_Hook ? $callbacks->has_filters() : ! empty( $callbacks ) ) {
                $hooked[] = $name;
            }
        }

        return $hooked;
    }

    /**
     * Whether a hook belonged to the 2.x screens.
     *
     * @param string $name Hook name.
     *
     * @return bool
     */
    public static function is_classic_hook( string $name ): bool {
        if ( in_array( $name, self::HOOKS, true ) ) {
            return true;
        }

        foreach ( self::HOOK_PREFIXES as $prefix ) {
            if ( 0 === strpos( $name, $prefix ) && strlen( $name ) > strlen( $prefix ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tell developers (with WP_DEBUG) that a 2.x screen hook they use no longer runs.
     *
     * @return void
     */
    public static function deprecate_hooks() {
        foreach ( self::hooked() as $hook ) {
            _deprecated_hook( esc_html( $hook ), '3.0.0', '', esc_html__( 'The Conversion Tracking settings screens were replaced in 3.0; this hook no longer runs.', 'woocommerce-conversion-tracking' ) );
        }
    }

    /**
     * Shown instead of the app when its build is missing (a development checkout without npm run build).
     *
     * @return void
     */
    public static function missing_build() {
        echo '<div class="wrap"><h1>' . esc_html__( 'Conversion Tracking', 'woocommerce-conversion-tracking' ) . '</h1><div class="notice notice-error inline"><p>' . esc_html__( 'The settings app is missing from this copy of the plugin. Reinstall Conversion Tracking for WooCommerce.', 'woocommerce-conversion-tracking' ) . '</p></div></div>';
    }
}
