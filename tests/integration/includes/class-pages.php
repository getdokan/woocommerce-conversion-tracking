<?php
// phpcs:disable

/**
 * The page requests every integration is checked on, against the store from setup/seed.php
 */
class WCCT_Test_Pages {

    /**
     * Cart held from earlier requests on the checkout pages: 2 x Beanie, 1 x Hoodie (Blue)
     *
     * @var array
     */
    const CART = array(
        array(
            'product_id' => 2001,
            'quantity'   => 2,
        ),
        array(
            'product_id'   => 2002,
            'variation_id' => 2003,
            'variation'    => array( 'attribute_color' => 'Blue' ),
            'quantity'     => 1,
        ),
    );

    /**
     * Page name => request: path, and optionally user_id, cart and options
     *
     * @return array
     */
    public static function all() {
        $classic_checkout = array( 'woocommerce_checkout_page_id' => 1004 );

        return array(
            'home'                               => array( 'path' => '/' ),
            'shop'                               => array( 'path' => '/shop/' ),
            'category'                           => array( 'path' => '/product-category/clothing/' ),
            'search'                             => array( 'path' => '/?s=cotton&post_type=product' ),
            'product-simple'                     => array( 'path' => '/product/beanie/' ),
            'product-variable'                   => array( 'path' => '/product/hoodie/' ),
            // Add to cart without AJAX, as a theme without AJAX buttons does it; the cart page renders in the same request.
            'cart-after-add-to-cart'             => array(
                'path' => '/cart/?add-to-cart=2001',
                'cart' => array(),
            ),
            'checkout-classic'                   => array(
                'path'    => '/classic-checkout/',
                'cart'    => self::CART,
                'options' => $classic_checkout,
            ),
            'checkout-block'                     => array(
                'path' => '/checkout/',
                'cart' => self::CART,
            ),
            'order-received-classic'             => array(
                'path'    => '/classic-checkout/order-received/3001/?key=wc_order_ctguest0001',
                'options' => $classic_checkout,
            ),
            'order-received-block'               => array( 'path' => '/checkout/order-received/3001/?key=wc_order_ctguest0001' ),
            'order-received-no-key'              => array( 'path' => '/checkout/order-received/3001/' ),
            // The customer's order with its key, but nobody logged in.
            'order-received-customer-logged-out' => array( 'path' => '/checkout/order-received/3002/?key=wc_order_ctcustomer01' ),
            'order-received-customer'            => array(
                'path'    => '/checkout/order-received/3002/?key=wc_order_ctcustomer01',
                'user_id' => 501,
            ),
            'my-account'                         => array( 'path' => '/my-account/' ),
            // Where WooCommerce sends a shopper who has just registered (see RegistrationRedirectTest).
            'registration'                       => array(
                'path'    => '/my-account/?_wc_user_reg=true',
                'user_id' => 501,
            ),
        );
    }

    /**
     * One page request
     *
     * @param string $page Page name.
     *
     * @return array
     */
    public static function get( $page ) {
        $pages = self::all();

        if ( ! isset( $pages[ $page ] ) ) {
            throw new InvalidArgumentException( "Unknown page $page" );
        }

        return $pages[ $page ];
    }
}
