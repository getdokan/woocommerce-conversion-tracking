<?php

use StorePulse\ConversionTracking\Events\PurchaseConfirmation;
use StorePulse\ConversionTracking\Events\OrderReceived;
use StorePulse\ConversionTracking\Platforms\CustomScript;
use StorePulse\ConversionTracking\Privacy\CustomerDetails;
use StorePulse\ConversionTracking\Settings\Google;

/**
 * WCCT gate way custom
 */
class WCCT_Integration_Custom extends WCCT_Integration {

    /**
     * Constructor for WC_Conversion_Tracking_Gateway_Custom class
     */
    function __construct() {
        $this->id           = 'custom';
        $this->name         = __( 'Custom', 'woocommerce-conversion-tracking' );
        $this->enabled      = true;
        $this->supports     = array(
            'checkout',
            'registration'
        );
    }

    /**
     * Get settings integration
     *
     * @return array
     */
    public function get_settings() {
        $settings = array(
            array(
                'type'  => 'textarea',
                'name'  => 'checkout',
                'label' => __( 'Successful Order', 'woocommerce-conversion-tracking' ),
                'value' => '',
                'help'  => sprintf( /* translators: %s: dynamic values */
                                   __( 'Put your JavaScript tracking scripts here. You can use dynamic values: %s', 'woocommerce-conversion-tracking' ),
                    '<code>{customer_id}</code>, <code>{customer_email}</code>, <code>{customer_first_name}</code>, <code>{customer_last_name}</code>, <code>{order_number}</code>, <code>{order_total}</code>, <code>{order_subtotal}</code>, <code>{order_discount}</code>, <code>{order_shipping}</code>, <code>{currency}</code>, <code>{payment_method}</code>, <code>{order_id}</code>, <code>{used_coupons}</code>'
                ),
            ),
            array(
                'type'  => 'textarea',
                'name'  => 'registration',
                'label' => __( 'Registration Scripts', 'woocommerce-conversion-tracking' ),
                'value' => '',
                'help'  => sprintf( __( '<a href="%s" target="_blank">Learn more</a> about setting up custom scripts.' ), 'https://wedevs.com/docs/woocommerce-conversion-tracking/custom/?utm_source=wp-admin&utm_medium=inline-helpk&utm_campaign=wcct_docs&utm_content=Custom' )
            )
        );

        return $settings;
    }

    /**
     * The "Every page, in the header" script, printed in the plugin's wp_head block
     *
     * @return void
     */
    public function enqueue_script() {
        // With consent mode on, the header script waits for marketing consent like the footer ones.
        $html = \StorePulse\ConversionTracking\Consent\ConsentMode::hold_markup( $this->get_placement_markup( CustomScript::HEADER ), $this->get_consent_category() );

        if ( '' !== $html ) {
            echo $html . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- merchant HTML, needs unfiltered_html to save.
        }
    }

    /**
     * Merchant scripts are HTML, so nothing goes in the inline footer script
     *
     * @param array $events Events decided on page load.
     *
     * @return string
     */
    public function get_footer_script( array $events ) {
        return '';
    }

    /**
     * Merchant HTML for this page's events, printed after the footer scripts
     *
     * @param array $events Events decided on page load.
     *
     * @return string
     */
    public function get_footer_markup( array $events ) {
        $html = '';

        foreach ( $events as $event ) {
            $html .= $this->get_event_script( $event );
        }

        // With consent mode on, the scripts wait for marketing consent.
        return \StorePulse\ConversionTracking\Consent\ConsentMode::hold_markup( $html, $this->get_consent_category() );
    }

    /**
     * Check Out
     *
     * @return string Merchant HTML for the pipeline
     */
    public function checkout( $order_id ) {
        $order = wc_get_order( $order_id );

        return $this->get_placement_markup( CustomScript::THANKYOU, $order instanceof WC_Order ? $order : null );
    }

    /**
     * The checkout markup carries its own confirmation, after the merchant's script
     *
     * @param string   $code  Purchase markup.
     * @param WC_Order $order Order.
     *
     * @return string
     */
    protected function with_purchase_confirmation( $code, $order ) {
        return $code;
    }

    /**
     * Registration
     *
     * @return string Merchant HTML for the pipeline
     */
    public function registration() {
        return $this->get_placement_markup( CustomScript::SIGNUP );
    }

    /**
     * Merchant HTML for one placement, with its placeholders filled; every Custom script is printed from here
     *
     * @param string        $placement CustomScript::THANKYOU, HEADER or SIGNUP.
     * @param WC_Order|null $order     Order of the thank-you placement.
     *
     * @return string
     */
    public function get_placement_markup( $placement, $order = null ) {
        $script = CustomScript::script( $this->get_script_settings(), (string) $placement );

        if ( '' === $script ) {
            return '';
        }

        // Order and customer placeholders fill only on the thank-you page; elsewhere they stay empty.
        if ( CustomScript::THANKYOU !== $placement || ! $order instanceof WC_Order ) {
            return CustomScript::fill( $script );
        }

        $html = $this->fill_for_order( $script, $order );

        // Confirms once the merchant's inline script has run; an async or external script may not have sent yet.
        return $html . '<script data-no-optimize="1">' . PurchaseConfirmation::helper() . "\n" . PurchaseConfirmation::confirm_script( $this->id, $order ) . '</script>';
    }

    /**
     * Each placement's switch and script, through the settings layer
     *
     * @return array placement => { enabled, script }
     */
    public function get_script_settings() {
        return CustomScript::current();
    }

    /**
     * On while any placement has a script
     *
     * @return boolean
     */
    public function is_enabled() {
        return CustomScript::has_script( $this->get_script_settings() );
    }

    /**
     * The settings page switch as stored, also with no script yet
     *
     * @return boolean
     */
    public function is_switched_on() {
        $settings = $this->get_integration_settings();

        return is_array( $settings ) && Google::enabled( $settings );
    }

    /**
     * Filter the code for dynamic data for order received page
     *
     * @since 1.1
     *
     * @param  string $code
     * @param  int    $order_id
     *
     * @return string
     */
    function process_order_markdown( $code, $order_id ) {
        $order = wc_get_order( $order_id );

        return is_a( $order, 'WC_Order' ) ? $this->fill_for_order( (string) $code, $order ) : CustomScript::fill( (string) $code );
    }

    /**
     * A script with the order's placeholders filled
     *
     * @param string   $script Merchant script.
     * @param WC_Order $order  Order.
     *
     * @return string
     */
    private function fill_for_order( $script, $order ) {
        // Customer details stay empty unless "Include customer details in scripts" allows them.
        $verified = CustomerDetails::is_enabled() ? OrderReceived::get_order() : false;

        return CustomScript::fill( $script, array_merge( CustomScript::order_values( $order ), CustomerDetails::values( $order, $verified ) ) );
    }
}
