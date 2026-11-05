<?php

namespace StorePulse\ConversionTracking\Events;

use StorePulse\ConversionTracking\Platforms\Registry;

/**
 * Sends every event to every active platform and prints one footer script per platform.
 */
class Pipeline {

    /**
     * Footer script handle prefix; the handle is wcct-{platform}-events.
     */
    const HANDLE_PREFIX = 'wcct-';

    /**
     * Returns the active platforms.
     *
     * @var callable
     */
    private $platform_source;

    /**
     * Active platforms that send in the browser, keyed by ID.
     *
     * @var \WCCT_Integration[]|null
     */
    private $platforms = null;

    /**
     * Code waiting for the footer, per platform ID.
     *
     * @var string[][]
     */
    private $scripts = array();

    /**
     * Handles this request registered.
     *
     * @var string[]
     */
    private $handles = array();

    /**
     * Whether the footer scripts were added.
     *
     * @var bool
     */
    private $printed = false;

    /**
     * Purchase found through woocommerce_thankyou off the order received page.
     *
     * @var Event[]
     */
    private $fallback_events = array();

    /**
     * Raw HTML per platform, printed after the footer scripts.
     *
     * @var string[]
     */
    private $markup = array();

    /**
     * Pipeline over the platforms a callable returns.
     *
     * @param callable $platform_source Returns the active platforms.
     */
    public function __construct( callable $platform_source ) {
        $this->platform_source = $platform_source;
    }

    /**
     * Hook into WordPress and WooCommerce.
     *
     * @return void
     */
    public function init_hooks() {
        add_action( 'woocommerce_add_to_cart', array( $this, 'added_to_cart' ), 9999, 0 );
        add_action( 'woocommerce_thankyou', array( $this, 'thankyou_fallback' ) );
        add_action( 'wp_footer', array( $this, 'print_footer_scripts' ), 19 );
        add_action( 'wp_footer', array( $this, 'print_footer_markup' ), 21 );
        add_filter( 'wp_inline_script_attributes', array( $this, 'inline_script_attributes' ) );
    }

    /**
     * Active platforms that send in the browser, keyed by ID, in registration order.
     *
     * @return \WCCT_Integration[]
     */
    public function get_platforms() {
        if ( null === $this->platforms ) {
            $this->platforms = Registry::browser( Registry::normalise( call_user_func( $this->platform_source ) ) );
        }

        return $this->platforms;
    }

    /**
     * Add to cart in this request; the code is printed in this request's footer.
     *
     * @return void
     */
    public function added_to_cart() {
        $this->dispatch( EventFactory::cart( Event::ADD_TO_CART, WC()->cart ) );
    }

    /**
     * Purchase for funnel and custom thank-you pages that fire woocommerce_thankyou off WooCommerce's order received page.
     *
     * @param int $order_id Order ID.
     *
     * @return void
     */
    public function thankyou_fallback( $order_id ) {
        if ( $this->printed || $this->fallback_events || ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) ) {
            return;
        }

        $order = OrderReceived::get_owned_order( $order_id );

        if ( $order ) {
            $this->fallback_events[] = EventFactory::purchase( $order );
        }
    }

    /**
     * Events for this page: the page-load decisions plus the woocommerce_thankyou fallback.
     *
     * @return Event[]
     */
    public function get_page_events() {
        return PageEvents::current( $this->fallback_events );
    }

    /**
     * Render an event for every platform now and keep the code for the footer.
     *
     * @param Event $event Event.
     *
     * @return void
     */
    public function dispatch( Event $event ) {
        foreach ( $this->get_platforms() as $id => $platform ) {
            $this->add_script( $id, $platform->get_event_script( $event ) );
        }
    }

    /**
     * Add code to a platform's footer script.
     *
     * @param string $platform_id Platform ID.
     * @param mixed  $code        JavaScript, without script tags.
     *
     * @return void
     */
    public function add_script( $platform_id, $code ) {
        if ( ! is_string( $code ) || '' === trim( $code ) ) {
            return;
        }

        $this->scripts[ $platform_id ][] = $code;
    }

    /**
     * Footer code per platform ID for the given page events, in platform order. Paused and inactive platforms get nothing.
     *
     * @param Event[] $events Page events.
     *
     * @return string[]
     */
    public function build_footer_scripts( array $events ) {
        $scripts = array();

        foreach ( $this->get_platforms() as $id => $platform ) {
            $this->add_script( $id, $platform->get_footer_script( $events ) );

            if ( ! empty( $this->scripts[ $id ] ) ) {
                $scripts[ $id ] = implode( "\n", $this->scripts[ $id ] );
            }
        }

        $this->scripts = array();

        return $scripts;
    }

    /**
     * Raw HTML per platform ID for the given page events, in platform order.
     *
     * @param Event[] $events Page events.
     *
     * @return string[]
     */
    public function build_footer_markup( array $events ) {
        $markup = array();

        foreach ( $this->get_platforms() as $id => $platform ) {
            $html = $platform->get_footer_markup( $events );

            if ( is_string( $html ) && '' !== trim( $html ) ) {
                $markup[ $id ] = $html;
            }
        }

        return $markup;
    }

    /**
     * Decide this page's events and add one inline footer script per platform.
     *
     * @return void
     */
    public function print_footer_scripts() {
        if ( $this->printed || is_admin() || ! function_exists( 'WC' ) ) {
            return;
        }

        $this->printed = true;
        $events        = $this->get_page_events();
        $this->markup  = $this->build_footer_markup( $events );

        foreach ( $this->build_footer_scripts( $events ) as $id => $code ) {
            $handle = self::handle( $id );

            wp_register_script( $handle, false, array(), WCCT_VERSION, array( 'in_footer' => true ) );
            wp_enqueue_script( $handle );
            wp_add_inline_script( $handle, $code );

            $this->handles[] = $handle;
        }
    }

    /**
     * Print the platforms' raw HTML, after the footer scripts.
     *
     * @return void
     */
    public function print_footer_markup() {
        foreach ( $this->markup as $html ) {
            echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- merchant HTML, admin-only setting.
        }

        $this->markup = array();
    }

    /**
     * Mark our inline scripts so optimisation plugins leave them alone.
     *
     * @param array $attributes Inline script attributes.
     *
     * @return array
     */
    public function inline_script_attributes( $attributes ) {
        if ( is_array( $attributes ) && isset( $attributes['id'] ) && in_array( preg_replace( '/-js-(before|after)$/', '', (string) $attributes['id'] ), $this->handles, true ) ) {
            $attributes['data-no-optimize'] = '1';
        }

        return $attributes;
    }

    /**
     * Footer script handle for a platform.
     *
     * @param string $platform_id Platform ID.
     *
     * @return string
     */
    public static function handle( $platform_id ) {
        return self::HANDLE_PREFIX . str_replace( '_', '-', strtolower( preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $platform_id ) ) ) . '-events';
    }
}
