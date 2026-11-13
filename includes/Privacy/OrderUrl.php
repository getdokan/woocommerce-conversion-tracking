<?php

namespace StorePulse\ConversionTracking\Privacy;

/**
 * Keeps the order key and other order parameters out of every URL a platform receives.
 */
class OrderUrl {

    /**
     * Priority of the head script on wp_head; before any base code.
     */
    const HEAD_PRIORITY = -1000;

    /**
     * ID of the head script tag.
     */
    const SCRIPT_ID = 'wcct-order-url';

    /**
     * Query parameters that identify an order. Any parameter whose value holds an order key is removed too.
     */
    const PARAMS = array( 'key', 'order', 'order_id', 'order-pay', 'order_key' );

    /**
     * Parameters removed only when their value is a number; archives sort with ?order=desc.
     */
    const NUMERIC_PARAMS = array( 'order' );

    /**
     * Returns the active platforms; the head script is only printed when there is one.
     *
     * @var callable
     */
    private $platform_source;

    /**
     * Head script over the platforms a callable returns.
     *
     * @param callable $platform_source Returns the active platforms.
     */
    public function __construct( callable $platform_source ) {
        $this->platform_source = $platform_source;
    }

    /**
     * Hook into WordPress.
     *
     * @return void
     */
    public function init_hooks() {
        add_action( 'wp_head', array( $this, 'print_head_script' ), self::HEAD_PRIORITY );
    }

    /**
     * Query parameter names removed from URLs sent to platforms.
     *
     * @return string[]
     */
    public static function params() {
        /**
         * Query parameters removed from every URL sent to a platform.
         *
         * @since 3.0.0
         *
         * @param string[] $params Lower-case parameter names.
         */
        $params = apply_filters( 'wcct_order_url_params', self::PARAMS );
        $params = is_array( $params ) ? array_filter( array_map( 'strtolower', array_filter( $params, 'is_string' ) ) ) : array();

        // The order key is a bearer credential; it is always removed.
        return array_values( array_unique( array_merge( array( 'key' ), $params ) ) );
    }

    /**
     * JavaScript function expression that returns a URL without the order parameters.
     *
     * @return string
     */
    public static function cleaner_js() {
        return 'function (url) {
                    var names = ' . wp_json_encode( self::params(), JSON_HEX_TAG | JSON_HEX_AMP ) . ', numeric = ' . wp_json_encode( self::NUMERIC_PARAMS ) . ';
                    try {
                        url = String(url);
                        var hashPos = url.indexOf(\'#\');
                        var hash = hashPos > -1 ? url.substring(hashPos) : \'\';
                        var base = hashPos > -1 ? url.substring(0, hashPos) : url;
                        var queryPos = base.indexOf(\'?\');
                        if (queryPos == -1) return url;
                        var pairs = base.substring(queryPos + 1).split(\'&\'), kept = [];
                        for (var i = 0; i < pairs.length; i++) {
                            var pair = pairs[i];
                            try { pair = decodeURIComponent(pair.replace(/\+/g, \' \')); } catch (e) {}
                            pair = pair.toLowerCase();
                            var name = pair.split(\'=\')[0], value = pair.substring(name.length + 1);
                            var drop = names.indexOf(name) > -1 && (numeric.indexOf(name) == -1 || /^\d+$/.test(value));
                            if (!drop && pair.indexOf(\'wc_order_\') == -1) kept.push(pairs[i]);
                        }
                        return base.substring(0, queryPos) + (kept.length ? \'?\' + kept.join(\'&\') : \'\') + hash;
                    } catch (e) {
                        return String(url).split(\'?\')[0];
                    }
                }';
    }

    /**
     * JavaScript expression for a gtag config object with page_location and page_referrer cleaned when they need it.
     *
     * @return string
     */
    public static function page_config_js() {
        return '(function (cleanUrl) {
                var config = {};
                var pageLocation = cleanUrl(window.location.href);
                if (pageLocation != window.location.href) config.page_location = pageLocation;
                if (document.referrer) {
                    var referrer = cleanUrl(document.referrer);
                    if (referrer != document.referrer) config.page_referrer = referrer;
                }
                return config;
            })(' . self::cleaner_js() . ')';
    }

    /**
     * Inline script for a thank-you page: keep the order ID and key in window.wcctOrder, then clean the address bar.
     *
     * @param int $order_id Order ID WooCommerce shows on this page.
     *
     * @return string
     */
    public static function head_script( $order_id ) {
        return '(function (window, document) {
            var cleanUrl = ' . self::cleaner_js() . ';
            var decode = function (value) {
                try { return decodeURIComponent(value.replace(/\+/g, \' \')); } catch (e) { return value; }
            };
            try {
                var href = window.location.href, key = \'\';
                var match = /[?&](?:key|order_key)=([^&#]*)/.exec(href);
                if (match) {
                    key = decode(match[1]);
                } else {
                    // Funnel pages may carry the key under another name.
                    var any = /[?&][^=&#]*=([^&#]*wc_order_[^&#]*)/i.exec(href);
                    key = any ? decode(any[1]) : \'\';
                }
                window.wcctOrder = { id: ' . absint( $order_id ) . ', key: key };
                var cleaned = cleanUrl(href);
                if (cleaned == href || !window.history || !window.history.replaceState) return;
                window.history.replaceState(window.history.state, \'\', cleaned);
                // Forms that post back to this page (the create account block) still need the key.
                document.addEventListener(\'submit\', function (e) {
                    var form = e.target, action = form && form.getAttribute ? form.getAttribute(\'action\') : null;
                    if (form && form.tagName == \'FORM\' && (!action || action.charAt(0) == \'#\')) form.action = href.split(\'#\')[0] + (action || \'\');
                }, true);
            } catch (e) {}
        })(window, document);';
    }

    /**
     * Print the head script on order pages when a platform is active; base codes print after it and read the cleaned URL.
     *
     * @return void
     */
    public function print_head_script() {
        if ( is_admin() || ! self::is_order_page() ) {
            return;
        }

        $platforms = call_user_func( $this->platform_source );

        if ( empty( $platforms ) ) {
            return;
        }

        // Optimisation plugins must not defer it: it has to run before every base code.
        wp_print_inline_script_tag(
            self::head_script( self::order_id() ),
            array(
                'id'               => self::SCRIPT_ID,
                'data-no-optimize' => '1',
                'data-no-defer'    => '1',
                'data-cfasync'     => 'false',
            )
        );
    }

    /**
     * Whether this is a thank-you page: WooCommerce's order received page, or a funnel page whose URL carries an order key.
     *
     * @return bool
     */
    public static function is_order_page() {
        if ( ! function_exists( 'is_order_received_page' ) ) {
            return false;
        }

        if ( is_order_received_page() ) {
            return true;
        }

        // Accepted exception (#79): the pay for order form posts back to its URL and WooCommerce needs the key there, so it stays.
        if ( is_wc_endpoint_url( 'order-pay' ) ) {
            return false;
        }

        foreach ( $_GET as $value ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if ( is_string( $value ) && false !== stripos( $value, 'wc_order_' ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * The order ID of this thank-you page, 0 when the page doesn't say.
     *
     * @return int
     */
    public static function order_id() {
        $order_id = absint( get_query_var( 'order-received' ) );

        foreach ( array( 'order_id', 'order' ) as $param ) {
            if ( ! $order_id && isset( $_GET[ $param ] ) && is_string( $_GET[ $param ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                $order_id = absint( $_GET[ $param ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            }
        }

        // WooCommerce filter.
        return absint( apply_filters( 'woocommerce_thankyou_order_id', $order_id ) );
    }
}
