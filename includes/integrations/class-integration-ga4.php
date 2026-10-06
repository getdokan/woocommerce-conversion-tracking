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
     * AJAX action the browser calls after the purchase event is sent
     *
     * @var string
     */
    const PURCHASE_SENT_ACTION = 'wcct_ga4_purchase_sent';

    /**
     * Adds made during the current AJAX request, as {id, params}
     *
     * @var array
     */
    private $ajax_added_items = array();

    /**
     * Saved measurement ID for this request, see get_measurement_id()
     *
     * @var string|null
     */
    private $measurement_id = null;

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
        add_action( 'wp_ajax_' . self::PURCHASE_SENT_ACTION, array( $this, 'mark_purchase_sent' ) );
        add_action( 'wp_ajax_nopriv_' . self::PURCHASE_SENT_ACTION, array( $this, 'mark_purchase_sent' ) );
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
     * When GA4 is enabled, an invalid ID stops the save with an error, so the
     * admin sees why instead of the field coming back empty. When GA4 is
     * disabled the text is kept as typed, so saving other integrations is
     * never blocked. The ID is validated again whenever it is read.
     *
     * @param  array $settings
     *
     * @return array
     */
    public function sanitize_settings( $settings ) {
        if ( isset( $settings[ $this->id ][0] ) && is_array( $settings[ $this->id ][0] ) ) {
            $raw_id         = isset( $settings[ $this->id ][0]['measurement_id'] ) ? $settings[ $this->id ][0]['measurement_id'] : '';
            $raw_id         = is_string( $raw_id ) ? $raw_id : '';
            $measurement_id = WCCT_GA4_Data::sanitize_measurement_id( $raw_id );

            if ( '' === $measurement_id && empty( $settings[ $this->id ]['enabled'] ) ) {
                $settings[ $this->id ][0]['measurement_id'] = sanitize_text_field( $raw_id );

                return $settings;
            }

            if ( '' === $measurement_id && '' !== trim( $raw_id ) ) {
                wp_send_json_error(
                    array(
                        'message' => sprintf(
                            /* translators: %s: the rejected measurement ID */
                            __( 'Google Analytics 4: "%s" is not a valid Measurement ID. Use the ID that starts with G-, for example G-XXXXXXXXXX. Settings were not saved.', 'woocommerce-conversion-tracking' ),
                            sanitize_text_field( wp_unslash( $raw_id ) )
                        ),
                    )
                );
            }

            $settings[ $this->id ][0]['measurement_id'] = $measurement_id;
        }

        return $settings;
    }

    /**
     * Enqueue script
     *
     * The gtag.js loader is shared with the other gtag integrations, see
     * WCCT_Integration::print_gtag_loader().
     *
     * The `key` query parameter (the order key on the order received and
     * order pay pages) is removed from the page location and referrer.
     *
     * @return void
     */
    public function enqueue_script() {
        if ( ! $this->is_tracking_page() ) {
            return;
        }

        $measurement_id = $this->get_measurement_id();
        $loader_printed = $this->print_gtag_loader( $measurement_id );
        ?>
        <script>
            window.dataLayer = window.dataLayer || [];
            window.wcctGtag = window.wcctGtag || function () { window.dataLayer.push(arguments); };
            <?php if ( $loader_printed ) { ?>
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
                var config = {};
                var pageLocation = cleanUrl(window.location.href);
                if (pageLocation != window.location.href) config.page_location = pageLocation;
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
     * Every add is stored in the session with an ID and sent on the next page
     * view. AJAX adds are also sent back in the cart fragments, and sent at
     * once when add-to-cart.js fires added_to_cart. The browser remembers the
     * IDs it sent that way and skips them on the next page view.
     *
     * So an add is sent once whether the page fires added_to_cart, redirects
     * (redirect to cart, buy now buttons) or uses another AJAX action or the
     * Store API.
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
        if ( ! $this->is_tracking_event( 'add_to_cart' ) ) {
            return;
        }

        $product = wc_get_product( $variation_id ? $variation_id : $product_id );

        if ( ! $product ) {
            return;
        }

        $entry = array(
            'id'     => wp_generate_uuid4(),
            'params' => $this->get_event_params( array( $this->get_product_item( $product, $quantity ) ) ),
        );

        if ( wp_doing_ajax() ) {
            $this->ajax_added_items[] = $entry;
        }

        if ( WC()->session ) {
            $pending   = (array) WC()->session->get( self::SESSION_KEY, array() );
            $pending[] = $entry;

            WC()->session->set( self::SESSION_KEY, $pending );
        }
    }

    /**
     * Add the adds from this AJAX request to the cart fragments
     *
     * @param  array $fragments
     *
     * @return array
     */
    public function add_to_cart_fragment( $fragments ) {
        if ( ! empty( $this->ajax_added_items ) && $this->is_tracking_event( 'add_to_cart' ) ) {
            $fragments[ self::FRAGMENT_KEY ] = $this->ajax_added_items;
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

        $events      = array();
        $listen_ajax = $this->event_allowed( 'add_to_cart' );
        $pending     = $this->get_pending_add_to_cart_items();

        if ( ! $listen_ajax ) {
            $pending = array();
        }

        if ( is_product() && $this->event_allowed( 'view_item' ) ) {
            $product = wc_get_product( get_queried_object_id() );

            if ( $product ) {
                $events[] = array( 'view_item', $this->get_event_params( array( $this->get_product_item( $product, 1 ) ) ) );
            }
        }

        if ( is_checkout() && ! is_order_received_page() && ! is_wc_endpoint_url( 'order-pay' ) && $this->event_allowed( 'begin_checkout' ) ) {
            $params = $this->get_begin_checkout_params();

            if ( $params ) {
                $events[] = array( 'begin_checkout', $params );
            }
        }

        if ( is_order_received_page() && $this->event_allowed( 'purchase' ) ) {
            $order = $this->get_purchase_order();

            if ( $order ) {
                $events[] = array(
                    'purchase',
                    $this->get_purchase_params( $order ),
                    array(
                        'order_id' => $order->get_id(),
                        'key'      => $order->get_order_key(),
                    ),
                );
            }
        }

        if ( ! $events && ! $pending && ! $listen_ajax ) {
            return;
        }
        ?>
        <script>
            (function () {
                window.dataLayer = window.dataLayer || [];
                var gtag = window.wcctGtag || function () { window.dataLayer.push(arguments); };
                var events = <?php echo $this->encode( $events ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
                var pending = <?php echo $this->encode( array_values( $pending ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
                // IDs of adds already sent from the AJAX response, skipped
                // when the same adds come back from the session.
                var sentKey = 'wcct_ga4_sent_add_to_cart';
                var readSent = function () {
                    try { return JSON.parse(window.sessionStorage.getItem(sentKey)) || []; } catch (e) { return []; }
                };
                var sendAdds = function (entries, remember) {
                    var sent = readSent();
                    for (var i = 0; i < entries.length; i++) {
                        if (!entries[i] || !entries[i].params || sent.indexOf(entries[i].id) > -1) continue;
                        gtag('event', 'add_to_cart', entries[i].params);
                        if (remember) sent.push(entries[i].id);
                    }
                    if (remember) {
                        try { window.sessionStorage.setItem(sentKey, JSON.stringify(sent.slice(-50))); } catch (e) {}
                    }
                };
                sendAdds(pending, false);
                // Tells the server the purchase was sent, so it is not sent again.
                // Runs only once gtag.js has handled the event, so a blocked or
                // abandoned page sends the purchase again on the next view.
                // gtag also runs the callback when event_timeout passes, even if
                // the hit failed, so the timeout is long enough that the callback
                // in practice comes from the hit being sent.
                var confirmSent = function (order) {
                    var done = false;
                    return function () {
                        if (done) return;
                        done = true;
                        var url = <?php echo $this->encode( admin_url( 'admin-ajax.php' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
                        var body = new FormData();
                        body.append('action', <?php echo $this->encode( self::PURCHASE_SENT_ACTION ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>);
                        body.append('order_id', order.order_id);
                        body.append('key', order.key);
                        if (navigator.sendBeacon && navigator.sendBeacon(url, body)) return;
                        var xhr = new XMLHttpRequest();
                        xhr.open('POST', url);
                        xhr.send(body);
                    };
                };
                for (var i = 0; i < events.length; i++) {
                    if (events[i][2]) {
                        events[i][1].event_callback = confirmSent(events[i][2]);
                        events[i][1].event_timeout = 60000;
                    }
                    gtag('event', events[i][0], events[i][1]);
                }
                <?php if ( $listen_ajax ) { ?>
                if (window.jQuery) {
                    window.jQuery(document.body).on('added_to_cart', function (e, fragments) {
                        if (fragments && fragments['<?php echo esc_js( self::FRAGMENT_KEY ); ?>']) {
                            sendAdds(fragments['<?php echo esc_js( self::FRAGMENT_KEY ); ?>'], true);
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
        $value    = 0;

        foreach ( WC()->cart->get_cart() as $cart_item ) {
            if ( empty( $cart_item['data'] ) || ! $cart_item['data'] instanceof WC_Product ) {
                continue;
            }

            $values  = WCCT_GA4_Data::line_item_values( $cart_item['line_subtotal'], $cart_item['line_total'], $cart_item['quantity'], $decimals );
            $items[] = $this->get_product_item( $cart_item['data'], $cart_item['quantity'], $values['price'], $values['discount'] );
            $value  += (float) $cart_item['line_total'];
        }

        if ( ! $items ) {
            return false;
        }

        return $this->get_event_params( $items, WC()->cart->get_applied_coupons(), $value );
    }

    /**
     * Order on the order received page that still needs a purchase event
     *
     * The order key must match, failed and cancelled orders are skipped, and
     * orders already sent are skipped.
     *
     * @return WC_Order|false
     */
    private function get_purchase_order() {
        $order = $this->get_order_by_key( absint( get_query_var( 'order-received' ) ), isset( $_GET['key'] ) ? wp_unslash( $_GET['key'] ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in get_order_by_key()

        if ( ! $order || ! WCCT_GA4_Data::is_trackable_status( $order->get_status() ) || $order->get_meta( self::TRACKED_META_KEY ) ) {
            return false;
        }

        return $order;
    }

    /**
     * Mark an order's purchase event as sent
     *
     * Called by the browser after gtag.js has handled the purchase event.
     * The order key is the credential, as on the order received page.
     *
     * @return void
     */
    public function mark_purchase_sent() {
        $order_id  = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $order_key = isset( $_POST['key'] ) ? wp_unslash( $_POST['key'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in get_order_by_key()
        $order     = $this->get_order_by_key( $order_id, $order_key );

        if ( ! $order ) {
            wp_send_json_error( null, 403 );
        }

        if ( ! $order->get_meta( self::TRACKED_META_KEY ) ) {
            $order->update_meta_data( self::TRACKED_META_KEY, 1 );
            $order->save_meta_data();
        }

        wp_send_json_success();
    }

    /**
     * Order for an ID, only when the order key matches
     *
     * @param  int   $order_id
     * @param  mixed $order_key Unsanitized key
     *
     * @return WC_Order|false
     */
    private function get_order_by_key( $order_id, $order_key ) {
        $order_key = is_string( $order_key ) ? wc_clean( $order_key ) : '';
        $order     = $order_id ? wc_get_order( $order_id ) : false;

        if ( ! $order instanceof WC_Order || '' === $order_key || ! hash_equals( $order->get_order_key(), $order_key ) ) {
            return false;
        }

        return $order;
    }

    /**
     * Purchase parameters for an order
     *
     * @param  WC_Order $order
     *
     * @return array
     */
    private function get_purchase_params( $order ) {
        $decimals = wc_get_price_decimals();
        $items    = array();
        $value    = 0;

        foreach ( $order->get_items() as $order_item ) {
            if ( ! $order_item instanceof WC_Order_Item_Product ) {
                continue;
            }

            $value  += (float) $order_item->get_total();
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

        return WCCT_GA4_Data::purchase_params(
            $this->get_measurement_id(),
            WCCT_GA4_Data::transaction_id( $order->get_order_number(), $order->get_id() ),
            $order->get_currency(),
            $items,
            $order->get_total_tax(),
            $order->get_shipping_total(),
            $order->get_coupon_codes(),
            $decimals,
            $value
        );
    }

    /**
     * Read and clear the adds stored in the session
     *
     * @return array List of {id, params}
     */
    private function get_pending_add_to_cart_items() {
        if ( ! WC()->session ) {
            return array();
        }

        $pending = WC()->session->get( self::SESSION_KEY, array() );

        if ( $pending ) {
            WC()->session->set( self::SESSION_KEY, null );
        }

        if ( ! is_array( $pending ) ) {
            return array();
        }

        return array_filter(
            $pending,
            function ( $entry ) {
                return is_array( $entry ) && isset( $entry['id'], $entry['params'] );
            }
        );
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
     * @param  float $value   Total from line totals, see WCCT_GA4_Data::event_params()
     *
     * @return array
     */
    private function get_event_params( array $items, array $coupons = array(), $value = null ) {
        return WCCT_GA4_Data::event_params( $this->get_measurement_id(), get_woocommerce_currency(), $items, wc_get_price_decimals(), $coupons, $value );
    }

    /**
     * Saved measurement ID, or an empty string when missing or invalid
     *
     * @return string
     */
    private function get_measurement_id() {
        if ( null === $this->measurement_id ) {
            $settings = $this->get_integration_settings();

            $this->measurement_id = WCCT_GA4_Data::sanitize_measurement_id( isset( $settings[0]['measurement_id'] ) ? $settings[0]['measurement_id'] : '' );
        }

        return $this->measurement_id;
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
        return $this->is_tracking_page() && $this->event_allowed( $event );
    }

    /**
     * Whether an event should be sent, once is_tracking_page() has passed
     *
     * @param  string $event
     *
     * @return boolean
     */
    private function event_allowed( $event ) {
        if ( ! $this->event_enabled( $event ) ) {
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
