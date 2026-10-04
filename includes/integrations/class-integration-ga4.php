<?php

require_once WCCT_INCLUDES . '/class-ga4-data.php';

/**
 * Google Analytics 4 integration
 */
class WCCT_Integration_GA4 extends WCCT_Integration {

    /**
     * WooCommerce session key for add to cart events waiting for the next page view
     *
     * @var string
     */
    const SESSION_KEY = 'wcct_ga4_pending_add_to_cart';

    /**
     * Order meta key that marks the purchase event as sent
     *
     * @var string
     */
    const TRACKED_META_KEY = '_wcct_ga4_tracked';

    /**
     * Cart fragment key that carries add to cart data in AJAX responses
     *
     * @var string
     */
    const FRAGMENT_KEY = 'wcct_ga4_add_to_cart';

    /**
     * Items added to the cart during the current AJAX request
     *
     * @var array
     */
    private $ajax_added_items = array();

    /**
     * Constructor for WCCT_Integration_GA4 class
     */
    function __construct() {
        $this->id       = 'ga4';
        $this->name     = __( 'Google Analytics 4', 'woocommerce-conversion-tracking' );
        $this->enabled  = true;
        $this->supports = array();

        add_filter( 'wcct_save_integrations_settings', array( $this, 'sanitize_settings' ) );
        add_action( 'woocommerce_add_to_cart', array( $this, 'capture_add_to_cart' ), 10, 6 );
        add_filter( 'woocommerce_add_to_cart_fragments', array( $this, 'add_to_cart_fragment' ) );
        add_action( 'wp_footer', array( $this, 'print_footer_events' ), 30 );
    }

    /**
     * Get settings
     *
     * @return array
     */
    public function get_settings() {
        $help = sprintf(
            /* translators: 1: example measurement ID, 2: Google help link */
            __( 'Your GA4 Measurement ID, for example %1$s. Find it in Google Analytics under Admin &rarr; Data streams &rarr; your web stream. <a href="%2$s" target="_blank">Learn more</a>.', 'woocommerce-conversion-tracking' ),
            '<code>G-XXXXXXXXXX</code>',
            'https://support.google.com/analytics/answer/12270356'
        );

        $help .= '<br><br>' . __( '<strong>Avoid double counting:</strong> if this GA4 property is already a destination of the Google tag you use for Google Ads (Google tag settings &rarr; Destinations), page views can be counted twice. Remove that destination, or turn on "Ignore duplicate instances of on-page configuration" in your Google tag settings.', 'woocommerce-conversion-tracking' );

        if ( $this->is_enabled() && '' === $this->get_measurement_id() ) {
            $help .= '<br><br><strong>' . __( 'The saved Measurement ID is missing or invalid, so nothing is sent to Google Analytics. Only IDs that start with G- are accepted.', 'woocommerce-conversion-tracking' ) . '</strong>';
        }

        $settings = array(
            'id'     => array(
                'type'        => 'text',
                'name'        => 'measurement_id',
                'label'       => __( 'Measurement ID', 'woocommerce-conversion-tracking' ),
                'value'       => '',
                'placeholder' => 'G-XXXXXXXXXX',
                'help'        => $help,
            ),
            'events' => array(
                'type'    => 'multicheck',
                'name'    => 'events',
                'label'   => __( 'Events', 'woocommerce-conversion-tracking' ),
                'value'   => '',
                'options' => array(
                    'view_item'      => __( 'View Item', 'woocommerce-conversion-tracking' ),
                    'add_to_cart'    => __( 'Add to Cart', 'woocommerce-conversion-tracking' ),
                    'begin_checkout' => __( 'Begin Checkout', 'woocommerce-conversion-tracking' ),
                    'purchase'       => __( 'Purchase', 'woocommerce-conversion-tracking' ),
                ),
            ),
        );

        return apply_filters( 'wcct_settings_ga4', $settings );
    }

    /**
     * Validate the measurement ID before the settings are saved
     *
     * @param  array $settings
     *
     * @return array
     */
    public function sanitize_settings( $settings ) {
        if ( isset( $settings[ $this->id ][0] ) && is_array( $settings[ $this->id ][0] ) ) {
            $measurement_id = isset( $settings[ $this->id ][0]['measurement_id'] ) ? $settings[ $this->id ][0]['measurement_id'] : '';

            $settings[ $this->id ][0]['measurement_id'] = WCCT_GA4_Data::sanitize_measurement_id( $measurement_id );
        }

        return $settings;
    }

    /**
     * Enqueue script
     *
     * Prints the gtag.js loader only when the Google Ads integration has not
     * already printed one, so gtag.js loads once per page.
     *
     * @return void
     */
    public function enqueue_script() {
        if ( ! $this->is_tracking_page() ) {
            return;
        }

        $measurement_id = $this->get_measurement_id();
        $ads_loader     = $this->ads_loader_printed();
        $config         = array();
        $page_location  = $this->get_order_received_page_location();

        if ( '' !== $page_location ) {
            $config['page_location'] = $page_location;
        }

        if ( ! $ads_loader ) {
            ?>
        <script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo esc_attr( $measurement_id ); ?>"></script>
            <?php
        }
        ?>
        <script>
            window.dataLayer = window.dataLayer || [];
            window.wcctGtag = window.wcctGtag || function () { window.dataLayer.push(arguments); };
            <?php if ( ! $ads_loader ) { ?>
            wcctGtag('js', new Date());
            <?php } ?>
            (function () {
                var cleanUrl = function (url) {
                    try {
                        var hashPos = url.indexOf('#');
                        var hash = hashPos > -1 ? url.substring(hashPos) : '';
                        var base = hashPos > -1 ? url.substring(0, hashPos) : url;
                        var queryPos = base.indexOf('?');
                        if (queryPos == -1) return url;
                        var pairs = base.substring(queryPos + 1).split('&'), kept = [];
                        for (var i = 0; i < pairs.length; i++) {
                            if (decodeURIComponent(pairs[i].split('=')[0].replace(/\+/g, ' ')) != 'key') kept.push(pairs[i]);
                        }
                        return base.substring(0, queryPos) + (kept.length ? '?' + kept.join('&') : '') + hash;
                    } catch (e) {
                        return String(url).split('?')[0];
                    }
                };
                var config = <?php echo $this->encode( (object) $config ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
                if (document.referrer) {
                    var referrer = cleanUrl(document.referrer);
                    if (referrer != document.referrer) config.page_referrer = referrer;
                }
                wcctGtag('config', <?php echo $this->encode( $measurement_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>, config);
            })();
        </script>
        <?php
    }

    /**
     * Remember a product added to the cart
     *
     * AJAX adds are sent back in the cart fragments. Form posts are stored
     * in the session and sent on the next page view. Store API (block) adds
     * are not tracked yet.
     *
     * @param  string $cart_item_key
     * @param  int    $product_id
     * @param  int    $quantity
     * @param  int    $variation_id
     * @param  array  $variation
     * @param  array  $cart_item_data
     *
     * @return void
     */
    public function capture_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id = 0, $variation = array(), $cart_item_data = array() ) {
        if ( ! $this->is_tracking_event( 'add_to_cart' ) || WC()->is_rest_api_request() ) {
            return;
        }

        $product = wc_get_product( $variation_id ? $variation_id : $product_id );

        if ( ! $product ) {
            return;
        }

        $item = $this->get_product_item( $product, $quantity );

        if ( wp_doing_ajax() ) {
            $this->ajax_added_items[] = $item;
            return;
        }

        if ( WC()->session ) {
            $pending   = (array) WC()->session->get( self::SESSION_KEY, array() );
            $pending[] = $item;

            WC()->session->set( self::SESSION_KEY, $pending );
        }
    }

    /**
     * Add the items added in this AJAX request to the cart fragments
     *
     * @param  array $fragments
     *
     * @return array
     */
    public function add_to_cart_fragment( $fragments ) {
        if ( ! empty( $this->ajax_added_items ) && $this->is_tracking_event( 'add_to_cart' ) ) {
            $fragments[ self::FRAGMENT_KEY ] = $this->get_event_params( $this->ajax_added_items );
        }

        $this->ajax_added_items = array();

        return $fragments;
    }

    /**
     * Print the events for this page in the footer
     *
     * @return void
     */
    public function print_footer_events() {
        if ( ! $this->is_tracking_page() ) {
            return;
        }

        $events = array();

        $pending = $this->get_pending_add_to_cart_items();

        if ( $pending && $this->is_tracking_event( 'add_to_cart' ) ) {
            $events[] = array( 'add_to_cart', $this->get_event_params( $pending ) );
        }

        if ( is_product() && $this->is_tracking_event( 'view_item' ) ) {
            $product = wc_get_product( get_queried_object_id() );

            if ( $product ) {
                $events[] = array( 'view_item', $this->get_event_params( array( $this->get_product_item( $product, 1 ) ) ) );
            }
        }

        if ( is_checkout() && ! is_order_received_page() && ! is_wc_endpoint_url( 'order-pay' ) && $this->is_tracking_event( 'begin_checkout' ) ) {
            $params = $this->get_begin_checkout_params();

            if ( $params ) {
                $events[] = array( 'begin_checkout', $params );
            }
        }

        if ( is_order_received_page() && $this->is_tracking_event( 'purchase' ) ) {
            $params = $this->get_purchase_params();

            if ( $params ) {
                $events[] = array( 'purchase', $params );
            }
        }

        $listen_ajax = $this->is_tracking_event( 'add_to_cart' );

        if ( ! $events && ! $listen_ajax ) {
            return;
        }
        ?>
        <script>
            (function () {
                window.dataLayer = window.dataLayer || [];
                var gtag = window.wcctGtag || function () { window.dataLayer.push(arguments); };
                var events = <?php echo $this->encode( $events ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
                for (var i = 0; i < events.length; i++) {
                    gtag('event', events[i][0], events[i][1]);
                }
                <?php if ( $listen_ajax ) { ?>
                if (window.jQuery) {
                    window.jQuery(document.body).on('added_to_cart', function (e, fragments) {
                        if (fragments && fragments['<?php echo esc_js( self::FRAGMENT_KEY ); ?>']) {
                            gtag('event', 'add_to_cart', fragments['<?php echo esc_js( self::FRAGMENT_KEY ); ?>']);
                            delete fragments['<?php echo esc_js( self::FRAGMENT_KEY ); ?>'];
                        }
                    });
                }
                <?php } ?>
            })();
        </script>
        <?php
    }

    /**
     * Begin checkout parameters for the current cart
     *
     * @return array|false
     */
    private function get_begin_checkout_params() {
        if ( ! WC()->cart || WC()->cart->is_empty() ) {
            return false;
        }

        $decimals = wc_get_price_decimals();
        $items    = array();

        foreach ( WC()->cart->get_cart() as $cart_item ) {
            if ( empty( $cart_item['data'] ) || ! $cart_item['data'] instanceof WC_Product ) {
                continue;
            }

            $values  = WCCT_GA4_Data::line_item_values( $cart_item['line_subtotal'], $cart_item['line_total'], $cart_item['quantity'], $decimals );
            $items[] = $this->get_product_item( $cart_item['data'], $cart_item['quantity'], $values['price'], $values['discount'] );
        }

        if ( ! $items ) {
            return false;
        }

        return $this->get_event_params( $items, WC()->cart->get_applied_coupons() );
    }

    /**
     * Purchase parameters for the order on the order received page
     *
     * The order key must match, failed and cancelled orders are skipped, and
     * each order is sent once.
     *
     * @return array|false
     */
    private function get_purchase_params() {
        $order     = wc_get_order( absint( get_query_var( 'order-received' ) ) );
        $order_key = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized by wc_clean()

        if ( ! $order instanceof WC_Order || ! is_string( $order_key ) || '' === $order_key || ! hash_equals( $order->get_order_key(), $order_key ) ) {
            return false;
        }

        if ( ! WCCT_GA4_Data::is_trackable_status( $order->get_status() ) || $order->get_meta( self::TRACKED_META_KEY ) ) {
            return false;
        }

        $decimals = wc_get_price_decimals();
        $items    = array();

        foreach ( $order->get_items() as $order_item ) {
            if ( ! $order_item instanceof WC_Order_Item_Product ) {
                continue;
            }

            $values  = WCCT_GA4_Data::line_item_values( $order_item->get_subtotal(), $order_item->get_total(), $order_item->get_quantity(), $decimals );
            $product = $order_item->get_product();

            if ( $product ) {
                $items[] = $this->get_product_item( $product, $order_item->get_quantity(), $values['price'], $values['discount'] );
            } else {
                $item_id = $order_item->get_variation_id() ? $order_item->get_variation_id() : $order_item->get_product_id();
                $items[] = WCCT_GA4_Data::build_item(
                    array(
                        'id'   => apply_filters( 'wcct_ga4_item_id', (string) $item_id, false ),
                        'name' => $order_item->get_name(),
                    ),
                    $order_item->get_quantity(),
                    $values['price'],
                    $values['discount'],
                    $decimals
                );
            }
        }

        $order->update_meta_data( self::TRACKED_META_KEY, 1 );
        $order->save();

        return WCCT_GA4_Data::purchase_params(
            $this->get_measurement_id(),
            WCCT_GA4_Data::transaction_id( $order->get_order_number(), $order->get_id() ),
            $order->get_currency(),
            $items,
            $order->get_total_tax(),
            $order->get_shipping_total(),
            $order->get_coupon_codes(),
            $decimals
        );
    }

    /**
     * Read and clear add to cart items stored by a form post
     *
     * @return array
     */
    private function get_pending_add_to_cart_items() {
        if ( ! WC()->session ) {
            return array();
        }

        $pending = WC()->session->get( self::SESSION_KEY, array() );

        if ( $pending ) {
            WC()->session->set( self::SESSION_KEY, null );
        }

        return is_array( $pending ) ? $pending : array();
    }

    /**
     * Build a GA4 item for a product
     *
     * Variations use the variation ID, the same ID the Meta integration
     * sends for cart items.
     *
     * @param  WC_Product $product
     * @param  int        $quantity
     * @param  float|null $price    Unit price after discount, excluding tax
     * @param  float      $discount Unit discount
     *
     * @return array
     */
    private function get_product_item( $product, $quantity = 1, $price = null, $discount = 0 ) {
        $parent_id = $product->get_parent_id();
        $variant   = '';

        if ( $product->is_type( 'variation' ) ) {
            $variant = wc_get_formatted_variation( $product, true, false, false );
        }

        if ( null === $price ) {
            $price = wc_get_price_excluding_tax( $product );
        }

        return WCCT_GA4_Data::build_item(
            array(
                'id'         => apply_filters( 'wcct_ga4_item_id', (string) $product->get_id(), $product ),
                'name'       => $product->get_title(),
                'variant'    => $variant,
                'categories' => $this->get_category_path( $parent_id ? $parent_id : $product->get_id() ),
            ),
            $quantity,
            $price,
            $discount,
            wc_get_price_decimals()
        );
    }

    /**
     * Category names for a product, from the top level down
     *
     * Uses the product's first category and its parents.
     *
     * @param  int $product_id
     *
     * @return array
     */
    private function get_category_path( $product_id ) {
        $terms = get_the_terms( $product_id, 'product_cat' );

        if ( ! is_array( $terms ) || ! $terms ) {
            return array();
        }

        $term  = reset( $terms );
        $names = array();

        foreach ( array_reverse( get_ancestors( $term->term_id, 'product_cat', 'taxonomy' ) ) as $ancestor_id ) {
            $ancestor = get_term( $ancestor_id, 'product_cat' );

            if ( $ancestor && ! is_wp_error( $ancestor ) ) {
                $names[] = $ancestor->name;
            }
        }

        $names[] = $term->name;

        return $names;
    }

    /**
     * Event parameters for a list of items in the store currency
     *
     * @param  array $items
     * @param  array $coupons
     *
     * @return array
     */
    private function get_event_params( array $items, array $coupons = array() ) {
        return WCCT_GA4_Data::event_params( $this->get_measurement_id(), get_woocommerce_currency(), $items, wc_get_price_decimals(), $coupons );
    }

    /**
     * Page location without the order key, on the order received page
     *
     * @return string
     */
    private function get_order_received_page_location() {
        if ( ! is_order_received_page() || empty( $_SERVER['REQUEST_URI'] ) ) {
            return '';
        }

        $request_uri = wp_unslash( $_SERVER['REQUEST_URI'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $origin      = preg_replace( '#^(https?://[^/]+).*$#i', '$1', home_url() );

        return esc_url_raw( remove_query_arg( 'key', $origin . $request_uri ) );
    }

    /**
     * Whether the Google Ads integration printed the gtag.js loader on this page
     *
     * Mirrors the checks in WCCT_Integration_Google::enqueue_script(), which
     * runs before this integration.
     *
     * @return boolean
     */
    private function ads_loader_printed() {
        $integrations = wcct_init()->manager->get_integrations();

        if ( empty( $integrations['google'] ) || ! $integrations['google']->is_enabled() ) {
            return false;
        }

        $settings = $integrations['google']->get_integration_settings();

        return ! empty( $settings[0]['account_id'] );
    }

    /**
     * Saved measurement ID, or an empty string when missing or invalid
     *
     * @return string
     */
    private function get_measurement_id() {
        $settings = $this->get_integration_settings();

        return WCCT_GA4_Data::sanitize_measurement_id( isset( $settings[0]['measurement_id'] ) ? $settings[0]['measurement_id'] : '' );
    }

    /**
     * Whether GA4 should load on this page
     *
     * @return boolean
     */
    private function is_tracking_page() {
        if ( ! $this->is_enabled() || '' === $this->get_measurement_id() ) {
            return false;
        }

        return (bool) apply_filters( 'wcct_ga4_should_track', true, 'page' );
    }

    /**
     * Whether an event should be sent
     *
     * @param  string $event
     *
     * @return boolean
     */
    private function is_tracking_event( $event ) {
        if ( ! $this->is_tracking_page() || ! $this->event_enabled( $event ) ) {
            return false;
        }

        return (bool) apply_filters( 'wcct_ga4_should_track', true, $event );
    }

    /**
     * Encode data for an inline script
     *
     * @param  mixed $data
     *
     * @return string
     */
    private function encode( $data ) {
        return wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
    }
}

return new WCCT_Integration_GA4();
