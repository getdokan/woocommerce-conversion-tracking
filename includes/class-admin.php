<?php

use StorePulse\ConversionTracking\Admin\ClassicScreens;

/**
 * The admin page handler class
 */
class WCCT_Admin {

    /**
     * Hook suffix of the Conversion Tracking page
     *
     * @var string
     */
    public $page_hook = '';

    /**
     * Constructor for WCCT_Admin class
     */
    function __construct() {
        add_action( 'admin_menu', array( $this, 'admin_menu_page' ) );
    }

    /**
     * Add menu page
     *
     * @return void
     */
    public function admin_menu_page() {
        $menu_page      = apply_filters( 'wcct_menu_page', 'woocommerce' );
        $capability     = wcct_manage_cap();

        $this->page_hook = (string) add_submenu_page( $menu_page, __( 'Conversion Tracking', 'woocommerce-conversion-tracking' ), __( 'Conversion Tracking', 'woocommerce-conversion-tracking' ), $capability, 'conversion-tracking', array( $this, 'conversion_tracking_template' ) );

        if ( '' !== $this->page_hook ) {
            // The 2.x screens are gone: their ?tab= links open the matching app page.
            add_action( 'load-' . $this->page_hook, array( ClassicScreens::class, 'load' ) );
        }
    }

    /**
     * Conversion Tracking View Page
     *
     * @return void
     */
    public function conversion_tracking_template() {
        if ( isset( wcct_init()->admin_assets ) && wcct_init()->admin_assets->is_app_view() ) {
            include dirname( __FILE__ ) . '/views/app.php';
            return;
        }

        ClassicScreens::missing_build();
    }
}
