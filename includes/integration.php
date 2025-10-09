<?php

/**
 * Conversion tracking integration class
 *
 * @author Tareq Hasan
 */
class WeDevs_WC_Tracking_Integration extends WC_Integration {

    function __construct() {

        $this->id                 = 'wc_conv_tracking';
        $this->method_title       = __( 'Conversion Tracking Pixel', 'woocommerce-conversion-tracking' );
        $this->method_description = __( 'Various conversion tracking pixel integration like Facebook Ad, Google AdWords, etc. Insert your scripts codes here:', 'woocommerce-conversion-tracking' );

        // Load the settings.
        $this->init_form_fields();
        $this->init_settings();

        // Save settings if the we are in the right section
        if ( isset( $_POST['section'] ) && $this->id === $_POST['section'] ) {
            add_action( 'woocommerce_update_options_integration', array( $this, 'process_admin_options' ) );
        }

        add_action( 'woocommerce_product_options_reviews', array( $this, 'product_options' ) );
        add_action( 'woocommerce_process_product_meta', array( $this, 'product_options_save' ), 10, 2 );

        add_action( 'woocommerce_registration_redirect', array( $this, 'wc_redirect_url' ) );
        add_action( 'template_redirect', array( $this, 'track_registration' ) );
        add_action( 'wp_head', array( $this, 'code_handler' ) );
        add_action( 'wp_footer', array( $this, 'code_handler' ) );
        add_action( 'woocommerce_thankyou', array( $this, 'thankyou_page' ) );
    }

    /**
     * WooCommerce settings API fields for storing our codes
     *
     * @return void
     */
    function init_form_fields() {
        $this->form_fields = array(
            'position' => array(
                'title'       => __( 'Tags Position', 'woocommerce-conversion-tracking' ),
                'description' => __( 'Select which position in your page you want to insert the tag', 'woocommerce-conversion-tracking' ),
                'desc_tip'    => true,
                'id'          => 'position',
                'type'        => 'select',
                'options'     => array(
                    'head'    => /* translators: %s: tag name */
                                 sprintf( __( 'Inside %s tag', 'woocommerce-conversion-tracking' ), 'head' ),
                    'footer'  => /* translators: %s: tag name */
                                 sprintf( __( 'Inside %s tag', 'woocommerce-conversion-tracking' ), 'body' )
                )
            ),
            'cart' => array(
                'title'       => sprintf( /* translators: %s: page name */
                                   __( 'Tags for %s', 'woocommerce-conversion-tracking' ),
                    __( 'View Cart', 'woocommerce-conversion-tracking' )
                ),
                'description' => __( 'Adds script on the cart page', 'woocommerce-conversion-tracking' ),
                'desc_tip'    => true,
                'id'          => 'cart',
                'type'        => 'textarea',
            ),
            'checkout' => array(
                'title'       => sprintf( /* translators: %s: page name */
                                   __( 'Tags for %s', 'woocommerce-conversion-tracking' ),
                    __( 'Successful Order', 'woocommerce-conversion-tracking' )
                ),
                'desc_tip'    => __( 'Adds script on the purchase success page', 'woocommerce-conversion-tracking' ),
                'description' => sprintf( /* translators: %s: dynamic values */
                                   __( 'You can use dynamic values: %s', 'woocommerce-conversion-tracking' ),
                    '<code>{customer_id}</code>, <code>{customer_email}</code>, <code>{customer_first_name}</code>, <code>{customer_last_name}</code>, <code>{order_number}</code>, <code>{order_total}</code>, <code>{order_subtotal}</code>, <code>{currency}</code>, <code>{payment_method}</code>'
                ),
                'id'          => 'checkout',
                'type'        => 'textarea',
            ),
            'reg' => array(
                'title'       => sprintf( /* translators: %s: page name */
                                   __( 'Tags for %s', 'woocommerce-conversion-tracking' ),
                    __( 'User Registration', 'woocommerce-conversion-tracking' )
                ),
                'description' => __( 'Adds script on the successful registraion page', 'woocommerce-conversion-tracking' ),
                'desc_tip'    => true,
                'id'          => 'registration',
                'type'        => 'textarea',
            ),
        );
    }

    /**
     * Save callback of Woo integration code settings API
     *
     * @param string $key
     * @return string
     */
    function validate_textarea_field( $key, $value ) {
        $field = isset( $_POST[ $this->plugin_id . $this->id . '_' . $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $this->plugin_id . $this->id . '_' . $key ] ) ) : '';

        $text = trim( stripslashes( $field ) );

        return $text;
    }

    /**
     * Saves tracking code of single product
     *
     * @uses woocommerce_process_product_meta
     * @param int $post_id
     */
    function product_options_save( $post_id ) {
        if ( isset( $_POST['_wc_conv_track'] ) ) {
            $value = trim( sanitize_text_field( wp_unslash( $_POST['_wc_conv_track'] ) ) );

	        $product = wc_get_product( $post_id );
	        if ( $product ) {
		        $product->update_meta_data( '_wc_conv_track', $value );
		        $product->save();
	        }
        }
    }

    /**
     * Textarea input box on product page
     *
     * Creates new textarea on WooCommerce product advanced tab for storing
     * conversion tracking pixel for single product
     *
     * @uses woocommerce_product_options_reviews
     */
    function product_options() {

        echo '<div class="options_group">';

        woocommerce_wp_textarea_input(
            array(
				'id'          => '_wc_conv_track',
				'label'       => __( 'Conversion Tracking Code', 'woocommerce-conversion-tracking' ),
				'desc_tip'    => true,
				'description' => __( 'Insert conversion tracking code for this product.', 'woocommerce-conversion-tracking' )
								 . sprintf(
									 /* translators: %s: dynamic values */
									 __( 'You can use dynamic values: %s', 'woocommerce-conversion-tracking' ),
									 '<code>{customer_id}</code>, <code>{customer_email}</code>, <code>{customer_first_name}</code>, <code>{customer_last_name}</code>, <code>{order_number}</code>, <code>{order_total}</code>, <code>{order_subtotal}</code>, <code>{currency}</code>, <code>{payment_method}</code>'
								 )
            )
        );

        echo '</div>';
    }

    /**
     * Print registration code when particular GET tag exists
     *
     * @uses add_action()
     * @return void
     */
    function track_registration() {
        $wc_user_reg = isset( $_GET['_wc_user_reg'] ) ? sanitize_text_field( wp_unslash( $_GET['_wc_user_reg'] ) ) : '';

        if ( $wc_user_reg == 'true' ) {
            add_action( 'wp_head', array( $this, 'print_reg_code' ) );
        }
    }

    /**
     * Adds a url query arg to determine newly registered user
     *
     * @uses woocommerce_registration_redirect action
     * @param string $redirect
     * @return string
     */
    function wc_redirect_url( $redirect ) {
        if ( wp_get_referer() ) {
            $redirect = add_query_arg(
                array(
					'_wc_user_reg' => 'true'
                ), esc_url( wp_get_referer() )
            );
        } else {
            $redirect = add_query_arg(
                array(
					'_wc_user_reg' => 'true'
                ), esc_url( get_permalink( wc_get_page_id( 'myaccount' ) ) )
            );
        }

        return $redirect;
    }

    /**
     * Code print handler on HEAD tag
     *
     * It prints conversion tracking pixels on cart page, order received page
     * and single product page
     *
     * @uses wp_head
     * @return void
     */
    function code_handler() {

        $position = $this->get_option( 'position', 'head' );

        if ( 'wp_head' == current_filter() && $position != 'head' ) {
            return;
        } elseif ( 'wp_footer' == current_filter() && $position != 'footer' ) {
            return;
        }

        if ( is_cart() ) {

            echo $this->print_conversion_code( $this->get_option( 'cart' ) );

        } elseif ( is_order_received_page() ) {

            echo $this->print_conversion_code( $this->process_order_markdown( $this->get_option( 'checkout' ) ) );
        }
    }

    /**
     * Put product specific conversion tracking pixel in thank you page
     *
     * @param  int $order_id
     *
     * @since 0.3
     *
     * @return void
     */
    public function thankyou_page( $order_id ) {
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            return;
        }

        /**
         * @var WC_Order_Item_Product $item
         */
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();

            if ( ! $product ) {
                continue;
            }

            $code = $this->get_product_conversion_code( $product->get_id() );

            if ( empty( $code ) ) {
                continue;
            }

            echo $this->print_conversion_code( $this->process_product_markdown( $code, $product ) );
        }
    }

    /**
     * Get product conversion code
     *
     * @param  int $product_id
     *
     * @return string
     */
    private function get_product_conversion_code( $product_id ) {
        $product = wc_get_product( $product_id );

        if ( ! $product ) {
            return '';
        }

	    return $product->get_meta( '_wc_conv_track' );
    }


    /**
     * Registration code print handler
     *
     * @return void
     */
    function print_reg_code() {
        echo $this->print_conversion_code( $this->get_option( 'reg' ) );
    }

    /**
     * Prints the code
     *
     * @param string $code
     *
     * @return string
     */
    function print_conversion_code( $code ) {
        if ( $code == '' ) {
            return '';
        }

        ob_start();

        echo "<!-- Tracking pixel by Conversion Tracking for WooCommerce plugin by Tareq Hasan -->\n";
        echo $code;
        echo "\n<!-- Tracking pixel by Conversion Tracking for WooCommerce plugin -->\n";

        return ob_get_clean();
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

        if ( version_compare( WC()->version, '3.0', '<=' ) ) {
            $order_currency = $order->get_order_currency();
            $payment_method = $order->payment_method;

        } else {
            $order_currency = $order->get_currency();
            $payment_method = $order->get_payment_method();
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

    /**
     * Filter the code for dynamic data for products
     *
     * @since 1.1
     *
     * @param  string $code
     *
     * @return string
     */
    function process_product_markdown( $code, $product ) {
        $price               = $product->get_price();
        $sale_price          = $product->get_sale_price();
        $regular_price       = $product->get_regular_price();
        $price_excluding_tax = $product->get_price_excluding_tax();
        $price_including_tax = $product->get_price_including_tax();

        $code                = str_replace( '{product_name}', $product->get_title(), $code );
        $code                = str_replace( '{price}', $price, $code );
        $code                = str_replace( '{sale_price}', $sale_price, $code );
        $code                = str_replace( '{regular_price}', $regular_price, $code );
        $code                = str_replace( '{price_including_tax}', $price_including_tax, $code );
        $code                = str_replace( '{price_excluding_tax}', $price_excluding_tax, $code );

        return $code;
    }

}
