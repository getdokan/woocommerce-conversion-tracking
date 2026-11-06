<?php

/**
 * WCCT gate way facebook
 */
class WCCT_Integration_Facebook extends WCCT_Integration {

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
                'help'      => sprintf( __( 'Find the Pixel ID from <a href="%s" target="_blank">here</a>.', 'woocommerce-conversion-tracking' ), 'https://www.facebook.com/ads/manager/pixel/facebook_pixel' )
            ),
            'events'    => array(
                'type'    => 'multicheck',
                'name'    => 'events',
                'label'   => __( 'Events', 'woocommerce-conversion-tracking' ),
                'value'   => '',
                'options' => array(
                    'AddToCart'        => __( 'Add to Cart', 'woocommerce-conversion-tracking' ),
                    'InitiateCheckout' => __( 'Initiate Checkout', 'woocommerce-conversion-tracking' ),
                    'Purchase'         => __( 'Purchase', 'woocommerce-conversion-tracking' ),
                    'Registration'     => __( 'Complete Registration', 'woocommerce-conversion-tracking' )
                )
            ),
        );

        return apply_filters( 'wcct_settings_fb', $settings );
    }

    /**
     * Build the event object
     *
     * @param  string $event_name
     * @param  array $params
     * @param  string $method
     *
     * @return string
     */
    public function build_event( $event_name, $params = array(), $method = 'track' ) {
        return sprintf( "fbq('%s', '%s', %s);", $method, $event_name, json_encode( $params, JSON_PRETTY_PRINT | JSON_FORCE_OBJECT ) );
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

        $integration_settins    = $this->get_integration_settings();
        $facebook_pixel_id      = ! empty( $integration_settins[0]['pixel_id'] ) ? $integration_settins[0]['pixel_id'] : '';
        ?>
        <script>
            !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
            n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
            n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
            t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,
            document,'script','https://connect.facebook.net/en_US/fbevents.js');

            <?php
            if ( is_user_logged_in() ) {
                $user_email = wp_get_current_user()->user_email;

                echo $this->build_event( $facebook_pixel_id, array( 'em' => $user_email ), 'init' );
            } else {
                echo $this->build_event( $facebook_pixel_id, array(), 'init' );
            }

            echo $this->build_event( 'PageView', array() );
            ?>
        </script>
        <?php

        $this->print_event_script();
    }

    /**
     * Enqueue add to cart event
     *
     * @return string|void JavaScript for the pipeline
     */
    public function add_to_cart() {

        if ( ! $this->event_enabled( 'AddToCart' ) ) {
            return;
        }

        $event = $this->current_event;

        // The product just added; the whole cart only when the event has no product.
        if ( $event && $event->get_product() ) {
            $product_ids = array( \StorePulse\ConversionTracking\Events\ProductId::for_product( $event->get_product(), $this->id ) );
            $value       = wc_format_decimal( $event->get_value(), wc_get_price_decimals() );
        } else {
            $product_ids = $this->get_content_ids_from_cart( WC()->cart->get_cart() );
            $value       = WC()->cart->total ? WC()->cart->total : 0;
        }

        $code = $this->build_event( 'AddToCart', array(
            'content_ids'  => json_encode( $product_ids ),
            'content_type' => 'product',
            'value'        => $value,
            'currency'     => get_woocommerce_currency()
        ) );

        return $code;
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
        if ( ! $this->event_enabled( 'InitiateCheckout' ) ) {
            return;
        }

        $product_ids = $this->get_content_ids_from_cart( WC()->cart->get_cart() );

        $code = $this->build_event( 'InitiateCheckout', array(
            'num_items'    => WC()->cart->get_cart_contents_count(),
            'content_ids'  => json_encode( $product_ids ),
            'content_type' => 'product',
            'value'        => WC()->cart->total ? WC()->cart->total : 0,
            'currency'     => get_woocommerce_currency()
        ) );

        return $code;
    }

    /**
     * Check Out
     *
     * @param  integer $order_id
     *
     * @return string|void JavaScript for the pipeline
     */
    public function checkout( $order_id ) {
        if ( ! $this->event_enabled( 'Purchase' ) ) {
            return;
        }

        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            return;
        }

        // Variations send their own ID, so the IDs are products, never groups.
        $product_ids = \StorePulse\ConversionTracking\Events\ProductId::for_order( $order, $this->id );

        $code = $this->build_event( 'Purchase', array(
            'content_ids'  => json_encode($product_ids),
            'content_type' => 'product',
            'value'        => $this->purchase_value( $order ),
            'currency'     => $order->get_currency()
        ) );

        return $code;
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
        if ( ! $this->event_enabled( 'Registration' ) ) {
            return;
        }

        $code = $this->build_event( 'CompleteRegistration' );
        return $code;
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

    public function print_event_script() {
        ?>
        <script>
            (function (window, document) {
                if (window.wcfbq) return;
                var cleanUrl = <?php echo \StorePulse\ConversionTracking\Privacy\OrderUrl::cleaner_js(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
                window.wcfbq = (function () {
                    if (arguments.length > 0) {
                        var pixelId, trackType, contentObj;

                        if (typeof arguments[0] == 'string') pixelId = arguments[0];
                        if (typeof arguments[1] == 'string') trackType = arguments[1];
                        if (typeof arguments[2] == 'object') contentObj = arguments[2];

                        var params = [];
                        if (typeof pixelId === 'string' && pixelId.replace(/\s+/gi, '') != '' &&
                        typeof trackType === 'string' && trackType.replace(/\s+/gi, '')) {
                            params.push('id=' + encodeURIComponent(pixelId));
                            switch (trackType) {
                                case 'PageView':
                                case 'ViewContent':
                                case 'Search':
                                case 'AddToCart':
                                case 'InitiateCheckout':
                                case 'AddPaymentInfo':
                                case 'Lead':
                                case 'CompleteRegistration':
                                case 'Purchase':
                                case 'AddToWishlist':
                                    params.push('ev=' + encodeURIComponent(trackType));
                                    break;
                                default:
                                    return;
                            }

                            params.push('dl=' + encodeURIComponent(cleanUrl(document.location.href)));
                            if (document.referrer) params.push('rl=' + encodeURIComponent(cleanUrl(document.referrer)));
                            params.push('if=false');
                            params.push('ts=' + new Date().getTime());

                            if (typeof contentObj == 'object') {
                                for (var u in contentObj) {
                                    if (typeof contentObj[u] == 'object' && contentObj[u] instanceof Array) {
                                        if (contentObj[u].length > 0) {
                                            for (var y = 0; y < contentObj[u].length; y++) { contentObj[u][y] = (contentObj[u][y] + '').replace(/^\s+|\s+$/gi, '').replace(/\s+/gi, ' ').replace(/,/gi, '§'); }
                                            params.push('cd[' + u + ']=' + encodeURIComponent(contentObj[u].join(',').replace(/^/gi, '[\'').replace(/$/gi, '\']').replace(/,/gi, '\',\'').replace(/§/gi, '\,')));
                                        }
                                    }
                                    else if (typeof contentObj[u] == 'string')
                                        params.push('cd[' + u + ']=' + encodeURIComponent(contentObj[u]));
                                }
                            }

                            params.push('v=' + encodeURIComponent('2.7.19'));

                            var imgId = new Date().getTime();
                            var img = document.createElement('img');
                            img.id = 'fb_' + imgId, img.src = 'https://www.facebook.com/tr/?' + params.join('&'), img.width = 1, img.height = 1, img.style = 'display:none;';
                            document.body.appendChild(img);
                            window.setTimeout(function () { var t = document.getElementById('fb_' + imgId); t.parentElement.removeChild(t); }, 1000);
                        }
                    }
                });
            })(window, document);
        </script>
        <?php
    }
}
