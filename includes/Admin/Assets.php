<?php

namespace StorePulse\ConversionTracking\Admin;

// don't call the file directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registers the React admin bundles built into dist/
 *
 * @since PLUGIN_SINCE
 */
class Assets {

    /**
     * Script handle => dist/ entry name
     *
     * @var array
     */
    const BUNDLES = array(
        'wcct-plugin-ui'  => 'plugin-ui',
        'wcct-components' => 'components',
        'wcct-admin-app'  => 'index',
    );

    /**
     * Style dependencies per handle
     *
     * @var array
     */
    const STYLE_DEPS = array(
        // components.css already bundles the WordPress components styles, so no wp-components here.
        'wcct-admin-app' => array( 'wcct-components' ),
    );

    /**
     * Constructor for the Assets class
     */
    public function __construct() {
        add_action( 'admin_enqueue_scripts', array( $this, 'register' ), 5 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
        add_action( 'wcct_before_nav', array( $this, 'render_root' ), 5 );
    }

    /**
     * Register the bundles found in dist/ so Pro can depend on them
     *
     * @return void
     */
    public function register() {
        foreach ( self::BUNDLES as $handle => $entry ) {
            $asset_file = WCCT_PATH . '/dist/' . $entry . '.asset.php';

            if ( ! file_exists( $asset_file ) ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( 'Conversion Tracking: missing ' . $entry . '.asset.php; run npm run build.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                }

                continue;
            }

            $asset = require $asset_file;

            wp_register_script( $handle, WCCT_URL . '/dist/' . $entry . '.js', $asset['dependencies'], $asset['version'], true );

            // plugin-ui strings are not in our text domain.
            if ( 'wcct-plugin-ui' !== $handle ) {
                wp_set_script_translations( $handle, 'woocommerce-conversion-tracking', WCCT_PATH . '/languages' );
            }

            $style_file = WCCT_PATH . '/dist/' . $entry . '.css';

            if ( file_exists( $style_file ) ) {
                $style_deps = isset( self::STYLE_DEPS[ $handle ] ) ? self::STYLE_DEPS[ $handle ] : array();

                wp_register_style( $handle, WCCT_URL . '/dist/' . $entry . '.css', $style_deps, filemtime( $style_file ) );
                wp_style_add_data( $handle, 'rtl', 'replace' );
            }
        }
    }

    /**
     * Enqueue the admin app on the Conversion Tracking page
     *
     * @param string $hook_suffix Current admin page hook.
     *
     * @return void
     */
    public function enqueue( $hook_suffix ) {
        if ( ! $this->is_app_page( $hook_suffix ) || ! wp_script_is( 'wcct-admin-app', 'registered' ) ) {
            return;
        }

        wp_enqueue_script( 'wcct-admin-app' );
        wp_enqueue_style( wp_style_is( 'wcct-admin-app', 'registered' ) ? 'wcct-admin-app' : 'wcct-components' );

        wp_localize_script(
            'wcct-admin-app',
            'wcctAdmin',
            array(
                'version'    => WCCT_VERSION,
                'proVersion' => defined( 'WCCT_PRO_VERSION' ) ? WCCT_PRO_VERSION : '',
            )
        );
    }

    /**
     * Print the element the admin app mounts on
     *
     * @return void
     */
    public function render_root() {
        echo '<div id="wcct-app-root"></div>';
    }

    /**
     * Whether the current admin page is the Conversion Tracking page
     *
     * @param string $hook_suffix Current admin page hook.
     *
     * @return bool
     */
    private function is_app_page( $hook_suffix ) {
        $admin = wcct_init()->admin;

        return ! empty( $admin->page_hook ) && $admin->page_hook === $hook_suffix;
    }
}
