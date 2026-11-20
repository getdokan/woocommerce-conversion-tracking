<?php

namespace StorePulse\ConversionTracking\Events;

use StorePulse\ConversionTracking\Platforms\Registry;

/**
 * Sends every event to every active platform and prints one footer script per platform.
 *
 * Page events are decided on page load (PageEvents) and printed through wp_add_inline_script.
 * Add to cart is per visitor: it waits in the session (PendingEvents) and reaches the browser through a cart
 * fragment or an uncached request, never through page HTML. Wishlist adds go the same way.
 */
class Pipeline {

    /**
     * Footer script handle prefix; the handle is wcct-{platform}-events.
     */
    const HANDLE_PREFIX = 'wcct-';

    /**
     * Handle of the script that runs pending events.
     */
    const RUNNER_HANDLE = 'wcct-pending-events';

    /**
     * WooCommerce AJAX action that returns pending events; also the cart fragment key.
     */
    const AJAX_ACTION = 'wcct_events';

    /**
     * Cookie with the ID of the newest pending event, set by the server.
     */
    const PENDING_COOKIE = 'wcct_events';

    /**
     * Cookie with the ID of the last pending event the browser ran, set by the browser.
     */
    const SEEN_COOKIE = 'wcct_events_seen';

    /**
     * Cart item data key a plugin sets on items it adds in code, so they send no AddToCart and add nothing to one.
     */
    const AUTO_ADDED = 'wcct_auto_added';

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
     * Lines added inside a shopper's add (bundle items, ticked add-ons), keyed by the shopper's cart item key: [ cart item key, quantity ].
     *
     * @var array[]
     */
    private $nested_lines = array();

    /**
     * REST requests running, innermost last: the Store API batch runs each add as its own request.
     *
     * @var array
     */
    private $rest_requests = array();

    /**
     * Product IDs the request named that an add already used, per request or REST request.
     *
     * @var array[]
     */
    private $named_used = array();

    /**
     * Cart item keys of shopper's adds whose AddToCart was already decided in this add round.
     *
     * @var bool[]
     */
    private $finished_adds = array();

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
     * Whether this request queued pending events.
     *
     * @var bool
     */
    private $queued = false;

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
        // Last, after WooCommerce recalculates the totals (priority 20) and every plugin has added its lines.
        add_action( 'woocommerce_add_to_cart', array( $this, 'add_started' ), PHP_INT_MIN, 0 );
        add_action( 'woocommerce_add_to_cart', array( $this, 'added_to_cart' ), PHP_INT_MAX, 4 );
        add_filter( 'rest_request_before_callbacks', array( $this, 'rest_started' ), 10, 3 );
        add_filter( 'rest_request_after_callbacks', array( $this, 'rest_finished' ), 10, 3 );
        add_filter( 'wcct_track_add_to_cart', array( self::class, 'skip_storegrowth_gifts' ), 5, 4 );
        add_filter( 'woocommerce_add_to_cart_fragments', array( $this, 'cart_fragments' ) );
        add_action( 'wc_ajax_' . self::AJAX_ACTION, array( $this, 'send_pending_events' ) );
        add_action( 'woocommerce_thankyou', array( $this, 'thankyou_fallback' ) );
        add_action( 'wp_footer', array( $this, 'print_footer_scripts' ), 19 );
        add_action( 'wp_footer', array( $this, 'print_footer_markup' ), 21 );
        add_filter( 'wp_inline_script_attributes', array( $this, 'inline_script_attributes' ) );
    }

    /**
     * Active platforms that send in the browser and track this visitor, keyed by ID, in registration order.
     *
     * @return \WCCT_Integration[]
     */
    public function get_platforms() {
        if ( null === $this->platforms ) {
            $this->platforms = Registry::for_visitor( Registry::browser( Registry::normalise( call_user_func( $this->platform_source ) ) ) );
        }

        return $this->platforms;
    }

    /**
     * An add starts with no other add running: forget lines left from earlier adds (added after their event, or inside one that threw).
     *
     * @return void
     */
    public function add_started() {
        // Not the count alone: after a hook threw it stays high, and leftovers would join a later add of the same line.
        if ( null === self::outer_add_key() ) {
            $this->nested_lines  = array();
            $this->finished_adds = array();
        }
    }

    /**
     * Queue add to cart for the visitor's browser; covers forms, AJAX buttons and Store API (block) buttons.
     *
     * One add sends one AddToCart: lines other plugins add inside it (bundle items, ticked add-ons) join it. See the readme FAQ.
     *
     * @param string    $cart_item_key Cart item key.
     * @param int       $product_id    Product ID.
     * @param int|float $quantity      Quantity added.
     * @param int       $variation_id  Variation ID.
     *
     * @return void
     */
    public function added_to_cart( $cart_item_key = '', $product_id = 0, $quantity = 1, $variation_id = 0 ) {
        $cart          = WC()->cart;
        $cart_item_key = (string) $cart_item_key;
        $outer         = self::outer_add_key();

        // Lines added inside the shopper's add are judged when it is done, once plugins have set their cart prices.
        if ( null !== $outer && ! isset( $this->finished_adds[ $outer ] ) ) {
            $this->nested_lines[ $outer ][] = array( $cart_item_key, $quantity );

            return;
        }

        // Added by a hook that runs after CT's, once the shopper's AddToCart was decided: a priced line sends its own, as before 3.0.
        if ( null !== $outer ) {
            if ( $cart && false === has_action( 'woocommerce_add_to_cart', array( $cart, 'calculate_totals' ) ) ) {
                $cart->calculate_totals();
            }

            $item = $cart ? $cart->get_cart_item( $cart_item_key ) : array();

            if ( self::tracks_line( $cart_item_key, $item, true ) ) {
                $lines = $this->lines( $cart, $cart_item_key, $quantity, array() );

                if ( $lines ) {
                    $this->queue( EventFactory::add_to_cart( $lines ) );
                }
            }

            return;
        }

        // The first add of a product the request names uses the name, whatever happens to it.
        $first_named = $this->use_request_name( $product_id, $variation_id );
        $cart_item   = $cart ? $cart->get_cart_item( $cart_item_key ) : array();

        if ( ! self::tracks_line( $cart_item_key, $cart_item, false ) ) {
            $this->take_nested_lines( $cart_item_key );
            $this->finished_adds[ $cart_item_key ] = true;

            return;
        }

        // The grouped form detaches the recalculation, which is where plugins set cart prices; lines they add while it runs join too.
        if ( $cart && false === has_action( 'woocommerce_add_to_cart', array( $cart, 'calculate_totals' ) ) ) {
            $cart->calculate_totals();
        }

        $lines = $this->lines( $cart, $cart_item_key, $quantity, $this->take_nested_lines( $cart_item_key ) );

        // From here a line added inside this add comes too late to join it.
        $this->finished_adds[ $cart_item_key ] = true;

        // Nothing it added is left in the cart.
        if ( ! $lines ) {
            return;
        }

        $event = EventFactory::add_to_cart( $lines );

        /**
         * Filters whether a $0 add is the shopper's own and sends AddToCart; otherwise it is a gift.
         *
         * @since 3.0.0
         *
         * @param bool $track        Whether it is: the request names the product and no earlier add used that name.
         * @param int  $product_id   Product ID.
         * @param int  $variation_id Variation ID.
         */
        if ( $event->get_value() <= 0 && ! apply_filters( 'wcct_track_free_add_to_cart', $first_named, (int) $product_id, (int) $variation_id ) ) {
            return;
        }

        $this->queue( $event );
    }

    /**
     * The click's lines: the shopper's line (if still there), then the counted lines added inside it, each once.
     *
     * @param \WC_Cart|null $cart          Cart.
     * @param string        $cart_item_key Shopper's cart item key.
     * @param int|float     $quantity      Quantity the shopper added.
     * @param array         $nested        Lines added inside it, [ cart item key, quantity ].
     *
     * @return array[] [ 'product' => cart line product, 'quantity' => quantity added ].
     */
    private function lines( $cart, $cart_item_key, $quantity, array $nested ) {
        $added = array( $cart_item_key => EventFactory::quantity( $quantity ) );

        foreach ( $nested as $line ) {
            // Merged into the shopper's own line (more of the same, or removed and added again): the shopper's quantity stands.
            if ( $line[0] === $cart_item_key ) {
                continue;
            }

            $added[ $line[0] ] = ( isset( $added[ $line[0] ] ) ? $added[ $line[0] ] : 0 ) + EventFactory::quantity( $line[1] );
        }

        $lines = array();

        foreach ( $added as $key => $added_quantity ) {
            $item = $cart ? $cart->get_cart_item( (string) $key ) : array();

            // A plugin may have removed the shopper's own line.
            if ( empty( $item['data'] ) || ! $item['data'] instanceof \WC_Product ) {
                continue;
            }

            if ( (string) $key !== $cart_item_key && ! self::tracks_line( (string) $key, $item, true ) ) {
                continue;
            }

            // The line's product carries the price plugins set in the cart (a $0 gift).
            $lines[] = array(
                'product'  => $item['data'],
                'quantity' => isset( $item['quantity'] ) ? min( $added_quantity, EventFactory::quantity( $item['quantity'] ) ) : $added_quantity,
            );
        }

        return $lines;
    }

    /**
     * Take the lines added inside an add, including those under '' (no call stack).
     *
     * @param string $cart_item_key Cart item key.
     *
     * @return array [ cart item key, quantity ] pairs.
     */
    private function take_nested_lines( $cart_item_key ) {
        $lines = array_merge( isset( $this->nested_lines[ $cart_item_key ] ) ? $this->nested_lines[ $cart_item_key ] : array(), isset( $this->nested_lines[''] ) ? $this->nested_lines[''] : array() );

        unset( $this->nested_lines[ $cart_item_key ], $this->nested_lines[''] );

        return $lines;
    }

    /**
     * Note the REST request about to run, so an add can read the product it names.
     *
     * @param mixed            $response Response so far.
     * @param array            $handler  Route handler.
     * @param \WP_REST_Request $request  Request.
     *
     * @return mixed The response, unchanged.
     */
    public function rest_started( $response, $handler, $request ) {
        $this->rest_requests[] = $request instanceof \WP_REST_Request ? $request : null;

        return $response;
    }

    /**
     * Forget the REST request that just ran, and the names it used: object IDs are reused.
     *
     * @param mixed            $response Response.
     * @param array            $handler  Route handler.
     * @param \WP_REST_Request $request  Request.
     *
     * @return mixed The response, unchanged.
     */
    public function rest_finished( $response, $handler, $request ) {
        // Down to this request: one that threw inside it never reached its own after callbacks.
        $index = array_search( $request, $this->rest_requests, true );

        if ( false === $index ) {
            return $response;
        }

        foreach ( array_splice( $this->rest_requests, $index ) as $done ) {
            if ( $done instanceof \WP_REST_Request ) {
                unset( $this->named_used[ 'rest-' . spl_object_id( $done ) ] );
            }
        }

        return $response;
    }

    /**
     * Use up the request's name for a product: true when this is an add to cart request naming it and no add used the name yet.
     *
     * Only add requests name a product: the product form or link, WooCommerce's AJAX add, and the Store API's add item (also in a batch).
     * A gift a plugin adds names nothing: it comes on a page load, coupon, quantity change or other request, or after the shopper's add.
     *
     * @param int $product_id   Product ID.
     * @param int $variation_id Variation ID.
     *
     * @return bool
     */
    private function use_request_name( $product_id, $variation_id ) {
        $rest = $this->rest_requests ? end( $this->rest_requests ) : null;

        if ( $rest instanceof \WP_REST_Request ) {
            $scope = 'rest-' . spl_object_id( $rest );
            $named = 'POST' === strtoupper( $rest->get_method() ) && preg_match( '#^/wc/store(/v\d+)?/cart/(add-item|items)/?$#i', $rest->get_route() ) ? array( $rest->get_param( 'id' ) ) : array();
        } else {
            $scope = 'request';
            $named = self::add_request_names();
        }

        $named = array_map( 'absint', array_filter( $named, 'is_scalar' ) );
        $first = false;

        // The variation and its parent: a sibling variation's gift then finds the parent used.
        foreach ( array_filter( array( absint( $variation_id ), absint( $product_id ) ) ) as $id ) {
            if ( in_array( $id, $named, true ) ) {
                $first                            = $first || empty( $this->named_used[ $scope ][ $id ] );
                $this->named_used[ $scope ][ $id ] = true;
            }
        }

        return $first;
    }

    /**
     * Product IDs a non-REST add to cart request names: the product form or link (with a grouped form's picked children), or WooCommerce's AJAX add.
     *
     * @return array
     */
    private static function add_request_names() {
        // phpcs:disable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- only compared with product IDs.
        if ( isset( $_REQUEST['add-to-cart'] ) ) {
            $named = array( self::add_to_cart_product_id( wp_unslash( $_REQUEST['add-to-cart'] ) ), isset( $_REQUEST['variation_id'] ) ? wp_unslash( $_REQUEST['variation_id'] ) : null );

            // The grouped form names its children as quantity[ id ]; only those the shopper picked.
            if ( isset( $_REQUEST['quantity'] ) && is_array( $_REQUEST['quantity'] ) ) {
                $named = array_merge( $named, array_keys( array_filter( wp_unslash( $_REQUEST['quantity'] ), function ( $picked ) { return is_numeric( $picked ) && $picked > 0; } ) ) );
            }

            return $named;
        }

        $wc_ajax = isset( $_GET['wc-ajax'] ) ? sanitize_key( wp_unslash( $_GET['wc-ajax'] ) ) : '';
        $action  = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';

        if ( 'add_to_cart' === $wc_ajax || ( wp_doing_ajax() && 'woocommerce_add_to_cart' === $action ) ) {
            return array( isset( $_POST['product_id'] ) ? self::add_to_cart_product_id( wp_unslash( $_POST['product_id'] ) ) : null, isset( $_POST['variation_id'] ) ? wp_unslash( $_POST['variation_id'] ) : null );
        }
        // phpcs:enable

        return array();
    }

    /**
     * A requested product ID as WooCommerce's add handlers see it, after plugins remap it (translations, for example).
     *
     * @param mixed $product_id Requested product ID.
     *
     * @return int
     */
    private static function add_to_cart_product_id( $product_id ) {
        if ( ! is_scalar( $product_id ) ) {
            return 0;
        }

        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own filter, applied as its handlers do.
        return absint( apply_filters( 'woocommerce_add_to_cart_product_id', absint( $product_id ) ) );
    }

    /**
     * Whether a cart line counts: the shopper's add sends AddToCart, and a line added inside it joins that event.
     *
     * The shopper's add counts unless it is flagged as auto-added; a line added inside it counts when it has a price and no wcct_auto_added flag.
     *
     * @param string $cart_item_key Cart item key.
     * @param array  $cart_item     Cart item.
     * @param bool   $nested        Whether the line was added inside another add.
     *
     * @return bool
     */
    public static function tracks_line( $cart_item_key, $cart_item, $nested ) {
        $cart_item = is_array( $cart_item ) ? $cart_item : array();
        $track     = empty( $cart_item[ self::AUTO_ADDED ] );

        // A $0 line added inside an add is a gift; a priced one (bundle item, ticked add-on, discounted BOGO item) is part of what the shopper pays.
        if ( $track && $nested ) {
            $track = ! empty( $cart_item['data'] ) && is_object( $cart_item['data'] ) && method_exists( $cart_item['data'], 'get_price' ) && (float) $cart_item['data']->get_price() > 0;
        }

        /**
         * Filters whether a cart line counts toward AddToCart; plugins that add items in code can return false.
         *
         * @since 3.0.0
         *
         * @param bool   $track         Whether it counts: its own AddToCart, or, added inside another add, part of that one.
         * @param string $cart_item_key Cart item key.
         * @param array  $cart_item     Cart item.
         * @param bool   $nested        Whether it was added inside another add.
         */
        return (bool) apply_filters( 'wcct_track_add_to_cart', $track, $cart_item_key, $cart_item, (bool) $nested );
    }

    /**
     * StoreGrowth BOGO gifts send no AddToCart of their own; it also adds them on quantity changes, outside the shopper's add.
     *
     * @param bool   $track         Whether the line counts.
     * @param string $cart_item_key Cart item key.
     * @param array  $cart_item     Cart item.
     * @param bool   $nested        Whether it was added inside another add.
     *
     * @return bool
     */
    public static function skip_storegrowth_gifts( $track, $cart_item_key, $cart_item, $nested ) {
        if ( $nested || empty( $cart_item['bogo_offer'] ) ) {
            return $track;
        }

        return class_exists( 'StorePulse\\StoreGrowth\\Modules\\BoGo\\OrderBogo' ) ? false : $track;
    }

    /**
     * How many woocommerce_add_to_cart actions are running; a hook that threw stays counted.
     *
     * @return int
     */
    private static function running_adds() {
        $running = isset( $GLOBALS['wp_current_filter'] ) ? (array) $GLOBALS['wp_current_filter'] : array();

        return count( array_keys( $running, 'woocommerce_add_to_cart', true ) );
    }

    /**
     * Cart item key of the add this one runs inside, or null for the shopper's own add.
     *
     * Read from the call stack: $wp_current_filter keeps a hook that threw, and WooCommerce catches add exceptions.
     * Without debug_backtrace (disabled by some hosts) the count decides, and the key is ''.
     *
     * @return string|null
     */
    private static function outer_add_key() {
        // A stale entry only adds to this count, so one or none means no add is running around this one.
        if ( self::running_adds() < 2 ) {
            return null;
        }

        if ( ! function_exists( 'debug_backtrace' ) ) {
            return '';
        }

        $keys = array();

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- only while adds are nested.
        foreach ( debug_backtrace( 0 ) as $frame ) {
            if ( ! isset( $frame['function'], $frame['args'][0] ) || isset( $frame['class'] ) || 'woocommerce_add_to_cart' !== $frame['args'][0] ) {
                continue;
            }

            if ( 'do_action' === $frame['function'] ) {
                $keys[] = isset( $frame['args'][1] ) && is_scalar( $frame['args'][1] ) ? (string) $frame['args'][1] : '';
            } elseif ( 'do_action_ref_array' === $frame['function'] ) {
                $keys[] = isset( $frame['args'][1][0] ) && is_scalar( $frame['args'][1][0] ) ? (string) $frame['args'][1][0] : '';
            }
        }

        // Innermost first: the last is the shopper's add.
        return count( $keys ) > 1 ? end( $keys ) : null;
    }

    /**
     * Render an event for every platform now and keep it in the visitor's session until their browser runs it.
     *
     * @param Event $event         Event.
     * @param bool  $start_session Start a WooCommerce session for a visitor without one (wishlist adds come without a cart).
     *
     * @return bool Whether anything was queued.
     */
    public function queue( Event $event, $start_session = false ) {
        $scripts = array();

        foreach ( $this->get_platforms() as $id => $platform ) {
            // Echoed output would break AJAX and REST responses; only returned code is queued.
            ob_start();
            $scripts[ $id ] = $platform->get_event_script( $event );
            ob_end_clean();
        }

        $scripts = array_filter(
            $scripts,
            function ( $code ) {
                return is_string( $code ) && '' !== trim( $code );
            }
        );

        if ( ! $scripts ) {
            return false;
        }

        if ( $start_session ) {
            self::start_session();
        }

        if ( ! $this->pending()->add( $event->get_event_id(), $scripts ) ) {
            return false;
        }

        $this->queued = true;

        // With headers already sent there is no cookie; the event waits in the session and goes out with the next add's fragment or fetch.
        if ( ! headers_sent() ) {
            wc_setcookie( self::PENDING_COOKIE, $event->get_event_id(), 0, is_ssl() );
        }

        return true;
    }

    /**
     * Start a WooCommerce session that outlives the request: REST requests have none, and a guest without a cart has no cookie.
     *
     * @return void
     */
    public static function start_session() {
        if ( ! function_exists( 'WC' ) ) {
            return;
        }

        if ( ! WC()->session && method_exists( WC(), 'initialize_session' ) ) {
            WC()->initialize_session();
        }

        $session = WC()->session;

        if ( $session && method_exists( $session, 'has_session' ) && ! $session->has_session() && method_exists( $session, 'set_customer_session_cookie' ) && ! headers_sent() ) {
            $session->set_customer_session_cookie( true );
        }
    }

    /**
     * The visitor's pending events.
     *
     * @return PendingEvents
     */
    public function pending() {
        return new PendingEvents( function_exists( 'WC' ) && WC()->session ? WC()->session : null );
    }

    /**
     * Pending events the browser has not run yet, after the ID in its seen cookie; code of platforms paused since it was queued is left out.
     *
     * @return array List of {id, scripts}.
     */
    public function unseen_events() {
        $seen   = isset( $_COOKIE[ self::SEEN_COOKIE ] ) && is_string( $_COOKIE[ self::SEEN_COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::SEEN_COOKIE ] ) ) : '';
        $active = $this->get_platforms();

        return array_map(
            function ( $entry ) use ( $active ) {
                $entry['scripts'] = array_intersect_key( $entry['scripts'], $active );

                return $entry;
            },
            $this->pending()->after( $seen )
        );
    }

    /**
     * Add this request's pending events to the AJAX add to cart response.
     *
     * @param array $fragments Cart fragments.
     *
     * @return array
     */
    public function cart_fragments( $fragments ) {
        if ( $this->queued && is_array( $fragments ) ) {
            $fragments[ self::AJAX_ACTION ] = $this->unseen_events();
        }

        return $fragments;
    }

    /**
     * WooCommerce AJAX endpoint: the visitor's pending events, read from their session.
     *
     * @return void
     */
    public function send_pending_events() {
        wp_send_json( array( 'events' => $this->unseen_events() ) );
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
     * Decide this page's events and add one inline footer script per platform, after the pending events runner.
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

        $scripts = array();

        if ( $this->sends( Event::ADD_TO_CART ) || $this->triggers() ) {
            // With consent mode on, each platform's queued code waits for its consent category.
            $scripts[ self::RUNNER_HANDLE ] = \StorePulse\ConversionTracking\Consent\ConsentMode::gate_runner( $this->runner_script(), $this->consent_categories() );
        }

        foreach ( $this->build_footer_scripts( $events ) as $id => $code ) {
            $scripts[ self::handle( $id ) ] = $code;
        }

        foreach ( $scripts as $handle => $code ) {
            wp_register_script( $handle, false, array(), WCCT_VERSION, array( 'in_footer' => true ) );
            wp_enqueue_script( $handle );
            wp_add_inline_script( $handle, $code );

            $this->handles[] = $handle;
        }
    }

    /**
     * Consent category per active platform ID.
     *
     * @return string[]
     */
    public function consent_categories() {
        $categories = array();

        foreach ( $this->get_platforms() as $id => $platform ) {
            $categories[ $id ] = method_exists( $platform, 'get_consent_category' ) ? (string) $platform->get_consent_category() : 'marketing';
        }

        return $categories;
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
     * Whether an active platform sends an event.
     *
     * @param string $name Event name.
     *
     * @return bool
     */
    public function sends( $name ) {
        foreach ( $this->get_platforms() as $platform ) {
            if ( $platform->sends_event( $name ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * jQuery events, besides add to cart, after which the runner asks for pending events: a wishlist plugin's add, when one is active and a platform sends it.
     *
     * @return string[]
     */
    public function triggers() {
        return Wishlist::active() && $this->sends( Event::ADD_TO_WISHLIST ) ? Wishlist::TRIGGERS : array();
    }

    /**
     * Script that runs the visitor's pending events; the same for every visitor, so pages stay cacheable.
     *
     * @return string
     */
    public function runner_script() {
        $triggers = $this->triggers();
        $config = array(
            'endpoint' => \WC_AJAX::get_endpoint( self::AJAX_ACTION ),
            'fragment' => self::AJAX_ACTION,
            'pending'  => self::PENDING_COOKIE,
            'seen'     => self::SEEN_COOKIE,
            // The browser sets the seen cookie with the same attributes as WordPress cookies.
            'path'     => COOKIEPATH ? COOKIEPATH : '/',
            'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
            'secure'   => is_ssl(),
        );

        ob_start();
        ?>
(function (window, document) {
    var config = <?php echo wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
    // IDs already run in this tab session, shared across pages and tabs of the same session.
    var ranKey = 'wcct_events_ran';
    var readRan = function () {
        try { return JSON.parse(window.sessionStorage.getItem(ranKey)) || []; } catch (e) { return []; }
    };
    var ran = readRan();
    var cookie = function (name) {
        var match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
        return match ? decodeURIComponent(match[1]) : '';
    };
    var markSeen = function (id) {
        document.cookie = config.seen + '=' + encodeURIComponent(id) + '; path=' + config.path + (config.domain ? '; domain=' + config.domain : '') + (config.secure ? '; secure' : '') + '; SameSite=Lax';
    };
    // Runs each platform's code in its own script, so one failing platform doesn't stop the others.
    var run = function (entries, pending) {
        var last = '';
        ran = ran.concat(readRan());
        for (var i = 0; entries && i < entries.length; i++) {
            if (!entries[i] || !entries[i].id || ran.indexOf(entries[i].id) > -1) continue;
            ran.push(entries[i].id);
            last = entries[i].id;
            for (var platform in entries[i].scripts) {
                var script = document.createElement('script');
                script.setAttribute('data-no-optimize', '1');
                script.text = entries[i].scripts[platform];
                document.body.appendChild(script);
            }
        }
        try { window.sessionStorage.setItem(ranKey, JSON.stringify(ran.slice(-50))); } catch (e) {}
        // Nothing left on the server: stop asking until the next add.
        if (!last && !(entries && entries.length)) last = pending;
        if (last) markSeen(last);
    };
    // Events come from the session: in the add to cart fragment or from this uncached POST, never from the page HTML.
    var check = function () {
        var pending = cookie(config.pending);
        if (!pending || pending === cookie(config.seen) || !window.fetch) return;
        window.fetch(config.endpoint, { method: 'POST', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (response) { return response.ok ? response.json() : null; })
            .then(function (data) { if (data) run(data.events, pending); })
            .catch(function () {});
    };
    // jQuery may load after this script (deferred or in the footer), so bind again once the page has loaded.
    var bound = false;
    var bindJQuery = function () {
        if (bound || !window.jQuery) return;
        bound = true;
        window.jQuery(document.body).on('added_to_cart', function (e, fragments) {
            if (fragments && fragments[config.fragment]) {
                run(fragments[config.fragment], '');
                delete fragments[config.fragment];
            } else {
                check();
            }
        });
<?php if ( $triggers ) : ?>
        // A wishlist add queued its event on the server.
        window.jQuery(document).on(<?php echo wp_json_encode( implode( ' ', $triggers ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>, check);
<?php endif; ?>
    };
    bindJQuery();
    document.addEventListener('DOMContentLoaded', bindJQuery);
    window.addEventListener('load', bindJQuery);
    document.body.addEventListener('wc-blocks_added_to_cart', check);
    check();
})(window, document);
        <?php
        return trim( ob_get_clean() );
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
