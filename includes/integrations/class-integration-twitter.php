<?php

use StorePulse\ConversionTracking\Events\Event;
use StorePulse\ConversionTracking\Platforms\XSettings;

/**
 * WCCT gate way twitter
 */
class WCCT_Integration_Twitter extends WCCT_Integration {

    /**
     * Constructor for WC_Conversion_Tracking_Gateway_Twitter class
     */
    function __construct() {
        $this->id       = 'twitter';
        $this->name     = __( 'X (formerly Twitter)', 'woocommerce-conversion-tracking' );
        $this->enabled  = true;
        $this->supports = array(
            'checkout',
        );
    }

    /**
     * Get settings
     *
     * @return array
     */
    public function get_settings() {
        $settings = array(
            'pixel_id'  => array(
                'type'        => 'text',
                'name'        => 'pixel_id',
                'label'       => __( 'Pixel ID', 'woocommerce-conversion-tracking' ),
                'value'       => '',
                'placeholder' => __( 'Letters and numbers, usually 5, like o8x2k', 'woocommerce-conversion-tracking' ),
                'help'        => __( 'The base tag on every page; it also sends the page views.', 'woocommerce-conversion-tracking' ),
            ),
            'events'    => array(
                'type'  => 'multicheck',
                'name'  => 'events',
                'label' => __( 'Events', 'woocommerce-conversion-tracking' ),
                'value' => '',
                'options' => array(
                    'Purchase'  => array(
                        'event_label_box'  => true,
                        'label'            => __( 'Purchase', 'woocommerce-conversion-tracking' ),
                        'label_name'       => 'purchase_event_id',
                        'placeholder'      => __( 'Purchase event ID, starts with tw-', 'woocommerce-conversion-tracking' ),
                        /* translators: %s: X Ads Manager URL */
                        'help'             => sprintf( __( 'Each event has its own ID in <a href="%s" target="_blank">X Ads Manager</a>, under Tools &rarr; Events Manager. The event stays off until it has an ID.', 'woocommerce-conversion-tracking' ), 'https://ads.x.com/' ),
                    ),
                )
            ),
        );

        return apply_filters( 'wcct_settings_twitter', $settings );
    }

    /**
     * Integration settings, with the 2.x pixel ID shown in the Pixel ID field
     *
     * @return array|false
     */
    public function get_integration_settings() {
        $settings = parent::get_integration_settings();

        if ( is_array( $settings ) && isset( $settings[0] ) && is_array( $settings[0] ) && empty( $settings[0]['pixel_id'] ) ) {
            $settings[0]['pixel_id'] = XSettings::from_v1( $settings )['pixel_id'];
        }

        return $settings;
    }

    /**
     * Settings in the 3.0 shape, see XSettings: wcct_settings_v3 once it exists, else the 2.x settings
     *
     * @return array
     */
    public function get_x_settings() {
        $values = $this->get_platform_values();

        return null !== $values ? XSettings::from_v3( $values ) : XSettings::from_v1( parent::get_integration_settings() );
    }

    /**
     * Build the event object
     *
     * @deprecated 3.0.0 X events need an event ID, see event_script().
     *
     * @param  string $event_name
     * @param  array $params
     * @param  string $method
     *
     * @return string
     */
    public function build_event( $event_name, $params = array(), $method = 'event' ) {
        _deprecated_function( __METHOD__, '3.0.0', 'WCCT_Integration_Twitter::event_script()' );

        return sprintf( "twq('%s', '%s', %s);", $method, $event_name, json_encode( $params, JSON_PRETTY_PRINT | JSON_FORCE_OBJECT ) );
    }

    /**
     * Print the base code: the uwt.js loader and twq('config'), which also sends the page view
     *
     * @return void
     */
    public function enqueue_script() {
        if ( ! $this->is_enabled() ) {
            return;
        }

        $pixel_id = $this->get_x_settings()['pixel_id'];

        if ( '' === $pixel_id ) {
            return;
        }

        $this->print_consent_script( $this->consent_loader_js() );
        ?>
        <script type="text/javascript">
            !function(e,t,n,s,u,a){e.twq||(s=e.twq=function(){s.exe?s.exe.apply(s,arguments):s.queue.push(arguments);},s.version='1.1',s.queue=[],u=t.createElement(n),u.async=!0,u.src='https://static.ads-twitter.com/uwt.js',a=t.getElementsByTagName(n)[0],a.parentNode.insertBefore(u,a))}(window,document,'script');
            twq('config', <?php echo self::js( $pixel_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>);
        </script>
        <?php
    }

    /**
     * The Purchase is taken once uwt.js has loaded and run the queue
     *
     * @return string
     */
    protected function purchase_sent_check() {
        return "window.twq && typeof window.twq.exe === 'function'";
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
     * With consent mode on: an X queue stub, so the base code doesn't load uwt.js, and uwt.js held until marketing consent
     *
     * @return string
     */
    protected function consent_loader_js() {
        if ( ! $this->consent_mode_on() ) {
            return '';
        }

        $js  = "!function(e){if(!e.twq){var s=e.twq=function(){s.exe?s.exe.apply(s,arguments):s.queue.push(arguments);};s.version='1.1';s.queue=[];}}(window);";
        $js .= $this->consent_script_js( 'https://static.ads-twitter.com/uwt.js' );

        return $js;
    }

    /**
     * Check Out
     *
     * @param  integer $order_id
     *
     * @return string|void JavaScript for the pipeline
     */
    public function checkout( $order_id ) {
        $event = $this->current_event;
        $order = $event && $event->get_order() ? $event->get_order() : wc_get_order( $order_id );

        if ( ! $order instanceof WC_Order ) {
            return;
        }

        return $this->event_script(
            Event::PURCHASE,
            array(
                // In this platform's value mode (#95).
                'value'         => (float) $this->purchase_value( $order ),
                'currency'      => Event::currency_code( $order->get_currency() ),
                'conversion_id' => (string) $order->get_order_number(),
                'contents'      => $this->order_contents( $order ),
            )
        );
    }

    /**
     * twq('event') for a pipeline event, or an empty string when its row is off (no pixel or no event ID)
     *
     * @param string $event  Pipeline event name.
     * @param array  $params Event parameters.
     *
     * @return string
     */
    protected function event_script( $event, array $params = array() ) {
        $settings = $this->get_x_settings();
        $event_id = XSettings::event_id( $settings, $event );

        if ( '' === $settings['pixel_id'] || '' === $event_id ) {
            return '';
        }

        return sprintf( "window.twq && twq('event', %s, %s);", self::js( $event_id ), self::js( (object) $params ) );
    }

    /**
     * Parameters for a cart event (add to cart, start checkout) from the event being rendered
     *
     * @return array
     */
    protected function cart_params() {
        $event  = $this->current_event;
        $params = array();

        if ( ! $event ) {
            return $params;
        }

        if ( $event->get_value() > 0 && '' !== $event->get_currency() ) {
            $params['value']    = $event->get_value();
            $params['currency'] = $event->get_currency();
        }

        $contents = array();

        foreach ( \StorePulse\ConversionTracking\Events\ProductId::for_event( $event, $this->id ) as $product_id ) {
            $contents[] = array( 'content_id' => (string) $product_id );
        }

        if ( $contents ) {
            $params['contents'] = $contents;
        }

        return $params;
    }

    /**
     * X contents for the order's products
     *
     * @param WC_Order $order Order.
     *
     * @return array
     */
    protected function order_contents( $order ) {
        $contents = array();
        $mode     = \StorePulse\ConversionTracking\Events\ProductId::mode_for( $this->id );

        foreach ( $order->get_items() as $item ) {
            if ( ! $item instanceof WC_Order_Item_Product ) {
                continue;
            }

            $contents[] = array(
                'content_id'    => (string) \StorePulse\ConversionTracking\Events\ProductId::for_order_item( $item, $mode ),
                'content_name'  => $item->get_name(),
                'content_price' => Event::amount( $order->get_item_total( $item, false, false ), wc_get_price_decimals() ),
                'num_items'     => (int) $item->get_quantity(),
            );
        }

        return $contents;
    }

    /**
     * A value as JavaScript, safe inside a script tag
     *
     * @param mixed $value Value.
     *
     * @return string
     */
    protected static function js( $value ) {
        return (string) wp_json_encode( $value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES );
    }
}
