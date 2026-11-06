<?php
/**
 * Adds the orders to the store from store.php. Runs in its own process, after HPOS is switched on.
 */

// phpcs:disable

// Orders: 3001 placed by a guest, 3002 by the customer. Order IDs come from the posts table in both order storages.
$wcct_create_order = function ( $id, $key, $customer_id, array $billing, array $items ) {
    global $wpdb;

    $wpdb->query( "ALTER TABLE {$wpdb->posts} AUTO_INCREMENT = " . (int) $id );

    $order = new WC_Order();
    $order->set_customer_id( $customer_id );
    $order->set_order_key( $key );
    $order->set_currency( 'USD' );
    $order->set_payment_method( 'cod' );
    $order->set_payment_method_title( 'Cash on delivery' );

    foreach ( $billing as $field => $value ) {
        $order->{"set_billing_$field"}( $value );
    }

    foreach ( $items as $item ) {
        $order->add_product( wc_get_product( $item[0] ), $item[1] );
    }

    $shipping = new WC_Order_Item_Shipping();
    $shipping->set_props(
        array(
            'method_title' => 'Flat rate',
            'method_id'    => 'flat_rate',
            'total'        => '5.00',
        )
    );
    $order->add_item( $shipping );
    $order->calculate_totals( false );
    $order->save();

    return $order;
};

$wcct_guest_order = $wcct_create_order(
    3001,
    'wc_order_ctguest0001',
    0,
    array(
        'first_name' => 'Jane',
        'last_name'  => 'Doe',
        'email'      => 'jane@example.com',
        'phone'      => '555-0100',
        'address_1'  => '1 Test Street',
        'city'       => 'San Francisco',
        'state'      => 'CA',
        'postcode'   => '94105',
        'country'    => 'US',
    ),
    array( array( 2001, 2 ), array( 2003, 1 ) )
);
$wcct_guest_order->apply_coupon( 'ctsave5' );
$wcct_guest_order->set_status( 'processing' );
$wcct_guest_order->save();

$wcct_customer_order = $wcct_create_order(
    3002,
    'wc_order_ctcustomer01',
    501,
    array(
        'first_name' => 'Sam',
        'last_name'  => 'Buyer',
        'email'      => 'customer@example.com',
        'phone'      => '555-0101',
        'address_1'  => '2 Customer Ave',
        'city'       => 'Oakland',
        'state'      => 'CA',
        'postcode'   => '94607',
        'country'    => 'US',
    ),
    array( array( 2001, 1 ) )
);
$wcct_customer_order->set_status( 'processing' );
$wcct_customer_order->save();

return array(
    'hpos'      => \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(),
    'orders'    => array( $wcct_guest_order->get_id(), $wcct_customer_order->get_id() ),
    'order_key' => array( $wcct_guest_order->get_order_key(), $wcct_customer_order->get_order_key() ),
    'totals'    => array( $wcct_guest_order->get_total(), $wcct_customer_order->get_total() ),
);
