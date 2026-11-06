<?php
/**
 * Plugin Name: Conversion Tracking for WooCommerce Pro (1.1.4 stand-in)
 * Version: 1.1.4
 *
 * Test stand-in with Pro 1.1.4's bootstrap hooks, for Compat/OldProTest. 1.1.4's admin and integration
 * classes extend Free 2.x classes, so here loading them throws instead.
 */

// phpcs:disable

class WeDevs_WC_Conversion_Tracking_Pro {

    private $version = '1.1.4';

    public function __construct() {
        define( 'WCCT_PRO_FILE', __FILE__ );
        define( 'WCCT_PRO_VERSION', $this->version );

        add_action( 'wcct_loaded', array( $this, 'includes' ) );
        add_action( 'wcct_loaded', array( $this, 'init_classes' ), 10 );
        add_action( 'admin_notices', array( $this, 'check_wcct' ) );
        add_action( 'init', array( $this, 'do_export' ), 99 );
        add_filter( 'wcct_integrations', array( $this, 'add_integrations' ), 120, 1 );
    }

    public static function init() {
        static $instance = false;

        if ( ! $instance ) {
            $instance = new WeDevs_WC_Conversion_Tracking_Pro();
        }

        return $instance;
    }

    public function includes() {
        throw new LogicException( 'Old Pro loaded its admin files' );
    }

    public function init_classes() {
        throw new LogicException( 'Old Pro started its admin classes' );
    }

    public function add_integrations( $integrations ) {
        throw new LogicException( 'Old Pro loaded its integrations' );
    }

    // 1.1.4's own notice, as released.
    public function check_wcct() {
        if ( ! class_exists( 'WeDevs_WC_Conversion_Tracking' ) ) {
            ?>
            <div class="error notice is-dismissible">
                <p>
                    <?php
                    printf(
                        /* translators: %s: Conversion Tracking for WooCommerce plugin URL */
                        wp_kses_post( __( '<b>Conversion Tracking for WooCommerce Pro</b> requires <a href="%s" target="_blank">Woocommerce Conversion Tracking</a>', 'woocommerce-conversion-tracking-pro' ) ),
                        esc_url( 'https://wordpress.org/plugins/woocommerce-conversion-tracking/' )
                    );
                    ?>
                </p>
            </div>
            <?php
        }
    }

    // 1.1.4 serves the catalog CSV on init without Free's hooks.
    public function do_export() {
        if ( isset( $_GET['action'] ) && 'wcct-product-export' === $_GET['action'] ) {
            throw new WCCT_Test_Exit( 'Old Pro served the catalog feed' );
        }
    }
}

$wc_tracking = WeDevs_WC_Conversion_Tracking_Pro::init();
