<?php
/*
Plugin Name: WooCommerce Conversion Tracking
Plugin URI: https://wedevs.com/woocommerce-conversion-tracking/
Description: Adds various conversion tracking codes to cart, checkout, registration success and product page on WooCommerce
Version: 2.1.1
Author: weDevs
Author URI: https://wedevs.com/?utm_source=ORG_Author_URI_WCCT
License: GPL2
Requires PHP: 7.4
Requires Plugins: woocommerce
WC requires at least: 5.0.0
WC tested up to: 10.1.1
*/

/**
 * Copyright (c) 2020 Tareq Hasan (email: tareq@wedevs.com). All rights reserved.
 *
 * Released under the GPL license
 * http://www.opensource.org/licenses/gpl-license.php
 *
 * This is an add-on for WordPress
 * http://wordpress.org/
 *
 * **********************************************************************
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 * **********************************************************************
 */

// don't call the file directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * WeDevs_WC_Facebook_Tracking_Pixel class
 *
 * @class WeDevs_WC_Facebook_Tracking_Pixel The class that holds the entire WeDevs_WC_Facebook_Tracking_Pixel plugin
 */
class WeDevs_WC_Conversion_Tracking {

    /**
     * Plugin version
     *
     * @var string
     */
    public $version = '2.1.1';

    /**
     * Holds various class instances
     *
     * @var array
     */
    private $container = array();

    /**
     * Constructor for the WeDevs_WC_Conversion_Tracking class
     *
     * Sets up all the appropriate hooks and actions
     * within our plugin.
     */
    public function __construct() {
	    require_once __DIR__ . '/vendor/autoload.php';

        $this->define_constants();
        $this->init_hooks();
        $this->includes();
        $this->init_classes();

        register_activation_hook( __FILE__, array( $this, 'activate' ) );

        // Add High Performance Order Storage Support
        add_action( 'before_woocommerce_init', [ $this, 'declare_woocommerce_feature_compatibility' ] );

        do_action( 'wcct_loaded' );
    }

    /**
     * Magic getter to bypass referencing plugin.
     *
     * @param $prop
     *
     * @return mixed
     */
    public function __get( $prop ) {
        if ( array_key_exists( $prop, $this->container ) ) {
            return $this->container[ $prop ];
        }

        return $this->{$prop};
    }

    /**
     * Magic isset to bypass referencing plugin.
     *
     * @param $prop
     *
     * @return mixed
     */
    public function __isset( $prop ) {
        return isset( $this->{$prop} ) || isset( $this->container[ $prop ] );
    }

    /**
     * Initializes the WeDevs_WC_Conversion_Tracking() class
     *
     * Checks for an existing WeDevs_WC_Conversion_Tracking() instance
     * and if it doesn't find one, creates it.
     */
    public static function init() {
        static $instance = false;

        if ( ! $instance ) {
            $instance = new WeDevs_WC_Conversion_Tracking();
        }

        return $instance;
    }

    /**
     * Include required files
     *
     * @return void
     */
    public function includes() {
        require_once WCCT_INCLUDES . '/class-abstract-integration.php';
        require_once WCCT_INCLUDES . '/class-integration-manager.php';
        require_once WCCT_INCLUDES . '/class-event-dispatcher.php';
        require_once WCCT_INCLUDES . '/class-integration-pro-features.php';
        require_once WCCT_INCLUDES . '/class-ajax.php';
        require_once WCCT_INCLUDES . '/class-admin.php';
        require_once WCCT_INCLUDES . '/class-welcome-20.php';
    }

    /**
     * Define the constants
     *
     * @since 1.2.5
     *
     * @return void
     */
    public function define_constants() {
        defined( 'WCCT_VERSION' ) || define( 'WCCT_VERSION', $this->version );
        defined( 'WCCT_FILE' ) || define( 'WCCT_FILE', __FILE__ );
        defined( 'WCCT_PATH' ) || define( 'WCCT_PATH', dirname( WCCT_FILE ) );
        defined( 'WCCT_INCLUDES' ) || define( 'WCCT_INCLUDES', WCCT_PATH . '/includes' );
        defined( 'WCCT_URL' ) || define( 'WCCT_URL', plugins_url( '', WCCT_FILE ) );
        defined( 'WCCT_ASSETS' ) || define( 'WCCT_ASSETS', WCCT_URL . '/assets' );
    }

    /**
     * Plugin activation routeis
     *
     * @since 1.2.5
     *
     * @return void
     */
    public function activate() {
        $installed = get_option( 'wcct_installed' );

        if ( ! $installed ) {
            update_option( 'wcct_installed', time() );
        }

        update_option( 'wcct_version', WCCT_VERSION );
    }

    /**
     * Initialize the hooks
     *
     * @return void
     */
    public function init_hooks() {

        add_action( 'plugins_loaded', array( $this, 'plugin_upgrades' ) );
        add_action( 'init', array( $this, 'localization_setup' ) );

        add_action( 'admin_notices', array( $this, 'check_woocommerce_exist' ) );
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_action_links' ) );
        add_action( 'admin_notices', array( $this, 'happy_addons_ads_banner' ) );

        $this->init_tracker();
    }

    /**
     * Instantiate the required classes
     *
     * @return void
     */
    public function init_classes() {
        $this->container['ajax']                = new WCCT_Ajax();
        $this->container['event_dispatcher']    = new WCCT_Event_Dispatcher();
        $this->container['admin']               = new WCCT_Admin();
        $this->container['manager']             = new WCCT_Integration_Manager();

        new WCCT_Welcome_20();

        if ( ! class_exists( 'WeDevs_WC_Conversion_Tracking_Pro') ) {
            $this->container['pro_feature'] = new WCCT_Pro_Features();
        }
    }

    /**
     * Initialize plugin for localization
     *
     * @uses load_plugin_textdomain()
     */
    public function localization_setup() {
        load_plugin_textdomain( 'woocommerce-conversion-tracking', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
    }

    /**
     * Do plugin upgrade
     *
     * @since  2.0
     * @return void
     */
    public function plugin_upgrades() {
        if ( ! is_admin() && ! current_user_can( 'manage_options' ) ) {
            return;
        }

        require_once WCCT_INCLUDES . '/class-upgrades.php';

        $upgrader   = new WCCT_Upgrades();

        if ( $upgrader->needs_update() ) {
            $upgrader->perform_updates();
        }
    }

    /**
     * Initialize the weDevs insights tracker
     *
     * @since 1.2.3
     *
     * @return void
     */
    public function init_tracker() {
        $client = new WeDevs_WC_Conversion_Tracking\Dependencies\Appsero\Client(
            '6816029d-7d48-4ed3-8ae4-aeb6a9496f21',
            'WooCommerce Conversion Tracking',
            __FILE__
        );

        // Active insights
        $client->insights()->init();
    }

	/**
	 * Add High Performance Order Storage Support
     *
     * @see https://developer.woocommerce.com/docs/hpos-extension-recipe-book/
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	public function declare_woocommerce_feature_compatibility() {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}

    /**
     * Check the pro version
     *
     * @return boolean
     */
    public function is_pro() {
        if ( class_exists( 'WeDevs_WC_Conversion_Tracking_Pro' ) ) {
            return true;
        }
        return false;
    }

    /**
     * Plugin action links
     *
     * @param  array $links
     *
     * @return array
     */
    function plugin_action_links( $links ) {
        if ( ! $this->is_pro() ) {
            $links[] = '<a href="https://wedevs.com/woocommerce-conversion-tracking/upgrade-to-pro/?utm_source=wp-admin&utm_medium=pro-upgrade&utm_campaign=wcct_upgrade&utm_content=Get_Premium" target="_blank" style="color: #389e38;font-weight: bold;">' . __( 'Get PRO', 'woocommerce-conversion-tracking' ) . '</a>';
        }

        $links[] = '<a href="https://wedevs.com/docs/woocommerce-conversion-tracking/get-started/?utm_source=wp-admin&utm_medium=action-link&utm_campaign=wcct_docs&utm_content=Docs" target="_blank">' . __( 'Docs', 'woocommerce-conversion-tracking' ) . '</a>';
        $links[] = '<a href="' . admin_url( 'admin.php?page=conversion-tracking' ) . '">' . __( 'Settings', 'woocommerce-conversion-tracking' ) . '</a>';

        return $links;
    }
    /**
     * Check Woocommerce exist
     *
     * @return void
     */
    public function check_woocommerce_exist() {
        if ( ! function_exists( 'WC' ) ) {
            ?>
                <div class="error notice is-dismissible">
                    <p><?php echo __( '<b>Woocommerce conversion tracking</b> requires <a target="_blank" href="https://wordpress.org/plugins/woocommerce/">Woocommerce</a>', 'woocommerce-conversion-tracking' ); ?></p>
                </div>
            <?php
        }
    }

    /**
     * Add happy addons ads banner
     *
     * @return void
     */
    public function happy_addons_ads_banner() {
        if ( ! class_exists( '\Elementor\Plugin' ) ) {
            return;
        }

        if ( class_exists( '\Happy_Addons\Elementor\Base' ) ) {
            return;
        }
        $dismissable = get_option( 'wcct_dismissable_notice' );

        if ( $dismissable == 'closed' ) {
            return;
        }

        ?>
            <div id="wcct_remote_notice" class="notice notice-success">
            </div>
            <div class="notice is-dismissible wcct-notice-wrap">
                <div class="wcct-message-icon">
                    <img src="<?php echo esc_attr( WCCT_ASSETS . '/images/happy-addons.png' )?>" alt="">
                </div>
                <div class="wcct-message-content">
                    <p><?php echo __( 'Reach beyond your imagination in creating web pages. <strong> Try Happy Addons for Elementor to shape your dream.</strong> 😊' ); ?></p>
                </div>
                <div class="wcct-message-action">
                    <a href="" id="wcct-install-happ-addons" class="button button-primary"> <i class="dashicons dashicons-update wcct-update-icon"></i> Install Now For FREE</a>
                    <p><a target="_blank" href="https://wordpress.org/plugins/happy-elementor-addons/">Read more details ➔</a>
                    </p>
                </div>
            </div>

        <?php
    }
}

function wcct_init() {
    return WeDevs_WC_Conversion_Tracking::init();
}

// WeDevs_WC_Conversion_Tracking
wcct_init();

/**
 * Manage Capability
 *
 * @return string
 */
function wcct_manage_cap() {
    return apply_filters( 'wcct_capability', 'manage_options' );
}
