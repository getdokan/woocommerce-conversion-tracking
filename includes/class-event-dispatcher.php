<?php

/**
 * Event Manager
 */
class WCCT_Event_Dispatcher {

    /**
     * Store integration object
     *
     * @var array
     */
    private $integrations;

    /**
     * Constructor for WCCT_Event_Dispatcher class
     */
    function __construct() {
        add_action( 'plugins_loaded', array( $this, 'init_integrations' ) );
        add_action( 'wp_head', array( $this, 'enqueue_scripts' ) );

        // purchase events
        add_action( 'woocommerce_add_to_cart', array( $this, 'added_to_cart' ), 9999, 4 );
        add_action( 'woocommerce_after_checkout_form', array( $this, 'initiate_checkout' ) );
        add_action( 'woocommerce_thankyou', array( $this, 'checkout_complete' ) );

        // view events
        add_action( 'woocommerce_after_single_product', array( $this, 'product_view' ) );
        add_action( 'woocommerce_after_shop_loop', array( $this, 'category_view' ) );

        // registration events
        add_action( 'woocommerce_registration_redirect', array( $this, 'wc_redirect_url' ) );
        add_action( 'template_redirect', array( $this, 'track_registration' ) );

        // Search Event
        add_action( 'pre_get_posts', array( $this, 'product_search' ) );

        // Wishlist Event
        add_filter( 'yith_wcwl_added_to_wishlist', array( $this, 'product_wishlist' ) );
        add_action( 'woocommerce_wishlist_add_item', array( $this, 'product_wishlist' ) );
    }

    /**
     * Intantiate integrations
     *
     * @return object
     */
    public function init_integrations() {
        $manager = wcct_init()->manager;
        $this->integrations = $manager->get_active_integrations();

        return $this->integrations;
    }

    /**
     * Do add to cart event
     *
     * @return void
     */
    public function added_to_cart() {
        $this->dispatch_event( 'add_to_cart' );
    }

    /**
     * Initiate checkout events
     *
     * @return void
     */
    public function initiate_checkout() {
        $this->dispatch_event( 'initiate_checkout' );
    }

    /**
     * Check out events
     *
     * @param  int $order_id
     *
     * @return void
     */
    public function checkout_complete( $order_id ) {
        $this->dispatch_event( 'checkout', $order_id );
    }

    /**
     * Single product view event
     *
     * @return void
     */
    public function product_view() {
        $this->dispatch_event( 'product_view' );
    }

    /**
     * Product category view event
     *
     * @return void
     */
    public function category_view() {
        $this->dispatch_event( 'category_view' );
    }

    /**
     * Customer registration
     *
     * @return void
     */
    public function complete_registration() {
        $this->dispatch_event( 'registration' );
    }

    /**
     * Product search event
     *
     * @return void
     */
    public function product_search( $query ) {
        if ( ! is_admin() && $query->is_main_query() && $query->is_search() ) {
            $this->dispatch_event( 'search' );
        }
    }

    /**
     * Product wishlist event
     *
     * @return void
     */
    public function product_wishlist() {
        $this->dispatch_event( 'add_to_wishlist' );
    }

    /**
     * Adds a url query arg to determine newly registered user
     *
     * @uses woocommerce_registration_redirect action
     *
     * @param string $redirect
     *
     * @return string
     */
    function wc_redirect_url( $redirect ) {

        $redirect = add_query_arg( array(
            '_wc_user_reg' => 'true'
        ), $redirect );

        return $redirect;
    }

    /**
     * Print registration code when particular GET tag exists
     *
     * @uses add_action()
     * @return void
     */
    function track_registration() {
        if ( isset( $_GET['_wc_user_reg'] ) && $_GET['_wc_user_reg'] == 'true' ) {
            add_action( 'wp_footer', array( $this, 'complete_registration' ) );
        }
    }

    /**
     * Dispatch an event
     *
     * @param  string $event
     * @param  mixed $value
     *
     * @return void
     */
    private function dispatch_event( $event, $value = '' ) {
        foreach ( $this->integrations as $integration ) {
            if ( $integration->supports( $event ) ) {
                if ( method_exists( $integration, $event ) ) {
                    $integration->$event( $value );
                }
            }
        }
    }

    /**
     * Integration enqueue script
     *
     * @return void
     */
    public function enqueue_scripts() {

        echo '<!-- Starting: Conversion Tracking for WooCommerce (https://wordpress.org/plugins/woocommerce-conversion-tracking/) -->' . PHP_EOL;
        foreach ( $this->integrations as $integration ) {
            $integration->enqueue_script();
        }
        echo '<!-- End: Conversion Tracking for WooCommerce Codes -->' . PHP_EOL;
    }
}
