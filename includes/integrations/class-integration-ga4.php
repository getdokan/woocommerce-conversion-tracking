<?php

require_once WCCT_INCLUDES . '/class-ga4-data.php';

use StorePulse\ConversionTracking\Events\Event;
use StorePulse\ConversionTracking\Events\EventFactory;
use StorePulse\ConversionTracking\Events\PurchaseConfirmation;
use StorePulse\ConversionTracking\Events\PurchaseGate;
use StorePulse\ConversionTracking\Events\SentFlags;
use StorePulse\ConversionTracking\Platforms\GoogleTag;

/**
 * Google Analytics 4 integration
 */
class WCCT_Integration_GA4 extends WCCT_Integration {

    /**
     * Old session key for add to cart events; the pipeline's pending events replace it
     *
     * @deprecated 3.0.0
     *
     * @var string
     */
    const SESSION_KEY = 'wcct_ga4_pending_add_to_cart';

    /**
     * Old cart fragment key for add to cart data; the pipeline's fragment replaces it
     *
     * @deprecated 3.0.0
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
     * GA4 event names => the google platform's event switches, when they differ
     *
     * @var array
     */
    const EVENT_SWITCHES = array(
        'view_item' => 'view_product',
    );

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
        $this->supports = array( 'add_to_cart' );

        // Google consent mode covers GA4; this is the category for wcct_consent_allows().
        $this->consent_category = 'statistics';

        add_filter( 'wcct_save_integrations_settings', array( $this, 'sanitize_settings' ) );
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
            '<code>G-XXXXXXXXXX</code> or <code>GT-XXXXXXX</code>',
            'https://support.google.com/analytics/answer/12270356'
        );

        $help .= '<br><br>' . __( '<strong>Avoid double counting:</strong> if this GA4 property is already a destination of the Google tag you use for Google Ads (Google tag settings &rarr; Destinations), page views can be counted twice. Remove that destination, or turn on "Ignore duplicate instances of on-page configuration" in your Google tag settings.', 'woocommerce-conversion-tracking' );

        if ( $this->is_enabled() && '' === $this->get_measurement_id() ) {
            $help .= '<br><br><strong>' . __( 'The saved Measurement ID is missing or invalid, so nothing is sent to Google Analytics. Only IDs that start with G- or GT- are accepted.', 'woocommerce-conversion-tracking' ) . '</strong>';
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
                    'search'         => __( 'Search', 'woocommerce-conversion-tracking' ),
                    'sign_up'        => __( 'Sign up', 'woocommerce-conversion-tracking' ),
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
                            __( 'Google Analytics 4: "%s" is not a valid Measurement ID. Use the ID that starts with G- or GT-, for example G-XXXXXXXXXX. Settings were not saved.', 'woocommerce-conversion-tracking' ),
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
     * Whether GA4 is on in the google platform settings (mode Analytics or Both with a measurement ID) and not paused
     *
     * @return boolean
     */
    public function is_active() {
        return ! empty( GoogleTag::settings( $this->id )['enabled'] ) && ! $this->is_paused();
    }

    /**
     * Whether a GA4 event is switched on in the google platform settings
     *
     * @param  string $event GA4 event name, or the platform event name.
     *
     * @return boolean
     */
    public function event_enabled( $event ) {
        $event = isset( self::EVENT_SWITCHES[ $event ] ) ? self::EVENT_SWITCHES[ $event ] : $event;

        return in_array( $event, GoogleTag::settings( $this->id )['events'], true );
    }

    /**
     * Config ID for the shared Google tag: the Google tag ID when set, else the measurement ID
     *
     * @return string[]
     */
    public function get_gtag_ids() {
        if ( ! $this->is_tracking_page() ) {
            return array();
        }

        $tag_id = GoogleTag::tag_id( GoogleTag::settings( $this->id )['tag_id'] ?? '' );

        return array( '' !== $tag_id ? $tag_id : $this->get_measurement_id() );
    }

    /**
     * Print the shared Google tag, see GoogleTag; order parameters are removed from the page location and referrer, see OrderUrl
     *
     * @return void
     */
    public function enqueue_script() {
        if ( $this->is_tracking_page() ) {
            $this->print_google_tag();
        }
    }

    /**
     * Add to cart event for the product the pipeline is sending
     *
     * @return string JavaScript for the pipeline
     */
    public function add_to_cart() {
        $event = $this->current_event;

        if ( ! $event || ! $event->get_product() instanceof WC_Product || ! $this->is_tracking_event( 'add_to_cart' ) ) {
            return '';
        }

        $params = $this->get_event_params( array( $this->get_product_item( $event->get_product(), $event->get( 'quantity', 1 ) ) ) );

        return '(window.wcctGtag || function () { (window.dataLayer = window.dataLayer || []).push(arguments); })(\'event\', \'add_to_cart\', ' . $this->encode( $params ) . ');';
    }

    /**
     * Remember a product added to the cart
     *
     * @deprecated 3.0.0 The pipeline queues add to cart, see add_to_cart().
     *
     * @return void
     */
    public function capture_add_to_cart() {
        _deprecated_function( __METHOD__, '3.0.0' );
    }

    /**
     * Add the adds from this AJAX request to the cart fragments
     *
     * @deprecated 3.0.0 The pipeline adds its own fragment.
     *
     * @param  array $fragments
     *
     * @return array
     */
    public function add_to_cart_fragment( $fragments ) {
        _deprecated_function( __METHOD__, '3.0.0' );

        return $fragments;
    }

    /**
     * Print the events for this page in the footer
     *
     * @deprecated 3.0.0 The pipeline prints the footer script, see get_footer_script().
     *
     * @return void
     */
    public function print_footer_events() {
        _deprecated_function( __METHOD__, '3.0.0', 'WCCT_Integration_GA4::get_footer_script()' );
    }

    /**
     * Footer script for the events on this page
     *
     * @param Event[] $page_events Events decided on page load.
     *
     * @return string
     */
    public function get_footer_script( array $page_events ) {
        if ( ! $this->is_tracking_page() ) {
            return '';
        }

        $events = array();

        foreach ( $page_events as $page_event ) {
            if ( Event::VIEW_PRODUCT === $page_event->get_name() && $page_event->get_product() && $this->event_allowed( 'view_item' ) ) {
                $events[] = array( 'view_item', $this->get_event_params( array( $this->get_product_item( $page_event->get_product(), 1 ) ) ) );
            }

            if ( Event::SEARCH === $page_event->get_name() && $this->event_allowed( 'search' ) ) {
                $params = $this->get_search_params( $page_event );

                if ( $params ) {
                    $events[] = array( 'search', $params );
                }
            }

            if ( Event::SIGN_UP === $page_event->get_name() && $this->event_allowed( 'sign_up' ) ) {
                $events[] = array(
                    'sign_up',
                    array(
                        'send_to' => $this->get_measurement_id(),
                        'method'  => 'WooCommerce',
                    ),
                );
            }

            if ( Event::BEGIN_CHECKOUT === $page_event->get_name() && $this->event_allowed( 'begin_checkout' ) ) {
                $params = $this->get_begin_checkout_params();

                if ( $params ) {
                    $events[] = array( 'begin_checkout', $params );
                }
            }

            if ( Event::PURCHASE === $page_event->get_name() && $this->event_allowed( 'purchase' ) ) {
                $order = $this->get_purchase_order( $page_event->get_order() );

                if ( $order ) {
                    $events[] = array(
                        'purchase',
                        $this->get_purchase_params( $order, $page_event ),
                        array(
                            'order_id' => $order->get_id(),
                            'key'      => $order->get_order_key(),
                        ),
                    );
                }
            }
        }

        if ( ! $events ) {
            return '';
        }

        ob_start();
        ?>
            (function () {
                window.dataLayer = window.dataLayer || [];
                var gtag = window.wcctGtag || function () { window.dataLayer.push(arguments); };
                var events = <?php echo $this->encode( $events ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
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
            })();
        <?php
        return trim( ob_get_clean() );
    }

    /**
     * Search parameters for a store (product) search with a search term
     *
     * @param Event $event Search event.
     *
     * @return array|false
     */
    private function get_search_params( $event ) {
        $term = $event->get( 'search_string', '' );

        if ( ! in_array( 'product', (array) $event->get( 'post_types', array() ), true ) || ! is_string( $term ) || '' === trim( $term ) ) {
            return false;
        }

        return WCCT_GA4_Data::search_params( $this->get_measurement_id(), $term );
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
     * The purchase event's order, unless its status is skipped or it was already sent (see PurchaseGate)
     *
     * @param  WC_Order|null $order Order the pipeline found on the order received page.
     *
     * @return WC_Order|false
     */
    private function get_purchase_order( $order ) {
        if ( ! $order instanceof WC_Order || ! PurchaseGate::should_send( $order, $this->id ) ) {
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

        SentFlags::mark_sent( $order, $this->id );

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
        return PurchaseConfirmation::order_by_key( $order_id, $order_key );
    }

    /**
     * Purchase parameters for an order; value is the item total (fees excluded), tax and shipping are sent on their own
     *
     * @param  WC_Order   $order
     * @param  Event|null $event The purchase event.
     *
     * @return array
     */
    private function get_purchase_params( $order, $event = null ) {
        $decimals = wc_get_price_decimals();
        $items    = array();
        $event    = $event instanceof Event ? $event : EventFactory::purchase( $order );
        $value    = $event->get_items_total_value( $this->id );

        foreach ( $order->get_items() as $order_item ) {
            if ( ! $order_item instanceof WC_Order_Item_Product ) {
                continue;
            }

            $values  = WCCT_GA4_Data::line_item_values( $order_item->get_subtotal(), $order_item->get_total(), $order_item->get_quantity(), $decimals );
            $product = $order_item->get_product();

            if ( $product ) {
                $items[] = $this->get_product_item( $product, $order_item->get_quantity(), $values['price'], $values['discount'] );
            } else {
                $item_id = \StorePulse\ConversionTracking\Events\ProductId::for_order_item( $order_item, \StorePulse\ConversionTracking\Events\ProductId::mode_for( $this->id ) );
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
     * Build a GA4 item for a product
     *
     * Variations use their own ID or SKU, per the Product ID sent setting,
     * the same ID the Meta integration sends.
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
                'id'         => apply_filters( 'wcct_ga4_item_id', (string) \StorePulse\ConversionTracking\Events\ProductId::for_product( $product, $this->id ), $product ),
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
            $settings = GoogleTag::settings( $this->id );

            $this->measurement_id = WCCT_GA4_Data::sanitize_measurement_id( isset( $settings['measurement_id'] ) ? $settings['measurement_id'] : '' );
        }

        return $this->measurement_id;
    }

    /**
     * Whether GA4 should load on this page
     *
     * @return boolean
     */
    private function is_tracking_page() {
        if ( ! $this->is_active() || '' === $this->get_measurement_id() ) {
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
