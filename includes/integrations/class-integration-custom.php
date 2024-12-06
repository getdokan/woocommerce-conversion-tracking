<?php

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
     * Check Out
     *
     * @return void
     */
    public function checkout( $order_id ) {
        if ( ! $this->is_enabled() ) {
            return;
        }

        $code = $this->get_integration_settings();

        if ( isset( $code['checkout'] ) && ! empty( $code['checkout'] ) ) {
            echo $this->process_order_markdown( $code['checkout'], $order_id );
        }
    }

    /**
     * Registration
     *
     * @return void
     */
    public function registration() {
        if ( $this->is_enabled() ) {

            $code = $this->get_integration_settings();

            if ( isset( $code['registration'] ) && ! empty( $code['registration'] ) ) {
                echo $code['registration'];
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
            return $code;
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

        $customer       = $order->get_user();
        $used_coupons   = implode( ',', $used_coupons );
        $order_total    = $order->get_total();
        $order_number   = $order->get_order_number();
        $order_subtotal = $order->get_subtotal();
	    $order_discount = $order->get_total_discount();


        // customer details
        if ( $customer ) {
            $code = str_replace( '{customer_id}', $customer->ID, $code );
            $code = str_replace( '{customer_email}', $customer->user_email, $code );
            $code = str_replace( '{customer_first_name}', $customer->first_name, $code );
            $code = str_replace( '{customer_last_name}', $customer->last_name, $code );
        }

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

return new WCCT_Integration_Custom();
