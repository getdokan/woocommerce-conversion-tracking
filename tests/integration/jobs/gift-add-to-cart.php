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

        // A bundle plugin: the T-Shirt bundle adds two Beanies (which bring their gifts).
        if ( ! empty( $item['ct_bundle'] ) ) {
            WC()->cart->add_to_cart( 2001, 2, 0, array(), array( 'ct_child' => true ) );
        }

        // A plugin that removes the shopper's line and adds it again, with a ticked add-on (WPC FBT's "separately").
        if ( ! empty( $item['ct_separate'] ) ) {
            WC()->cart->remove_cart_item( $cart_item_key );
            WC()->cart->add_to_cart( 2002, 1, 2003, array( 'attribute_color' => 'Blue' ) );
            WC()->cart->add_to_cart( 2005, 1, 0, array(), array( 'ct_addon' => true ) );
        }

        // A gift plugin whose gift is new to the cart, so it is $0 only once the totals are calculated.
        if ( ! empty( $item['ct_gift_new'] ) ) {
            WC()->cart->add_to_cart( 2005, 1, 0, array(), array( 'ct_gift' => true, 'ct_round' => 2 ) );
        }

        // A discounted BOGO item: StoreGrowth's key, half price.
        if ( ! empty( $item['ct_half'] ) ) {
            WC()->cart->add_to_cart( 2001, 1, 0, array(), array( 'bogo_offer' => true, 'ct_half_gift' => true ) );
        }

        // A plugin that removes the shopper's line and adds the same line again.
        static $readded = false;

        if ( ! empty( $item['ct_readd'] ) && ! $readded ) {
            $readded = true;
            WC()->cart->remove_cart_item( $cart_item_key );
            WC()->cart->add_to_cart( 2005, 1, 0, array(), array( 'ct_readd' => true ) );
        }

        if ( 2001 !== (int) $product_id || ! empty( $item['ct_gift'] ) || ! empty( $item['ct_throw'] ) || ! empty( $item['bogo_offer'] ) ) {
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
// A plugin that adds an item after Conversion Tracking's handler (priority 9999) has run, once.
add_action(
    'woocommerce_add_to_cart',
    function ( $cart_item_key ) {
        static $done = false;

        if ( ! $done && ! empty( WC()->cart->cart_contents[ $cart_item_key ]['ct_late'] ) ) {
            $done = true;
            WC()->cart->add_to_cart( 2001, 1, 0, array(), array( 'ct_late_child' => true ) );
        }
    },
    10000
);
add_action(
    'woocommerce_before_calculate_totals',
    function ( $cart ) {
        foreach ( $cart->get_cart() as $item ) {
            if ( ! empty( $item['ct_gift'] ) || ! empty( $item['ct_bundle'] ) ) {
                $item['data']->set_price( 0 );
            }

            if ( ! empty( $item['ct_half_gift'] ) ) {
                $item['data']->set_price( 9 );
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
// Another plugin's bogo_offer key means nothing while StoreGrowth is not loaded.
$step( 'other_bogo_offer', function () { WC()->cart->add_to_cart( 2005, 1, 0, array(), array( 'bogo_offer' => true ) ); } );
// StoreGrowth adds a BOGO gift on its own, outside the shopper's add (on a quantity change).
eval( 'namespace StorePulse\\StoreGrowth\\Modules\\BoGo; class OrderBogo {}' );
$step( 'storegrowth_gift', function () { WC()->cart->add_to_cart( 2005, 2, 0, array(), array( 'bogo_offer' => true ) ); } );
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
// A bundle: its items join the shopper's AddToCart; their $0 gifts do not.
$step( 'bundle', function () { WC()->cart->add_to_cart( 2005, 1, 0, array(), array( 'ct_bundle' => true ) ); } );
// The shopper's line removed and added again with a ticked add-on: each counted once.
$step( 'separately', function () { WC()->cart->add_to_cart( 2002, 1, 2003, array( 'attribute_color' => 'Blue' ), array( 'ct_separate' => true ) ); } );
// The same line removed and added again: counted once.
$step( 'readd', function () { WC()->cart->add_to_cart( 2005, 1, 0, array(), array( 'ct_readd' => true ) ); } );
// An item added after the event was queued is left out, and not carried into the next add of that line.
$step( 'late', function () { WC()->cart->add_to_cart( 2005, 1, 0, array(), array( 'ct_late' => true ) ); } );
$step( 'late_again', function () { WC()->cart->add_to_cart( 2005, 1, 0, array(), array( 'ct_late' => true ) ); } );
// The grouped form's way, with a gift new to the cart: still $0, so left out.
$step(
    'grouped_gift',
    function () {
        remove_action( 'woocommerce_add_to_cart', array( WC()->cart, 'calculate_totals' ), 20 );
        WC()->cart->add_to_cart( 2002, 1, 2003, array( 'attribute_color' => 'Blue' ), array( 'ct_gift_new' => true ) );
        add_action( 'woocommerce_add_to_cart', array( WC()->cart, 'calculate_totals' ), 20, 0 );
        WC()->cart->calculate_totals();
    }
);
// A half-price BOGO item added with the shopper's: part of what they pay.
$step( 'half_price_bogo', function () { WC()->cart->add_to_cart( 2002, 1, 2003, array( 'attribute_color' => 'Blue' ), array( 'ct_half' => true ) ); } );

$lines = array();

foreach ( WC()->cart->get_cart() as $item ) {
    $lines[] = array( (int) $item['data']->get_id(), (int) $item['quantity'], (float) $item['line_subtotal'] );
}

return array(
    'steps'   => $steps,
    'lines'   => $lines,
    'cart_id' => ProductId::for_cart( WC()->cart->get_cart(), 'facebook' ),
);
