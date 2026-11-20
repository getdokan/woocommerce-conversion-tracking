<?php

namespace StorePulse\ConversionTracking\Compat;

/**
 * Keeps a Pro older than 3.0 dormant: its classes extend Free's 2.x classes, so they are never loaded.
 *
 * @since PLUGIN_SINCE
 */
class ProGuard {

    /**
     * Pro's main class.
     */
    const PRO_CLASS = 'WeDevs_WC_Conversion_Tracking_Pro';

    /**
     * Hooks old Pro loads its integrations, admin and AJAX from.
     */
    const HOOKS = array( 'wcct_loaded', 'wcct_integrations', 'wcct_platforms' );

    /**
     * Pause old Pro now and once every plugin is loaded, and add the notice
     *
     * @return void
     */
    public static function register() {
        // Runs before Free fires wcct_loaded, when Pro was loaded first (the usual order).
        self::pause_old_pro();

        // A Pro loaded after Free adds its filters later.
        add_action( 'plugins_loaded', array( __CLASS__, 'pause_old_pro' ), 0 );
        add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
        add_action( 'network_admin_notices', array( __CLASS__, 'notice' ) );
    }

    /**
     * Remove old Pro's callbacks from the hooks it loads from
     *
     * @return void
     */
    public static function pause_old_pro() {
        global $wp_filter;

        if ( ! ProVersion::is_outdated() ) {
            return;
        }

        foreach ( self::HOOKS as $hook ) {
            if ( ! isset( $wp_filter[ $hook ] ) || ! $wp_filter[ $hook ] instanceof \WP_Hook ) {
                continue;
            }

            foreach ( self::owned_callbacks( $wp_filter[ $hook ]->callbacks ) as $owned ) {
                remove_filter( $hook, $owned[1], $owned[0] );
            }
        }
    }

    /**
     * The callbacks of a hook's priority list that belong to a class
     *
     * @param mixed  $callbacks  WP_Hook::$callbacks, priority => list of { function, accepted_args }.
     * @param string $class_name Owner class.
     *
     * @return array List of [ priority, callback ].
     */
    public static function owned_callbacks( $callbacks, $class_name = self::PRO_CLASS ) {
        $owned = array();

        if ( ! is_array( $callbacks ) ) {
            return $owned;
        }

        foreach ( $callbacks as $priority => $entries ) {
            foreach ( (array) $entries as $entry ) {
                if ( isset( $entry['function'] ) && self::is_owned_by( $entry['function'], $class_name ) ) {
                    $owned[] = array( $priority, $entry['function'] );
                }
            }
        }

        return $owned;
    }

    /**
     * Whether a callback is a method of a class (object or static)
     *
     * @param mixed  $callback   Callback.
     * @param string $class_name Class name.
     *
     * @return bool
     */
    private static function is_owned_by( $callback, $class_name ) {
        if ( is_string( $callback ) && false !== strpos( $callback, '::' ) ) {
            $callback = explode( '::', $callback, 2 );
        }

        if ( ! is_array( $callback ) || ! isset( $callback[0] ) ) {
            return false;
        }

        if ( is_object( $callback[0] ) ) {
            return is_a( $callback[0], $class_name );
        }

        return is_string( $callback[0] ) && 0 === strcasecmp( ltrim( $callback[0], '\\' ), $class_name );
    }

    /**
     * Where Pro's update is installed: the plugins list filtered to updates
     *
     * @return string
     */
    public static function update_url() {
        return network_admin_url( 'plugins.php?plugin_status=upgrade' );
    }

    /**
     * "Pro features are paused until you update", with the update link for users who can update plugins
     *
     * @return void
     */
    public static function notice() {
        if ( ! ProVersion::is_outdated() ) {
            return;
        }

        $can_update = current_user_can( 'update_plugins' );

        if ( ! $can_update && ! current_user_can( wcct_manage_cap() ) ) {
            return;
        }

        $message = sprintf(
            /* translators: %s: Free version Pro must be updated for, e.g. 3.0 */
            __( 'Conversion Tracking Pro needs an update to work with %s. Pro features are paused until you update.', 'woocommerce-conversion-tracking' ),
            ProVersion::minimum_label()
        );
        ?>
        <div class="notice notice-warning" id="wcct-pro-update-notice">
            <p>
                <?php echo esc_html( $message ); ?>
                <?php if ( $can_update ) : ?>
                    <a href="<?php echo esc_url( self::update_url() ); ?>"><?php esc_html_e( 'Update Pro', 'woocommerce-conversion-tracking' ); ?></a>
                <?php endif; ?>
            </p>
        </div>
        <?php
    }
}
