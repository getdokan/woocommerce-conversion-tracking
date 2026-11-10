<?php

use StorePulse\ConversionTracking\Events\Event;
use StorePulse\ConversionTracking\Events\OrderReceived;
use StorePulse\ConversionTracking\Events\UserData;
use StorePulse\ConversionTracking\Platforms\PlatformSettings;
use StorePulse\ConversionTracking\Settings\LegacyView;

/**
 * WCCT gate way facebook
 */
class WCCT_Integration_Facebook extends WCCT_Integration {

    /**
     * fbevents.js version string the fallback beacon sends as v; update it with each release
     */
    const PIXEL_VERSION = '2.9.435';

    /**
     * Settings key per Meta event name, where they differ
     */
    const EVENT_SETTINGS = array(
        'CompleteRegistration' => 'Registration',
    );

    /**
     * Constructor for WC_Conversion_Tracking_Gateway_Facebook
     */
    function __construct() {
        $this->id        = 'facebook';
        $this->name      = __( 'Facebook', 'woocommerce-conversion-tracking' );
        $this->enabled   = true;
        $this->supports  = array(
            'add_to_cart',
            'checkout',
            'initiate_checkout',
            'registration',
            'product_view',
            'search',
        );
    }

    /**
     * Get Settings
     *
     * @return array
     */
    public function get_settings() {
        $settings = array(
            'id'      => array(
                'type'      => 'text',
                'name'      => 'pixel_id',
                'label'     => __( 'Pixel ID', 'woocommerce-conversion-tracking' ),
                'required'  => true,
                'value'     => '',
                'help'      => sprintf( __( 'Find the Pixel ID from <a href="%s" target="_blank">here</a>.', 'woocommerce-conversion-tracking' ), 'https://www.facebook.com/ads/manager/pixel/facebook_pixel' ) . ' ' . __( 'Logged-in shoppers\' email is sent for advanced matching. Hashed before it\'s sent to the platform.', 'woocommerce-conversion-tracking' )
            ),
            'events'    => array(
                'type'    => 'multicheck',
                'name'    => 'events',
                'label'   => __( 'Events', 'woocommerce-conversion-tracking' ),
                'value'   => '',
                'options' => array(
                    'ViewContent'      => __( 'View Product', 'woocommerce-conversion-tracking' ),
                    'AddToCart'        => __( 'Add to Cart', 'woocommerce-conversion-tracking' ),
                    'InitiateCheckout' => __( 'Initiate Checkout', 'woocommerce-conversion-tracking' ),
                    'Purchase'         => __( 'Purchase', 'woocommerce-conversion-tracking' ),
                    'Search'           => __( 'Search', 'woocommerce-conversion-tracking' ),
                    'Registration'     => __( 'Complete Registration', 'woocommerce-conversion-tracking' )
                )
            ),
        );

        return apply_filters( 'wcct_settings_fb', $settings );
    }

    /**
     * Build an fbq() call
     *
     * @param  string $event_name Event name, or the pixel ID for init
     * @param  array  $params     Custom data
     * @param  string $method     fbq method
     * @param  array  $options    Options such as eventID, sent as the fourth argument when set
     *
     * @return string
     */
    public function build_event( $event_name, $params = array(), $method = 'track', $options = array() ) {
        $code = sprintf( "fbq('%s', '%s', %s", $method, $event_name, self::encode( $params ) );

        return $code . ( $options ? ', ' . self::encode( $options ) : '' ) . ');';
    }

    /**
     * JavaScript that sends an event to every pixel that has it on, with one event ID
     *
     * Purchase uses the order's ID; any other ID is made in the browser, so a cached page never repeats one.
     *
     * @param  string $event_name Meta event name
     * @param  array  $params     Custom data
     *
     * @return string Empty when no pixel has the event on
     */
    public function track( $event_name, $params = array() ) {
        $pixels = $this->get_event_pixels( $event_name );

        if ( ! $pixels ) {
            return '';
        }

        $event_id = $this->get_event_id();
        $code     = '';

        foreach ( $pixels as $pixel_id ) {
            $code .= sprintf( 'wcfbq(%s, %s, %s, %s);', self::encode( (string) $pixel_id ), self::encode( $event_name ), self::encode( $params ), '' !== $event_id ? self::encode( $event_id ) : 'id' );
        }

        return '' !== $event_id ? $code : 'window.wcctMeta && (function (id) {' . $code . '})(wcctMeta.eventId());';
    }

    /**
     * Pixel IDs that have an event on
     *
     * @param  string $event_name Meta event name
     *
     * @return string[]
     */
    public function get_event_pixels( $event_name ) {
        $pixel_id = $this->get_pixel_id();

        return '' !== $pixel_id && $this->event_enabled( self::settings_key( $event_name ) ) ? array( $pixel_id ) : array();
    }

    /**
     * The pixel ID
     *
     * @return string
     */
    public function get_pixel_id() {
        $values = $this->get_platform_values();

        if ( null !== $values ) {
            return PlatformSettings::text( $values, 'pixel_id' );
        }

        $settings = $this->get_integration_settings();

        return ! empty( $settings[0]['pixel_id'] ) && is_scalar( $settings[0]['pixel_id'] ) ? (string) $settings[0]['pixel_id'] : '';
    }

    /**
     * 2.x event keys => 3.0 events, for event_enabled()
     *
     * @return array
     */
    protected function get_v1_event_map() {
        return LegacyView::FACEBOOK_EVENTS;
    }

    /**
     * Order-derived ID of the Purchase being rendered; empty for other events, whose ID the browser makes
     *
     * @return string
     */
    public function get_event_id() {
        $event = $this->current_event;

        return $event && Event::PURCHASE === $event->get_name() && $event->get_order_id() > 0 ? $event->get_event_id() : '';
    }

    /**
     * Settings key of a Meta event
     *
     * @param  string $event_name Meta event name
     *
     * @return string
     */
    public static function settings_key( $event_name ) {
        return isset( self::EVENT_SETTINGS[ $event_name ] ) ? self::EVENT_SETTINGS[ $event_name ] : $event_name;
    }

    /**
     * Product IDs as Meta's content_ids: an array of strings
     *
     * @param  array $ids Product IDs
     *
     * @return string[]
     */
    public static function content_ids( $ids ) {
        return array_values( array_map( 'strval', array_filter( (array) $ids, 'is_scalar' ) ) );
    }

    /**
     * JSON for an inline script; an empty array becomes an object
     *
     * @param  mixed $data
     *
     * @return string
     */
    public static function encode( $data ) {
        if ( is_array( $data ) && ! $data ) {
            $data = new stdClass();
        }

        return wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_HEX_TAG );
    }

    /**
     * Hashed advanced matching for init: the verified order's billing details, or the logged-in user's email
     *
     * @return array
     */
    public function get_advanced_matching() {
        $order = OrderReceived::get_order();

        if ( $order ) {
            return UserData::from_order( $order );
        }

        if ( is_user_logged_in() ) {
            return UserData::hash_fields( array( 'em' => wp_get_current_user()->user_email ) );
        }

        return array();
    }

    /**
     * Enqueue script
     *
     * @return void
     */
    public function enqueue_script() {

        if ( ! $this->is_enabled() ) {
            return;
        }

        $facebook_pixel_id = $this->get_pixel_id();

        // Printed first: PageView takes its event ID from wcctMeta.eventId().
        $this->print_event_script();
        ?>
        <script>
            !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
            n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
            n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
            t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,
            document,'script','https://connect.facebook.net/en_US/fbevents.js');

            <?php
            // With consent mode on: revoke before init until marketing consent, grant on consent.
            echo $this->consent_sync_js( "fbq('consent', granted ? 'grant' : 'revoke');" ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

            echo $this->build_event( esc_js( $facebook_pixel_id ), $this->get_advanced_matching(), 'init' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON from wp_json_encode().

            // The browser makes the ID, so a cached page never repeats it.
            echo "fbq('track', 'PageView', {}, { eventID: wcctMeta.eventId() });";

            echo $this->fallback_start_js(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed JavaScript.
            ?>
        </script>
        <?php

        $this->print_consent_script( $this->consent_wrap_js( 'wcfbq' ) );
    }

    /**
     * Enqueue add to cart event
     *
     * @return string|void JavaScript for the pipeline
     */
    public function add_to_cart() {
        $event = $this->current_event;

        // The product just added; the whole cart only when the event has no product.
        if ( $event && $event->get_product() ) {
            $product_ids = array( \StorePulse\ConversionTracking\Events\ProductId::for_product( $event->get_product(), $this->id ) );
            $value       = wc_format_decimal( $event->get_value(), wc_get_price_decimals() );
        } else {
            $product_ids = $this->get_content_ids_from_cart( WC()->cart->get_cart() );
            $value       = WC()->cart->total ? WC()->cart->total : 0;
        }

        return $this->track( 'AddToCart', array(
            'content_ids'  => self::content_ids( $product_ids ),
            'content_type' => 'product',
            'value'        => $value,
            'currency'     => get_woocommerce_currency()
        ) );
    }

    /**
     * Added to cart
     *
     * @deprecated 3.0.0 Add to cart reaches the browser through the pipeline's pending events.
     *
     * @return void
     */
    public function add_to_cart_ajax() {
        _deprecated_function( __METHOD__, '3.0.0' );
    }

    /**
     * Fire initiate checkout events
     *
     * @return string|void JavaScript for the pipeline
     */
    public function initiate_checkout() {
        $product_ids = $this->get_content_ids_from_cart( WC()->cart->get_cart() );

        return $this->track( 'InitiateCheckout', array(
            'num_items'    => WC()->cart->get_cart_contents_count(),
            'content_ids'  => self::content_ids( $product_ids ),
            'content_type' => 'product',
            'value'        => WC()->cart->total ? WC()->cart->total : 0,
            'currency'     => get_woocommerce_currency()
        ) );
    }

    /**
     * Check Out
     *
     * @param  integer $order_id
     *
     * @return string|void JavaScript for the pipeline
     */
    public function checkout( $order_id ) {
        if ( ! $this->get_event_pixels( 'Purchase' ) ) {
            return;
        }

        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            return;
        }

        // Variations send their own ID, so the IDs are products, never groups.
        $product_ids = \StorePulse\ConversionTracking\Events\ProductId::for_order( $order, $this->id );

        return $this->track( 'Purchase', array(
            'content_ids'  => self::content_ids( $product_ids ),
            'content_type' => 'product',
            'value'        => $this->purchase_value( $order ),
            'currency'     => $order->get_currency()
        ) );
    }

    /**
     * The Purchase is taken once fbevents.js has loaded and run the queue
     *
     * @return string
     */
    protected function purchase_sent_check() {
        return "window.fbq && typeof window.fbq.callMethod === 'function'";
    }

    /**
     * Under consent mode the Purchase only leaves once marketing is allowed
     *
     * @return string
     */
    protected function purchase_consent_category() {
        return 'marketing';
    }

    /**
     * Registration script
     *
     * @return string|void JavaScript for the pipeline
     */
    public function registration() {
        return $this->track( 'CompleteRegistration' );
    }

    /**
     * View product event
     *
     * @return string|void JavaScript for the pipeline
     */
    public function product_view() {
        // The variation the page opens on when one is picked (the event's product); else a variable product is sent as its group.
        $product = $this->current_event && $this->current_event->get_product() ? $this->current_event->get_product() : \StorePulse\ConversionTracking\Events\ProductId::viewed( wc_get_product( get_queried_object_id() ) );

        if ( ! $product instanceof WC_Product || ! $this->get_event_pixels( 'ViewContent' ) ) {
            return;
        }

        $price = $product->get_price();

        return $this->track( 'ViewContent', array(
            'content_name' => $product->get_title(),
            'content_ids'  => self::content_ids( array( \StorePulse\ConversionTracking\Events\ProductId::for_product( $product, $this->id ) ) ),
            'content_type' => $product instanceof WC_Product_Variable ? 'product_group' : 'product',
            'value'        => $price ? $price : 0.00,
            'currency'     => get_woocommerce_currency(),
        ) );
    }

    /**
     * Store search event, for product searches
     *
     * @return string|void JavaScript for the pipeline
     */
    public function search() {
        $search    = $this->current_event ? $this->current_event->get( 'search_string', '' ) : get_search_query( false );
        $post_type = $this->current_event ? $this->current_event->get( 'post_type', '' ) : get_query_var( 'post_type' );

        if ( ! is_string( $search ) || '' === trim( $search ) || 'product' !== $post_type ) {
            return;
        }

        return $this->track( 'Search', array(
            'search_string' => $search,
        ) );
    }

    /**
     * Return categories for products/pixel
     *
     * @param string $id
     *
     * @return array
     */
    public function get_product_categories( $product_id ) {
        $category_path    = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'all' ) );
        $content_category = array_values( array_map( function($item) {
            return $item->name;
        }, $category_path ) );

        $content_category_slice = array_slice( $content_category, -1 );
        $categories             = empty( $content_category ) ? '""' : implode(', ', $content_category);

        return array(
            'name'       => array_pop( $content_category_slice ),
            'categories' => $categories
        );
    }

    /**
     * Starts the image fallback's clock where the base code runs; with consent mode on, only once marketing is allowed
     *
     * @return string
     */
    protected function fallback_start_js() {
        $start = 'window.wcctMeta && wcctMeta.start();';

        return $this->consent_mode_on() ? $this->consent_when_js( $start ) : $start;
    }

    /**
     * Print wcfbq(): sends an event to one pixel through fbq, and through the /tr image only when the Meta library didn't load
     *
     * @return void
     */
    public function print_event_script() {
        ?>
        <script>
            (function (window, document) {
                if (window.wcfbq) return;
                var version = '<?php echo esc_js( self::PIXEL_VERSION ); ?>';
                var standard = ['AddPaymentInfo', 'AddToCart', 'AddToWishlist', 'CompleteRegistration', 'Contact', 'CustomizeProduct', 'Donate', 'FindLocation', 'InitiateCheckout', 'Lead', 'PageView', 'Purchase', 'Schedule', 'Search', 'StartTrial', 'SubmitApplication', 'Subscribe', 'ViewContent'];
                var pending = [];
                var ready = document.readyState === 'complete';
                // When the base code ran (wcctMeta.start()); no image goes out before that.
                var started = 0;
                var loaded = function () {
                    return !!(window.fbq && window.fbq.callMethod);
                };
                // A consent revoke waiting in the queue of a library that never loaded also holds back the fallback.
                var revoked = function () {
                    var queue = window.fbq && window.fbq.queue, state = '';
                    for (var i = 0; queue && i < queue.length; i++) {
                        if (queue[i] && queue[i][0] === 'consent') state = queue[i][1];
                    }
                    return state === 'revoke';
                };
                var cleanUrl = <?php echo \StorePulse\ConversionTracking\Privacy\OrderUrl::cleaner_js(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
                var uuid = function () {
                    if (window.crypto && window.crypto.randomUUID) return window.crypto.randomUUID();
                    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
                        var r = Math.random() * 16 | 0;
                        return (c == 'x' ? r : (r & 0x3 | 0x8)).toString(16);
                    });
                };
                // eid is the parameter Meta reads; event_id carries the same value.
                var beacon = function (pixelId, eventName, params, eventId) {
                    var query = [
                        'id=' + encodeURIComponent(pixelId),
                        'ev=' + encodeURIComponent(eventName),
                        'eid=' + encodeURIComponent(eventId),
                        'event_id=' + encodeURIComponent(eventId),
                        'dl=' + encodeURIComponent(cleanUrl(document.location.href)),
                        'if=false',
                        'ts=' + new Date().getTime()
                    ];
                    if (document.referrer) query.push('rl=' + encodeURIComponent(cleanUrl(document.referrer)));
                    for (var key in params) {
                        if (Object.prototype.hasOwnProperty.call(params, key) && params[key] !== null && params[key] !== undefined) {
                            query.push('cd[' + encodeURIComponent(key) + ']=' + encodeURIComponent(typeof params[key] == 'object' ? JSON.stringify(params[key]) : String(params[key])));
                        }
                    }
                    query.push('v=' + encodeURIComponent(version));
                    new Image(1, 1).src = 'https://www.facebook.com/tr/?' + query.join('&');
                };
                var flush = function () {
                    var calls = pending;
                    pending = [];
                    if (loaded() || revoked()) return;
                    for (var i = 0; i < calls.length; i++) beacon.apply(null, calls[i]);
                };
                // The library gets until the page has loaded and a second after the base code ran.
                var schedule = function () {
                    if (!started || !ready || !pending.length) return;
                    window.setTimeout(flush, Math.max(0, started + 1000 - new Date().getTime()));
                };
                if (!ready) {
                    window.addEventListener('load', function () {
                        ready = true;
                        schedule();
                    });
                }
                window.wcfbq = function (pixelId, eventName, params, eventId) {
                    if (typeof pixelId != 'string' || !pixelId.replace(/\s+/g, '') || typeof eventName != 'string' || !/^[A-Za-z0-9_]+$/.test(eventName)) return;
                    params = params && typeof params == 'object' ? params : {};
                    eventId = typeof eventId == 'string' && eventId ? eventId : window.wcctMeta.eventId();
                    // Without the base code there is no Meta tag on the page, so nothing is sent.
                    if (typeof window.fbq != 'function') return eventId;
                    window.fbq(standard.indexOf(eventName) > -1 ? 'trackSingle' : 'trackSingleCustom', pixelId, eventName, params, { eventID: eventId });
                    if (!loaded()) {
                        pending.push([pixelId, eventName, params, eventId]);
                        schedule();
                    }
                    return eventId;
                };
                // Outside wcfbq, which consent mode wraps.
                window.wcctMeta = {
                    eventId: function () {
                        return 'wcct_' + uuid();
                    },
                    start: function () {
                        if (started) return;
                        started = new Date().getTime();
                        schedule();
                    }
                };
                window.wcfbq.eventId = window.wcctMeta.eventId;
            })(window, document);
        </script>
        <?php
    }
}
