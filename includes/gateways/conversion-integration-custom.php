<?php

/**
 * WCCT gate way custom
 */
class WCCT_Gateway_Custom extends WCCT_Integration {

    /**
     * Constructor for WC_Conversion_Tracking_Gateway_Custom class
     */
    function __construct() {
        $this->id           =   'custom';
        $this->name         =   'Custom';
        $this->enabled      =   true;
        $this->supports     =   array(
            'add_to_cart', 'checkout', 'registration'
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
                'type'  =>  'textarea',
                'name'  =>  'cart',
                'label' =>  'Cart Scripts',
                'value' =>  ''
            ),
            array(
                'type'  =>  'textarea',
                'name'  =>  'checkout',
                'label' =>  'Check Out Scripts',
                'value' =>  ''
            ),
            array(
                'type'  =>  'textarea',
                'name'  =>  'registration',
                'label' =>  'Registration Scripts',
                'value' =>  ''
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
     * Add to cart
     * @return  void
     */
    public function add_to_cart() {
        if ( $this->is_enabled() ) {
            $code = $this->get_integration_settings();
            if ( isset( $code['cart'] ) && !empty( $code['cart'] ) ) {
                echo  $code['cart'] ;
            }
        }
    }

    /**
     * Check Out
     *
     * @return void
     */
    public function checkout() {
        if ( $this->is_enabled() ) {
            $code = $this->get_integration_settings();
            if ( isset( $code['checkout'] ) && !empty( $code['checkout'] ) ) {
                echo $this->process_order_markdown( $code['checkout'] );
            }
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
            if ( isset( $code['registration'] ) && !empty( $code['registration'] ) ) {
                echo $code['registration'] ;
            }
        }
    }

    /**
     * Filter the code for dynamic data for order received page
     *
     * @since 1.1
     *
     * @param  string  $code
     *
     * @return string
     */
    function process_order_markdown( $code ) {
        global $wp;

        if ( ! is_order_received_page() ) {
            return $code;
        }

        $order = wc_get_order( $wp->query_vars['order-received'] );

        // bail out if not a valid instance
        if ( ! is_a( $order, 'WC_Order' ) ) {
            return $code;
        }

        if ( version_compare( WC()->version, '3.0', '<' ) ) {
            // older version
            $order_currency = $order->get_order_currency();
            $payment_method = $order->payment_method;

        } else {
            $order_currency = $order->get_currency();
            $payment_method = $order->get_payment_method();
        }

        $customer       = $order->get_user();
        $used_coupons   = $order->get_used_coupons() ? implode(',', $order->get_used_coupons() ) : '';
        $order_currency = $order_currency;
        $order_total    = $order->get_total();
        $order_number   = $order->get_order_number();
        $order_subtotal = $order->get_subtotal();

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

        return $code;
    }
}