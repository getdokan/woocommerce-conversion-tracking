<?php
/**
 * Adds to the cart next to a gift plugin and returns what reaches the browser. Runs in a test store process (see GiftAddToCartTest).
 */

// phpcs:disable

use StorePulse\ConversionTracking\Events\Pipeline;
use StorePulse\ConversionTracking\Events\ProductId;

$pipeline = wcct_init()->event_dispatcher->get_pipeline();

WC()->cart->empty_cart();

// A gift plugin like StoreGrowth BOGO: every Beanie gets a free T-Shirt and a free Beanie, added from inside the shopper's add.
add_action(
    'woocommerce_add_to_cart',
    function ( $cart_item_key, $product_id ) {
        $item = WC()->cart->cart_contents[ $cart_item_key ];

        // A gift whose add fails: a hook throws inside it and WooCommerce catches the exception.
        if ( ! empty( $item['ct_throw_gift'] ) ) {
            WC()->cart->add_to_cart( 2001, 1, 0, array(), array( 'ct_throw' => true ) );
        }

        if ( 2001 !== (int) $product_id || ! empty( $item['ct_gift'] ) || ! empty( $item['ct_throw'] ) ) {
            return;
        }

        WC()->cart->add_to_cart( 2005, 1, 0, array(), array( 'ct_gift' => true ) );
        WC()->cart->add_to_cart( 2001, 1, 0, array(), array( 'ct_gift' => true ) );
    },
    10,
    2
);
add_action(
    'woocommerce_add_to_cart',
    function ( $cart_item_key ) {
        if ( ! empty( WC()->cart->cart_contents[ $cart_item_key ]['ct_throw'] ) ) {
            throw new Exception( 'Gift unavailable.' );
        }
    },
    11
);
add_action(
    'woocommerce_before_calculate_totals',
    function ( $cart ) {
        foreach ( $cart->get_cart() as $item ) {
            if ( ! empty( $item['ct_gift'] ) ) {
                $item['data']->set_price( 0 );
            }
        }
    }
);
add_filter(
    'wcct_track_add_to_cart',
    function ( $track, $cart_item_key, $cart_item ) {
        return ! empty( $cart_item['ct_skip'] ) ? false : $track;
    },
    10,
    3
);

$steps = array();
$step  = function ( $name, $callback ) use ( $pipeline, &$steps ) {
    $before = count( $pipeline->pending()->after( '' ) );
    $callback();
    $steps[ $name ] = array_slice( $pipeline->pending()->after( '' ), $before );
};

// The shopper adds a Beanie; the plugin adds both gifts.
$step( 'beanie', function () { WC()->cart->add_to_cart( 2001, 1 ); } );
// The shopper picks a gift themselves (its own request): it is $0 in the cart.
$step( 'picked_gift', function () { WC()->cart->add_to_cart( 2005, 1, 0, array(), array( 'ct_gift' => true ) ); } );
// A plugin flags the item it adds.
$step( 'flagged', function () { WC()->cart->add_to_cart( 2005, 1, 0, array(), array( Pipeline::AUTO_ADDED => true ) ); } );
// A plugin opts out through the filter.
$step( 'filtered', function () { WC()->cart->add_to_cart( 2002, 1, 2003, array( 'attribute_color' => 'Blue' ), array( 'ct_skip' => true ) ); } );
// StoreGrowth adds a BOGO gift on its own, outside the shopper's add (on a quantity change).
$step( 'storegrowth_gift', function () { WC()->cart->add_to_cart( 2005, 1, 0, array(), array( 'bogo_offer' => true ) ); } );
// The shopper's add still sends when its gift's add throws.
$step( 'after_throw', function () { WC()->cart->add_to_cart( 2005, 1, 0, array(), array( 'ct_throw_gift' => true ) ); } );
// Two more Beanies on the line already in the cart, also after the throw.
$step( 'more_beanies', function () { WC()->cart->add_to_cart( 2001, 2 ); } );
// Two more again, the way the grouped product form adds: without the recalculation, so the line totals are old.
$step(
    'grouped',
    function () {
        remove_action( 'woocommerce_add_to_cart', array( WC()->cart, 'calculate_totals' ), 20 );
        WC()->cart->add_to_cart( 2001, 2 );
        add_action( 'woocommerce_add_to_cart', array( WC()->cart, 'calculate_totals' ), 20, 0 );
        WC()->cart->calculate_totals();
    }
);

$lines = array();

foreach ( WC()->cart->get_cart() as $item ) {
    $lines[] = array( (int) $item['data']->get_id(), (int) $item['quantity'], (float) $item['line_subtotal'] );
}

return array(
    'steps'   => $steps,
    'lines'   => $lines,
    'cart_id' => ProductId::for_cart( WC()->cart->get_cart(), 'facebook' ),
);
