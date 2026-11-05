<?php

use StorePulse\ConversionTracking\Events\EventFactory;
use StorePulse\ConversionTracking\Events\Pipeline;

/**
 * Event Manager
 *
 * Prints the integrations' head code. Events go through the pipeline, see Pipeline.
 */
class WCCT_Event_Dispatcher {

    /**
     * Store integration object
     *
     * @var array
     */
    private $integrations;

    /**
     * The event pipeline
     *
     * @var Pipeline
     */
    private $pipeline;

    /**
     * Constructor for WCCT_Event_Dispatcher class
     */
    function __construct() {
        $this->init_integrations();

        $this->pipeline = new Pipeline( array( $this, 'get_integrations' ) );
        $this->pipeline->init_hooks();

        add_action( 'wp_head', array( $this, 'enqueue_scripts' ) );

        // registration events
        add_action( 'woocommerce_registration_redirect', array( $this, 'wc_redirect_url' ) );

        // Wishlist Event
        add_action( 'yith_wcwl_added_to_wishlist', array( $this, 'product_wishlist' ) );
        add_action( 'woocommerce_wishlist_add_item', array( $this, 'product_wishlist' ), 10, 0 );
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
     * Active integrations
     *
     * @return array
     */
    public function get_integrations() {
        return $this->integrations;
    }

    /**
     * The event pipeline
     *
     * @return Pipeline
     */
    public function get_pipeline() {
        return $this->pipeline;
    }

    /**
     * Product wishlist event
     *
     * @param int $product_id Product added, from YITH's hook; 0 when the hook doesn't pass one.
     *
     * @return void
     */
    public function product_wishlist( $product_id = 0 ) {
        $this->pipeline->dispatch( EventFactory::add_to_wishlist( $product_id ) );
    }

    /**
     * Do add to cart event
     *
     * @deprecated 3.0.0 The pipeline sends add to cart.
     *
     * @return void
     */
    public function added_to_cart() {
        _deprecated_function( __METHOD__, '3.0.0' );
    }

    /**
     * Initiate checkout events
     *
     * @deprecated 3.0.0 Decided on page load by the pipeline.
     *
     * @return void
     */
    public function initiate_checkout() {
        _deprecated_function( __METHOD__, '3.0.0' );
    }

    /**
     * Check out events
     *
     * @deprecated 3.0.0 Decided on page load by the pipeline.
     *
     * @param  int $order_id
     *
     * @return void
     */
    public function checkout_complete( $order_id ) {
        _deprecated_function( __METHOD__, '3.0.0' );
    }

    /**
     * Single product view event
     *
     * @deprecated 3.0.0 Decided on page load by the pipeline.
     *
     * @return void
     */
    public function product_view() {
        _deprecated_function( __METHOD__, '3.0.0' );
    }

    /**
     * Product category view event
     *
     * @deprecated 3.0.0 Decided on page load by the pipeline.
     *
     * @return void
     */
    public function category_view() {
        _deprecated_function( __METHOD__, '3.0.0' );
    }

    /**
     * Customer registration
     *
     * @deprecated 3.0.0 Decided on page load by the pipeline.
     *
     * @return void
     */
    public function complete_registration() {
        _deprecated_function( __METHOD__, '3.0.0' );
    }

    /**
     * Product search event
     *
     * @deprecated 3.0.0 Decided on page load by the pipeline.
     *
     * @return void
     */
    public function product_search( $query ) {
        _deprecated_function( __METHOD__, '3.0.0' );
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
     * @deprecated 3.0.0 Decided on page load by the pipeline.
     *
     * @return void
     */
    function track_registration() {
        _deprecated_function( __METHOD__, '3.0.0' );
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
