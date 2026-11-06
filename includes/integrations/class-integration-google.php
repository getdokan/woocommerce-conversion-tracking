<?php

/**
 * WCCT gate way google
 */
class WCCT_Integration_Google extends WCCT_Integration {

    /**
     * Stands in for the event_callback function in the encoded parameters
     *
     * @var string
     */
    const CALLBACK_PLACEHOLDER = '__wcct_purchase_sent__';

    /**
     * Constructor for WC_Conversion_Tracking_Gateway_Google
     */
    function __construct() {
        $this->id           = 'adwords';
        $this->name         = __( 'Google Ads', 'woocommerce-conversion-tracking' );
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

        $loader_printed = $this->print_gtag_loader( $account_id );
        ?>
        <script>
            window.dataLayer = window.dataLayer || [];
            function gtag(){dataLayer.push(arguments)};
            <?php if ( $loader_printed ) { ?>
            gtag('js', new Date());
            <?php } ?>

            gtag('config', '<?php echo esc_attr( $account_id ); ?>');
        </script>
        <?php
    }

    /**
     * The conversion's event_callback confirms the Purchase
     *
     * @return false
     */
    protected function purchase_sent_check() {
        return false;
    }

    /**
     * Check Out google adwords
     *
     * @return string|void JavaScript for the pipeline
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

        if ( ! $order ) {
            return;
        }

        $code = $this->build_event( 'conversion', array(
            'send_to'        => sprintf( "%s/%s", $account_id, $label ),
            'transaction_id' => $order_id,
            'value'          => $this->purchase_value( $order ),
            'currency'       => $order->get_currency(),
            'event_callback' => self::CALLBACK_PLACEHOLDER,
            'event_timeout'  => 60000,
        ) );

        // gtag.js calls event_callback once it has sent the conversion; the flag is written then.
        return str_replace( '"' . self::CALLBACK_PLACEHOLDER . '"', $this->purchase_sent_callback( $order ), $code );
    }
}
