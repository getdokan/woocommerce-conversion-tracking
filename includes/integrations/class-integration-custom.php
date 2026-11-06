<?php

use StorePulse\ConversionTracking\Events\PurchaseConfirmation;
use StorePulse\ConversionTracking\Events\OrderReceived;
use StorePulse\ConversionTracking\Privacy\CustomerDetails;

/**
 * WCCT gate way custom
 */
class WCCT_Integration_Custom extends WCCT_Integration {

    /**
     * Order placeholders of the thank-you script.
     */
    const ORDER_PLACEHOLDERS = array( '{used_coupons}', '{payment_method}', '{currency}', '{order_total}', '{order_number}', '{order_subtotal}', '{order_discount}', '{order_shipping}' );

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
                    '<code>{customer_id}</code>, <code>{customer_email}</code>, <code>{customer_first_name}</code>, <code>{customer_last_name}</code>, <code>{order_number}</code>, <code>{order_total}</code>, <code>{order_subtotal}</code>, <code>{order_discount}</code>, <code>{order_shipping}</code>, <code>{currency}</code>, <code>{payment_method}</code>'
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
     * Enqueue script
     *
     * @return void
     */
    public function enqueue_script() {

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
     * @return string|void Merchant HTML for the pipeline
     */
    public function checkout( $order_id ) {
        if ( ! $this->is_enabled() ) {
            return;
        }

        $code = $this->get_integration_settings();

        if ( isset( $code['checkout'] ) && ! empty( $code['checkout'] ) ) {
            $html  = $this->process_order_markdown( $code['checkout'], $order_id );
            $order = wc_get_order( $order_id );

            // Confirms once the merchant's inline script has run; an async or external script may not have sent yet.
            if ( $order instanceof WC_Order ) {
                $html .= '<script data-no-optimize="1">' . PurchaseConfirmation::helper() . "\n" . PurchaseConfirmation::confirm_script( $this->id, $order ) . '</script>';
            }

            return $html;
        }
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
     * @return string|void Merchant HTML for the pipeline
     */
    public function registration() {
        if ( $this->is_enabled() ) {

            $code = $this->get_integration_settings();

            if ( isset( $code['registration'] ) && ! empty( $code['registration'] ) ) {
                return str_replace( CustomerDetails::PLACEHOLDERS, '', $code['registration'] );
            }
        }
    }

    /**
     * Filter the code for dynamic data for order received page
     *
     * @since 1.1
     *
     * @param  string $code
     *
     * @return string
     */
    function process_order_markdown( $code, $order_id ) {

        $order = wc_get_order( $order_id );

        // bail out if not a valid instance
        if ( ! is_a( $order, 'WC_Order' ) ) {
            return str_replace( array_merge( CustomerDetails::PLACEHOLDERS, self::ORDER_PLACEHOLDERS ), '', $code );
        }

        if ( version_compare( WC()->version, '3.0', '<=' ) ) {
            $order_currency = $order->get_order_currency();
            $payment_method = $order->payment_method;
            $order_shipping = $order->get_total_shipping();

        } else {
            $order_currency = $order->get_currency();
            $payment_method = $order->get_payment_method();
            $order_shipping = $order->get_shipping_total();
        }

        if ( version_compare( WC()->version, '3.7', '<=' ) ) {
            $used_coupons = $order->get_used_coupons();

        } else {
            $used_coupons = $order->get_coupon_codes();
        }

        $used_coupons   = implode( ',', $used_coupons );
        $order_total    = $order->get_total();
        $order_number   = $order->get_order_number();
        $order_subtotal = $order->get_subtotal();
	    $order_discount = $order->get_total_discount();


        // Customer details stay empty unless "Include customer details in scripts" allows them.
        $verified = CustomerDetails::is_enabled() ? OrderReceived::get_order() : false;
        $customer = CustomerDetails::values( $order, $verified );
        $code     = str_replace( array_keys( $customer ), array_values( $customer ), $code );

        // order details
        $code = str_replace( '{used_coupons}', $used_coupons, $code );
        $code = str_replace( '{payment_method}', $payment_method, $code );
        $code = str_replace( '{currency}', $order_currency, $code );
        $code = str_replace( '{order_total}', $order_total, $code );
        $code = str_replace( '{order_number}', $order_number, $code );
        $code = str_replace( '{order_subtotal}', $order_subtotal, $code );
	    $code = str_replace( '{order_discount}', $order_discount, $code );
	    $code = str_replace( '{order_shipping}', $order_shipping, $code );

        return $code;
    }
}
