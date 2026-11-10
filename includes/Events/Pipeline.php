<?php

namespace StorePulse\ConversionTracking\Events;

use StorePulse\ConversionTracking\Platforms\Registry;

/**
 * Sends every event to every active platform and prints one footer script per platform.
 *
 * Page events are decided on page load (PageEvents) and printed through wp_add_inline_script.
 * Add to cart is per visitor: it waits in the session (PendingEvents) and reaches the browser through a cart
 * fragment or an uncached request, never through page HTML. Wishlist adds still wait here for the footer.
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
        // After WooCommerce recalculates the totals (priority 20).
        add_action( 'woocommerce_add_to_cart', array( $this, 'added_to_cart' ), 9999, 4 );
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
     * Queue add to cart for the visitor's browser; covers forms, AJAX buttons and Store API (block) buttons.
     *
     * @param string    $cart_item_key Cart item key.
     * @param int       $product_id    Product ID.
     * @param int|float $quantity      Quantity added.
     * @param int       $variation_id  Variation ID.
     *
     * @return void
     */
    public function added_to_cart( $cart_item_key = '', $product_id = 0, $quantity = 1, $variation_id = 0 ) {
        $product = function_exists( 'wc_get_product' ) ? wc_get_product( $variation_id ? $variation_id : $product_id ) : false;
        $event   = $product instanceof \WC_Product ? EventFactory::add_to_cart( $product, $quantity ) : EventFactory::cart( Event::ADD_TO_CART, WC()->cart );

        $this->queue( $event );
    }

    /**
     * Render an event for every platform now and keep it in the visitor's session until their browser runs it.
     *
     * @param Event $event Event.
     *
     * @return bool Whether anything was queued.
     */
    public function queue( Event $event ) {
        $scripts = array();

        foreach ( $this->get_platforms() as $id => $platform ) {
            // Echoed output would break AJAX and REST responses; only returned code is queued.
            ob_start();
            $scripts[ $id ] = $platform->get_event_script( $event );
            ob_end_clean();
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

        if ( $this->sends( Event::ADD_TO_CART ) ) {
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
     * Script that runs the visitor's pending events; the same for every visitor, so pages stay cacheable.
     *
     * @return string
     */
    public function runner_script() {
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
