<?php

use StorePulse\ConversionTracking\Events\OrderReceived;
use StorePulse\ConversionTracking\Platforms\EnhancedConversions;
use StorePulse\ConversionTracking\Platforms\GoogleTag;
use StorePulse\ConversionTracking\Settings\Google;

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
     * 2.x event switches => the google platform's events
     *
     * @var array
     */
    const EVENT_SWITCHES = array(
        'Purchase'     => 'purchase',
        'Registration' => 'sign_up',
    );

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
        // Resilient when an optimiser moves or delays the head block, as GA4's footer script.
        return sprintf( "(window.wcctGtag || function () { (window.dataLayer = window.dataLayer || []).push(arguments); })('%s', '%s', %s);", $method, $event_name, wp_json_encode( $params, JSON_PRETTY_PRINT | JSON_FORCE_OBJECT | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG ) );
    }

    /**
     * The adwords settings from the google platform: { enabled, account_id, purchase_label, registration_label, enhanced_conversions, events }
     *
     * @return array
     */
    public function get_ads_settings() {
        return GoogleTag::settings( $this->id );
    }

    /**
     * Whether Ads has its IDs in wcct_settings_v3 once that exists (as is_active(), pause apart), else the 2.x switch
     *
     * @return boolean
     */
    public function is_enabled() {
        if ( null === $this->get_platform_values() ) {
            return parent::is_enabled();
        }

        $integrations = Google::integrations( GoogleTag::platform() );

        return ! empty( $integrations[ $this->id ]['enabled'] );
    }

    /**
     * Whether Ads is on in the google platform settings (mode Ads or Both with a conversion ID) and not paused
     *
     * @return boolean
     */
    public function is_active() {
        return ! empty( $this->get_ads_settings()['enabled'] ) && ! $this->is_paused();
    }

    /**
     * Whether an Ads event is switched on in the google platform settings
     *
     * @param  string $event 2.x switch (Purchase, Registration) or the platform event name.
     *
     * @return boolean
     */
    public function event_enabled( $event ) {
        $event = isset( self::EVENT_SWITCHES[ $event ] ) ? self::EVENT_SWITCHES[ $event ] : $event;

        return in_array( $event, $this->get_ads_settings()['events'], true );
    }

    /**
     * Config ID for the shared Google tag: the conversion ID
     *
     * @return string[]
     */
    public function get_gtag_ids() {
        $account_id = $this->is_active() ? trim( (string) $this->get_ads_settings()['account_id'] ) : '';

        return '' !== $account_id ? array( $account_id ) : array();
    }

    /**
     * The conversion ID's config allows enhanced conversions while they are on
     *
     * @return array
     */
    public function get_gtag_config() {
        $ids = $this->get_gtag_ids();

        return $ids && $this->enhanced_conversions_on() ? array( $ids[0] => array( 'allow_enhanced_conversions' => true ) ) : array();
    }

    /**
     * Print the shared Google tag, see GoogleTag
     *
     * @return void
     */
    public function enqueue_script() {
        if ( $this->get_gtag_ids() ) {
            $this->print_google_tag();
        }
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

        $settings   = $this->get_ads_settings();
        $account_id = isset( $settings['account_id'] ) ? $settings['account_id'] : '';
        $label      = isset( $settings['purchase_label'] ) ? $settings['purchase_label'] : '';

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
        $code = str_replace( '"' . self::CALLBACK_PLACEHOLDER . '"', $this->purchase_sent_callback( $order ), $code );

        $user_data = $this->user_data_script( $order );

        return '' !== $user_data ? $user_data . "\n" . $code : $code;
    }

    /**
     * Whether enhanced conversions are on; Pro's switch turns them on
     *
     * @return boolean
     */
    public function enhanced_conversions_on() {
        return false;
    }

    /**
     * The order received page's verified order (key, owner and email checks), or false
     *
     * @return \WC_Order|false
     */
    protected function verified_order() {
        return OrderReceived::get_order();
    }

    /**
     * Enhanced conversions: the verified order's hashed email and phone, set before the conversion with marketing consent
     *
     * @param \WC_Order $order Order.
     *
     * @return string JavaScript, empty when nothing is set
     */
    protected function user_data_script( $order ) {
        if ( ! $this->enhanced_conversions_on() ) {
            return '';
        }

        $user_data = EnhancedConversions::user_data( $order, $this->verified_order() );

        if ( ! $user_data ) {
            return '';
        }

        $js = EnhancedConversions::gate( $this->build_event( 'user_data', $user_data, 'set' ) );

        if ( '' !== $js ) {
            EnhancedConversions::skip_page_cache();
        }

        return $js;
    }

    /**
     * gtag.js has loaded once it has set up google_tag_data
     *
     * @return string
     */
    public function library_loaded_check() {
        return '!!window.google_tag_data';
    }
}
