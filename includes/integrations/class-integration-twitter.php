<?php

/**
 * WCCT gate way twitter
 */
class WCCT_Integration_Twitter extends WCCT_Integration {

    /**
     * Constructor for WC_Conversion_Tracking_Gateway_Twitter class
     */
    function __construct() {
        $this->id       = 'twitter';
        $this->name     = 'Twitter';
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

            'events'    => array(
                'type'  => 'multicheck',
                'name'  => 'events',
                'label' => __( 'Events', 'woocommerce-conversion-tracking' ),
                'value' => '',
                'options' => array(
                    'Purchase'  => array(
                        'event_label_box'  => true,
                        'label'            => __( 'Purchase', 'woocommerce-conversion-tracking' ),
                        'label_name'       => 'universal_tag_id',
                        'placeholder'      => 'Universal Tag ID',
                        'help'             => sprintf( __( 'Find the Universal Tag ID from <a href="%s" target="_blank">here</a>, navigate to Tools &rarr; Conversion Tracking.', 'woocommerce-conversion-tracking' ), 'https://ads.twitter.com/' )
                    ),
                )
            ),
        );

        return apply_filters( 'wcct_settings_twitter', $settings );
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
        return sprintf( "twq('%s', '%s', %s);", $method, $event_name, json_encode( $params, JSON_PRETTY_PRINT | JSON_FORCE_OBJECT ) );
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

        $integration_settins = $this->get_integration_settings();
        $universal_tag_id    = ! empty( $integration_settins[0]['events']['universal_tag_id'] ) ? $integration_settins[0]['events']['universal_tag_id'] : '';
        $advance_event       = ( isset( $integration_settins[0]['events']['AddToCart'] ) ||isset( $integration_settins[0]['events']['Registration'] ) ) ? true : false;
        ?>
        <script type="text/javascript">
            !function(e,t,n,s,u,a){e.twq||(s=e.twq=function(){s.exe?s.exe.apply(s,arguments):s.queue.push(arguments);},s.version='1.1',s.queue=[],u=t.createElement(n),u.async=!0,u.src='//static.ads-twitter.com/uwt.js',a=t.getElementsByTagName(n)[0],a.parentNode.insertBefore(u,a))}(window,document,'script');

            <?php echo $this->build_event( $universal_tag_id, array(), 'init' ); ?>
            <?php echo $this->build_event( 'PageView' ); ?>
        </script>

        <?php if ( $advance_event ): ?>
            <script src="//platform.twitter.com/oct.js" type="text/javascript"></script>
        <?php endif;?>
        <?php
    }

    /**
     * Check Out
     *
     * @param  integer $order_id
     *
     * @return void
     */
    public function checkout( $order_id ) {
        if ( ! $this->event_enabled( 'Purchase' ) ) {
            return;
        }

        $order        = wc_get_order( $order_id );
        $content_type = 'product';
        $product_ids  = array();

        foreach ( $order->get_items() as $item ) {
            $product = wc_get_product( $item['product_id'] );

            $product_ids[] = $product->get_id();

            if ( $product->get_type() === 'variable' ) {
                $content_type = 'product_group';
            }
        }

        $code = $this->build_event( 'Purchase', array(
            'content_ids'  => json_encode($product_ids),
            'content_type' => $content_type,
            'value'        => $order->get_total() ? $order->get_total() : 0,
            'currency'     => get_woocommerce_currency()
        ) );

        wc_enqueue_js( $code );
    }
}

return new WCCT_Integration_Twitter();
