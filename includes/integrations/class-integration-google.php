<?php

/**
 * WCCT gate way google
 */
class WCCT_Integration_Google extends WCCT_Integration {

    /**
     * Constructor for WC_Conversion_Tracking_Gateway_Google
     */
    function __construct() {
        $this->id           = 'adwords';
        $this->name         = 'Google Ads';
        $this->enabled      = true;
        $this->supports     = array(
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
            'id'  => array(
                'type'        => 'text',
                'name'        => 'account_id',
                'label'       => __( 'Account ID', 'woocommerce-conversion-tracking' ),
                'value'       => '',
                'placeholder' => 'AW-123456789',
                'help'        => sprintf( __( 'Provide the Google Ads Account ID. Usually it\'s something like <code>AW-123456789</code>, <a href="%s" target="_blank">learn more</a>.', 'woocommerce-conversion-tracking' ), 'https://wedevs.com/docs/woocommerce-conversion-tracking/google-adwords/account-id/?utm_source=wp-admin&utm_medium=inline-help&utm_campaign=wcct_docs&utm_content=adwords_learn_more' )
            ),
            'events'    => array(
                'type'    => 'multicheck',
                'name'    => 'events',
                'label'   => __( 'Events', 'woocommerce-conversion-tracking' ),
                'value'   => '',
                'options' => array(
                    'Purchase'  => array(
                        'event_label_box'   => true,
                        'label'             => __( 'Purchase', 'woocommerce-conversion-tracking' ),
                        'label_name'       => 'Purchase-label',
                        'placeholder'      => 'Add Your Purchase Label'
                    ),
                )
            ),
        );

        return apply_filters( 'wcct_settings_adwords', $settings );
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
    public function build_event( $event_name, $params = array(), $method = 'event' ) {
        return sprintf( "gtag('%s', '%s', %s);", $method, $event_name, json_encode( $params, JSON_PRETTY_PRINT | JSON_FORCE_OBJECT | JSON_UNESCAPED_SLASHES ) );
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

        $settings   = $this->get_integration_settings();
        $account_id = ! empty( $settings[0]['account_id'] ) ? $settings[0]['account_id'] : '';

        if ( empty( $account_id ) ) {
            return;
        }
        ?>
        <script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo esc_attr( $account_id ); ?>"></script>
        <script>
            window.dataLayer = window.dataLayer || [];
            function gtag(){dataLayer.push(arguments)};
            gtag('js', new Date());

            gtag('config', '<?php echo esc_attr( $account_id ); ?>');
        </script>
        <?php
    }

    /**
     * Check Out google adwords
     *
     * @return void
     */
    public function checkout( $order_id ) {
        if ( ! $this->event_enabled( 'Purchase' ) ) {
            return;
        }

        $settings   = $this->get_integration_settings();
        $account_id = isset( $settings[0]['account_id'] ) ? $settings[0]['account_id'] : '';
        $label      = isset( $settings[0]['events']['Purchase-label'] ) ? $settings[0]['events']['Purchase-label'] : '';

        if ( empty( $account_id ) || empty( $label ) ) {
            return;
        }

        $order = wc_get_order( $order_id );

        $code = $this->build_event( 'conversion', array(
            'send_to'        => sprintf( "%s/%s", $account_id, $label ),
            'transaction_id' => $order_id,
            'value'          => $order->get_total() ? $order->get_total() : 0,
            'currency'       => get_woocommerce_currency()
        ) );

        wc_enqueue_js( $code );
    }
}

return new WCCT_Integration_Google();
