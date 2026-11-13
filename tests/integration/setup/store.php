<?php
/**
 * Builds the store the fixtures were recorded against, except the orders (orders.php). Runs once per suite run, on a fresh install.
 *
 * Every ID is fixed, so fixtures never depend on auto-increments. Returns a summary for the bootstrap.
 */

// phpcs:disable

global $wpdb, $wcct_next_id;

// WooCommerce installs itself on init when its version option is missing.
if ( ! get_option( 'woocommerce_version' ) ) {
    WC_Install::install();
}

// Store basics: open to guests, US store in USD, no taxes.
$wcct_options = array(
    'blogname'                                  => 'CT Test Store',
    'permalink_structure'                       => '/%postname%/',
    'show_on_front'                             => 'posts',
    'woocommerce_coming_soon'                   => 'no',
    'woocommerce_store_pages_only'              => 'no',
    'woocommerce_default_country'               => 'US:CA',
    'woocommerce_currency'                      => 'USD',
    'woocommerce_price_num_decimals'            => '2',
    'woocommerce_calc_taxes'                    => 'no',
    'woocommerce_enable_guest_checkout'         => 'yes',
    'woocommerce_enable_myaccount_registration' => 'yes',
    'woocommerce_enable_ajax_add_to_cart'       => 'yes',
    'woocommerce_cart_redirect_after_add'       => 'no',
    'woocommerce_onboarding_profile'            => array( 'skipped' => true ),
);

foreach ( $wcct_options as $wcct_name => $wcct_value ) {
    update_option( $wcct_name, $wcct_value );
}

// The fixtures stand for a store upgraded from 2.x (its settings are 2.x settings), so 3.0 keeps its order values (D8).
update_option( 'wcct_install_type', 'upgrade' );

// Rules are rebuilt on each request (inside its rolled-back transaction) with the structure above.
delete_option( 'rewrite_rules' );

switch_theme( 'wcct-test' );

// Products, variations and coupons get the ID in $wcct_next_id.
$wcct_fixed_id = function ( $data ) {
    global $wcct_next_id;

    if ( $wcct_next_id ) {
        $data['import_id'] = $wcct_next_id;
    }

    return $data;
};
add_filter( 'woocommerce_new_product_data', $wcct_fixed_id );
add_filter( 'woocommerce_new_product_variation_data', $wcct_fixed_id );
add_filter( 'woocommerce_new_coupon_data', $wcct_fixed_id );

// Pages at fixed IDs. WooCommerce's own cart, checkout and account content is kept, so the blocks match the installed version.
$wcct_page_content = function ( $option, $fallback ) {
    $page = get_post( (int) get_option( $option ) );

    return $page ? $page->post_content : $fallback;
};
$wcct_pages        = array(
    1001 => array( 'Shop', 'shop', '', 'woocommerce_shop_page_id' ),
    1002 => array( 'Cart', 'cart', $wcct_page_content( 'woocommerce_cart_page_id', '<!-- wp:woocommerce/cart /-->' ), 'woocommerce_cart_page_id' ),
    1003 => array( 'Checkout', 'checkout', $wcct_page_content( 'woocommerce_checkout_page_id', '<!-- wp:woocommerce/checkout /-->' ), 'woocommerce_checkout_page_id' ),
    1004 => array( 'Classic checkout', 'classic-checkout', '<!-- wp:shortcode -->[woocommerce_checkout]<!-- /wp:shortcode -->', '' ),
    1005 => array( 'My account', 'my-account', $wcct_page_content( 'woocommerce_myaccount_page_id', '<!-- wp:shortcode -->[woocommerce_my_account]<!-- /wp:shortcode -->' ), 'woocommerce_myaccount_page_id' ),
);

foreach ( array( 'woocommerce_shop_page_id', 'woocommerce_cart_page_id', 'woocommerce_checkout_page_id', 'woocommerce_myaccount_page_id' ) as $wcct_option ) {
    if ( get_option( $wcct_option ) ) {
        wp_delete_post( (int) get_option( $wcct_option ), true );
    }
}

foreach ( $wcct_pages as $wcct_id => $wcct_page ) {
    wp_insert_post(
        array(
            'import_id'    => $wcct_id,
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_title'   => $wcct_page[0],
            'post_name'    => $wcct_page[1],
            'post_content' => $wcct_page[2],
        )
    );

    if ( $wcct_page[3] ) {
        update_option( $wcct_page[3], $wcct_id );
    }
}

// Categories: Clothing with Accessories and Hoodies under it.
$wcct_clothing    = wp_insert_term( 'Clothing', 'product_cat', array( 'slug' => 'clothing' ) );
$wcct_accessories = wp_insert_term( 'Accessories', 'product_cat', array( 'slug' => 'accessories', 'parent' => $wcct_clothing['term_id'] ) );
$wcct_hoodies     = wp_insert_term( 'Hoodies', 'product_cat', array( 'slug' => 'hoodies', 'parent' => $wcct_clothing['term_id'] ) );

$wcct_next_id = 2001;
$wcct_beanie  = new WC_Product_Simple();
$wcct_beanie->set_props(
    array(
        'name'          => 'Beanie',
        'date_created'  => '2026-01-01 10:00:00',
        'description'   => 'A soft cotton beanie.',
        'slug'          => 'beanie',
        'sku'           => 'CT-BEANIE',
        'regular_price' => '18.00',
        'status'        => 'publish',
        'category_ids'  => array( $wcct_accessories['term_id'] ),
    )
);
$wcct_beanie->save();

$wcct_next_id = 2002;
$wcct_color   = new WC_Product_Attribute();
$wcct_color->set_name( 'Color' );
$wcct_color->set_options( array( 'Blue', 'Red' ) );
$wcct_color->set_visible( true );
$wcct_color->set_variation( true );

$wcct_hoodie = new WC_Product_Variable();
$wcct_hoodie->set_props(
    array(
        'name'         => 'Hoodie',
        'date_created' => '2026-01-02 10:00:00',
        'description'  => 'A warm cotton hoodie.',
        'slug'         => 'hoodie',
        'sku'          => 'CT-HOODIE',
        'status'       => 'publish',
        'category_ids' => array( $wcct_hoodies['term_id'] ),
        'attributes'   => array( $wcct_color ),
    )
);
$wcct_hoodie->save();

foreach ( array( 2003 => array( 'Blue', '42.00' ), 2004 => array( 'Red', '45.00' ) ) as $wcct_id => $wcct_variation_data ) {
    $wcct_next_id   = $wcct_id;
    $wcct_variation = new WC_Product_Variation();
    $wcct_variation->set_props(
        array(
            'parent_id'     => 2002,
            'sku'           => 'CT-HOODIE-' . strtoupper( $wcct_variation_data[0] ),
            'regular_price' => $wcct_variation_data[1],
            'status'        => 'publish',
            'attributes'    => array( 'color' => $wcct_variation_data[0] ),
        )
    );
    $wcct_variation->save();
}

WC_Product_Variable::sync( 2002 );

$wcct_next_id = 2005;
$wcct_tshirt  = new WC_Product_Simple();
$wcct_tshirt->set_props(
    array(
        'name'          => 'T-Shirt',
        'date_created'  => '2026-01-03 10:00:00',
        'description'   => 'A plain cotton t-shirt.',
        'slug'          => 't-shirt',
        'sku'           => 'CT-TSHIRT',
        'regular_price' => '25.00',
        'status'        => 'publish',
        'category_ids'  => array( $wcct_clothing['term_id'] ),
    )
);
$wcct_tshirt->save();

$wcct_next_id = 2501;
$wcct_coupon  = new WC_Coupon();
$wcct_coupon->set_props(
    array(
        'code'          => 'ctsave5',
        'discount_type' => 'fixed_cart',
        'amount'        => '5',
    )
);
$wcct_coupon->save();

$wcct_next_id = 0;

// A registered customer with a billing address, user 501.
$wpdb->query( "ALTER TABLE {$wpdb->users} AUTO_INCREMENT = 501" );
$wcct_customer_id = wp_insert_user(
    array(
        'user_login' => 'ctcustomer',
        'user_email' => 'customer@example.com',
        'user_pass'  => 'ct-customer-pass',
        'first_name' => 'Sam',
        'last_name'  => 'Buyer',
        'role'       => 'customer',
    )
);
$wcct_customer    = new WC_Customer( $wcct_customer_id );
$wcct_customer->set_props(
    array(
        'billing_first_name' => 'Sam',
        'billing_last_name'  => 'Buyer',
        'billing_email'      => 'customer@example.com',
        'billing_phone'      => '555-0101',
        'billing_address_1'  => '2 Customer Ave',
        'billing_city'       => 'Oakland',
        'billing_state'      => 'CA',
        'billing_postcode'   => '94607',
        'billing_country'    => 'US',
    )
);
$wcct_customer->save();

// The customer signed up long ago: no sign-up waiting (the registration page sets the flag itself).
delete_user_meta( $wcct_customer_id, '_wcct_sign_up_pending' );

// Orders go to WooCommerce's order tables (HPOS), as on new stores; orders.php runs in a new process so the setting applies.
$wcct_sync = wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class );

if ( ! $wcct_sync->check_orders_table_exists() ) {
    $wcct_sync->create_database_tables();
}

update_option( 'woocommerce_custom_orders_table_enabled', 'yes' );
update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );

return array(
    'wordpress'   => get_bloginfo( 'version' ),
    'woocommerce' => WC()->version,
    'theme'       => get_stylesheet(),
    'pages'       => array_keys( $wcct_pages ),
    'products'    => array( $wcct_beanie->get_id(), $wcct_hoodie->get_id(), 2003, 2004, $wcct_tshirt->get_id() ),
    'coupon'      => $wcct_coupon->get_id(),
    'customer'    => $wcct_customer_id,
);
