<?php
/*
Plugin Name: Conversion Tracking for WooCommerce
Plugin URI: https://wordpress.org/plugins/woocommerce-conversion-tracking/
Description: Sends WooCommerce purchases and shopper events to Meta, Google Analytics 4, Google Ads and X, each purchase counted once, plus your own scripts.
Version: 3.0.0
Author: weDevs
Author URI: https://wedevs.com/?utm_source=ORG_Author_URI_WCCT
License: GPL2
Requires at least: 7.0
Requires PHP: 7.4
Requires Plugins: woocommerce
WC requires at least: 8.5.0
WC tested up to: 11.1
Text Domain: woocommerce-conversion-tracking
Domain Path: /languages
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
    public $version = '3.0.0';

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

        // Must run at load time: Appsero registers activation/deactivation hooks.
        $this->init_tracker();

        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        register_deactivation_hook( __FILE__, array( \StorePulse\ConversionTracking\Log\Prune::class, 'unschedule' ) );

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
        require_once WCCT_INCLUDES . '/consent-functions.php';
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
     * @param bool $network_wide Network activation.
     *
     * @return void
     */
    public function activate( $network_wide = false ) {
        $installed = get_option( 'wcct_installed' );

        if ( ! $installed ) {
            update_option( 'wcct_installed', time() );
        }

        update_option( 'wcct_version', WCCT_VERSION );

        \StorePulse\ConversionTracking\Log\OrderLog::install();
        \StorePulse\ConversionTracking\Log\Prune::schedule();
        \StorePulse\ConversionTracking\Onboarding\Redirect::on_activation( $network_wide );
    }

    /**
     * Initialize the hooks
     *
     * @return void
     */
    public function init_hooks() {
        // Before wcct_loaded, so a Pro older than 3.0 never loads its classes.
        \StorePulse\ConversionTracking\Compat\ProGuard::register();

        $this->container['capability'] = new \StorePulse\ConversionTracking\Access\Capability();
        $this->container['capability']->register();

        $this->container['rest'] = new \StorePulse\ConversionTracking\REST\Routes();
        $this->container['rest']->register();

        // Until the 2.x settings page is removed, its saves write through to the 3.0 settings.
        \StorePulse\ConversionTracking\Settings\LegacySync::register();

        \StorePulse\ConversionTracking\Privacy\PolicyText::register();

        $this->container['doctor_recheck'] = new \StorePulse\ConversionTracking\Doctor\Recheck();
        $this->container['doctor_recheck']->register();

        \StorePulse\ConversionTracking\Log\Prune::init_hooks();

        // Every settings save and pause purges the known page caches.
        \StorePulse\ConversionTracking\Cache\PageCache::register();

        $this->container['onboarding_redirect'] = new \StorePulse\ConversionTracking\Onboarding\Redirect();
        $this->container['onboarding_redirect']->register();

        add_action( 'plugins_loaded', array( $this, 'plugin_upgrades' ) );
        add_action( 'init', array( $this, 'localization_setup' ) );
        add_action( 'init', array( $this, 'init_classes' ) );

        add_action( 'admin_notices', array( $this, 'check_woocommerce_exist' ) );
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_action_links' ) );
        add_action( 'admin_notices', array( $this, 'happy_addons_ads_banner' ) );
    }

    /**
     * Instantiate the required classes
     *
     * @return void
     */
    public function init_classes() {
        // Manager must exist before the dispatcher, which reads active integrations on construct.
        $this->container['manager']             = new WCCT_Integration_Manager();
        $this->container['ajax']                = new WCCT_Ajax();
        $this->container['event_dispatcher']    = new WCCT_Event_Dispatcher();
        $this->container['admin']               = new WCCT_Admin();
        $this->container['admin_assets']        = new \StorePulse\ConversionTracking\Admin\Assets();
        $this->container['consent']             = new \StorePulse\ConversionTracking\Consent\ConsentMode();
        $this->container['consent']->register();

        if ( ! $this->is_pro() ) {
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
     * Run the 3.0 settings migration on any request where the stored settings need it
     *
     * @since  2.0
     * @return void
     */
    public function plugin_upgrades() {
        // Before the migration, so 2.x settings still tell an upgrade from a new install.
        \StorePulse\ConversionTracking\Install::record();
        \StorePulse\ConversionTracking\Migration\SettingsMigration::maybe_run();

        // Orders created before 3.0 first ran count as already sent.
        \StorePulse\ConversionTracking\Events\PurchaseGate::ensure_since();
        // The order log table, created or updated when its schema version changes.
        \StorePulse\ConversionTracking\Log\OrderLog::maybe_install();
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
            'Conversion Tracking for WooCommerce',
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
     * Whether Pro 3.0 or later is running; an older Pro is paused and counts as Free
     *
     * @return boolean
     */
    public function is_pro() {
        return \StorePulse\ConversionTracking\Compat\ProVersion::is_active();
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
            $links[] = '<a href="' . esc_url( \StorePulse\ConversionTracking\Admin\Upgrade::url() ) . '" target="_blank" rel="noopener noreferrer" style="color: #389e38;font-weight: bold;">' . __( 'Get PRO', 'woocommerce-conversion-tracking' ) . '</a>';
        }

        $links[] = '<a href="https://wedevs.com/docs/woocommerce-conversion-tracking/?utm_source=wp-admin&utm_medium=action-link&utm_campaign=wcct_docs&utm_content=Docs" target="_blank">' . __( 'Docs', 'woocommerce-conversion-tracking' ) . '</a>';
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
                    <p><?php echo wp_kses_post( __( '<b>Conversion Tracking for WooCommerce</b> requires <a target="_blank" href="https://wordpress.org/plugins/woocommerce/">Woocommerce</a>', 'woocommerce-conversion-tracking' ) ); ?></p>
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
                    <p><?php echo wp_kses_post( __( 'Reach beyond your imagination in creating web pages. <strong> Try Happy Addons for Elementor to shape your dream.</strong> 😊', 'woocommerce-conversion-tracking' ) ); ?></p>
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
 * Capability that opens the settings; manage_wcct unless filtered
 *
 * @return string
 */
function wcct_manage_cap() {
    return apply_filters( 'wcct_capability', \StorePulse\ConversionTracking\Access\Capability::NAME );
}
