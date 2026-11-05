<?php

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
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
        add_action( 'admin_menu', array( $this, 'admin_menu_page' ) );
    }

    /**
     * Enqueue Script
     *
     * @return void
     */
    public function enqueue_scripts() {

        /**
         * All style goes here
         */
        wp_enqueue_style( 'style', plugins_url( 'assets/css/style.css', WCCT_FILE ), false, filemtime( WCCT_PATH . '/assets/css/style.css' ) );

        /**
         * All script goes here
         */
        wp_enqueue_script( 'wcct-admin', plugins_url( 'assets/js/admin.js', WCCT_FILE ), array( 'jquery', 'wp-util' ), filemtime( WCCT_PATH . '/assets/js/admin.js' ), true );

        wp_localize_script(
            'wcct-admin', 'wc_tracking', array(
                'ajaxurl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'wcct-settings' ),
            )
        );
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
    }

    /**
     * Conversion Tracking View Page
     *
     * @return void
     */
    public function conversion_tracking_template() {
        $integrations = wcct_init()->manager->get_integrations();

        include dirname( __FILE__ ) . '/views/admin.php';
    }

    /**
     * Get navigation tab
     *
     * @return array
     */
    public function wcct_get_tab() {

        $sections   = array(
            array(
                'id'    => 'integrations',
                'title' => __( 'Integrations', 'woocommerce-conversion-tracking' ),
            ),
        );

        return apply_filters( 'wcct_nav_tab', $sections );
    }

    /**
     * Show navigation
     *
     * @return void
     */
    public function show_navigation() {
        $count  = count( $this->wcct_get_tab() );
        $tabs   = $this->wcct_get_tab();
        $active = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'integrations';

        if ( $count == 0 ) {
            return;
        }

        $html = '<h2 class="nav-tab-wrapper">';
        foreach ( $tabs as $tab ) {
            $active_class   = ( $tab['id'] == $active ) ? 'nav-tab-active' : '';

            if ( !empty( $tab['id'] ) ) {
                $html  .= sprintf( '<a href="admin.php?page=conversion-tracking&tab=%s" class="nav-tab %s">%s</a>', $tab['id'], $active_class, $tab['title'] );
            } else {
                $html  .= sprintf( '<a href="#" class="nav-tab disabled">%s</a>', $tab['title'] );
            }
        }

        $html   .= '</h2>';

        echo $html;
    }
}
