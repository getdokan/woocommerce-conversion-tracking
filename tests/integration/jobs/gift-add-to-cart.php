<?php
/**
 * Adds to the cart next to a gift plugin and returns what reaches the browser. Runs in a test store process (see GiftAddToCartTest).
 */

// phpcs:disable

use StorePulse\ConversionTracking\Events\Pipeline;
use StorePulse\ConversionTracking\Events\PendingEvents;
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

        // A plugin that adds one more of the shopper's product with the same cart data, so it merges into their line, once.
        static $merged = false;

        if ( ! empty( $GLOBALS['ct_merge'] ) && ! $merged && 2001 === (int) $product_id && empty( $item['ct_gift'] ) ) {
            $merged = true;
            WC()->cart->add_to_cart( 2001, 1 );
        }

        // A bundle plugin whose item carries the given cart item data.
        if ( ! empty( $item['ct_child_data'] ) ) {
            WC()->cart->add_to_cart( 2001, 1, 0, array(), $item['ct_child_data'] );
        }

        // A plugin that removes the shopper's line and adds nothing.
        if ( ! empty( $item['ct_vanish'] ) ) {
            WC()->cart->remove_cart_item( $cart_item_key );
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
// A plugin hooked late, at 10000: its item still joins the shopper's AddToCart.
add_action(
    'woocommerce_add_to_cart',
    function ( $cart_item_key ) {
        if ( ! empty( WC()->cart->cart_contents[ $cart_item_key ]['ct_late_join'] ) ) {
            WC()->cart->add_to_cart( 2001, 1, 0, array(), array( 'ct_late_child' => true ) );
        }
    },
    10000
);
// A plugin hooked last and after Conversion Tracking, so after its handler has run, once.
add_action(
    'woocommerce_add_to_cart',
    function ( $cart_item_key ) {
        static $done = false;

        if ( ! $done && ! empty( WC()->cart->cart_contents[ $cart_item_key ]['ct_late'] ) ) {
            $done = true;
            WC()->cart->add_to_cart( 2001, 1, 0, array(), array( 'ct_late_child' => true ) );
        }
    },
    PHP_INT_MAX
);
add_action(
    'woocommerce_before_calculate_totals',
    function ( $cart ) {
        foreach ( $cart->get_cart() as $item ) {
            if ( ! empty( $item['ct_gift'] ) || ! empty( $item['ct_bundle'] ) || ( ! empty( $GLOBALS['ct_free_ids'] ) && in_array( $item['data']->get_id(), $GLOBALS['ct_free_ids'], true ) && empty( $item['ct_child'] ) ) ) {
                $item['data']->set_price( 0 );
            }

            if ( ! empty( $item['ct_half_gift'] ) ) {
                $item['data']->set_price( 9 );
            }
        }

        // A plugin that adds an add-on while totals are calculated, once.
        static $calc_added = false;

        if ( ! empty( $GLOBALS['ct_calc_addon'] ) && ! $calc_added ) {
            $calc_added = true;
            $cart->add_to_cart( 2001, 1, 0, array(), array( 'ct_calc_child' => true ) );
        }
    }
);
add_filter(
    'wcct_track_add_to_cart',
    function ( $track, $cart_item_key, $cart_item, $nested ) {
        if ( $nested ) {
            $GLOBALS['ct_nested_filtered'][] = (int) $cart_item['data']->get_id();
        }

        return ! empty( $cart_item['ct_skip'] ) ? false : $track;
    },
    10,
    4
);

$steps = array();
// Each step stands for its own request: forget the product names earlier steps used.
$names = new ReflectionProperty( Pipeline::class, 'named_used' );
$names->setAccessible( true );
$step  = function ( $name, $callback ) use ( $pipeline, &$steps, $names ) {
    $names->setValue( $pipeline, array() );

    // The browser ran the earlier steps' events; the queue keeps only the last PendingEvents::MAX.
    WC()->session->set( PendingEvents::SESSION_KEY, array() );
    $before = count( $pipeline->pending()->after( '' ) );
    $callback();
    $steps[ $name ] = array_slice( $pipeline->pending()->after( '' ), $before );
};

// The shopper adds a Beanie; the plugin adds both gifts.
$step( 'beanie', function () { WC()->cart->add_to_cart( 2001, 1 ); } );
// The shopper picks a gift themselves (its own request): it is $0 in the cart.
$step( 'picked_gift', function () { WC()->cart->add_to_cart( 2005, 1, 0, array(), array( 'ct_gift' => true ) ); } );
// A $0 product the shopper adds with the product form, then through the Store API.
$step(
    'free_by_form',
    function () {
        $_REQUEST['add-to-cart'] = 2005;
        WC()->cart->add_to_cart( 2005, 1, 0, array(), array( 'ct_gift' => true ) );
        unset( $_REQUEST['add-to-cart'] );
    }
);
// A gift plugin that adds a $0 gift of another product while the shopper's add request runs (on template_redirect, say).
$step(
    'gift_in_add_request',
    function () {
        $_REQUEST['add-to-cart'] = 2001;
        WC()->cart->add_to_cart( 2001, 1 );
        WC()->cart->add_to_cart( 2005, 1, 0, array(), array( 'ct_gift' => true, 'ct_round' => 4 ) );
        unset( $_REQUEST['add-to-cart'] );
    }
);
// A free variation the request names, then a second $0 add of it in the same request: only the first is the shopper's.
$step(
    'free_named_twice',
    function () {
        $_REQUEST['add-to-cart'] = 2003;
        WC()->cart->add_to_cart( 2002, 1, 2003, array( 'attribute_color' => 'Blue' ), array( 'ct_gift' => true ) );
        WC()->cart->add_to_cart( 2002, 1, 2003, array( 'attribute_color' => 'Blue' ), array( 'ct_gift' => true, 'ct_round' => 5 ) );
        unset( $_REQUEST['add-to-cart'] );
    }
);
// A "buy one, get the same free" gift added after the shopper's AJAX add, in the same request.
$step(
    'same_product_gift_in_request',
    function () {
        $_REQUEST['product_id'] = 2001;
        WC()->cart->add_to_cart( 2001, 1 );
        WC()->cart->add_to_cart( 2001, 1, 0, array(), array( 'ct_gift' => true, 'ct_round' => 7 ) );
        unset( $_REQUEST['product_id'] );
    }
);
// The shopper's variation from the form, then a $0 sibling variation as a gift.
$step(
    'sibling_gift_in_request',
    function () {
        $_REQUEST['add-to-cart']  = 2002;
        $_REQUEST['variation_id'] = 2003;
        WC()->cart->add_to_cart( 2002, 1, 2003, array( 'attribute_color' => 'Blue' ) );
        WC()->cart->add_to_cart( 2002, 1, 2004, array( 'attribute_color' => 'Red' ), array( 'ct_gift' => true, 'ct_round' => 7 ) );
        unset( $_REQUEST['add-to-cart'], $_REQUEST['variation_id'] );
    }
);
// Bundle items a plugin flags, or leaves out through the filter, stay out of the event.
$step( 'bundle_flagged_child', function () { WC()->cart->add_to_cart( 2005, 1, 0, array(), array( 'ct_child_data' => array( Pipeline::AUTO_ADDED => true ) ) ); } );
$step( 'bundle_filtered_child', function () { WC()->cart->add_to_cart( 2005, 1, 0, array(), array( 'ct_child_data' => array( 'ct_skip' => true ) ) ); } );
// A plugin merges a copy into the shopper's own line: the shopper added one.
$step(
    'merge_into_shoppers_line',
    function () {
        $GLOBALS['ct_merge'] = true;
        WC()->cart->add_to_cart( 2001, 1 );
        unset( $GLOBALS['ct_merge'] );
    }
);
// The shopper's line is removed, then a $0 copy is added in the same request: the removed add used the name.
$step(
    'vanish_then_free_copy',
    function () {
        $_REQUEST['add-to-cart'] = 2005;
        WC()->cart->add_to_cart( 2005, 1, 0, array(), array( 'ct_vanish' => true ) );
        WC()->cart->add_to_cart( 2005, 1, 0, array(), array( 'ct_gift' => true, 'ct_round' => 9 ) );
        unset( $_REQUEST['add-to-cart'] );
    }
);
// The grouped form's way, with an add-on a plugin adds while CT recalculates the totals.
$step(
    'grouped_calc_addon',
    function () {
        remove_action( 'woocommerce_add_to_cart', array( WC()->cart, 'calculate_totals' ), 20 );
        $GLOBALS['ct_calc_addon'] = true;
        WC()->cart->add_to_cart( 2002, 1, 2003, array( 'attribute_color' => 'Blue' ), array( 'ct_gift_new' => true, 'ct_round' => 10 ) );
        unset( $GLOBALS['ct_calc_addon'] );
        add_action( 'woocommerce_add_to_cart', array( WC()->cart, 'calculate_totals' ), 20, 0 );
        WC()->cart->calculate_totals();
    }
);
// A flagged add of a named product uses the name: a $0 copy after it is a gift.
$step(
    'flagged_then_free_copy',
    function () {
        $_REQUEST['add-to-cart'] = 2005;
        WC()->cart->add_to_cart( 2005, 1, 0, array(), array( Pipeline::AUTO_ADDED => true, 'ct_round' => 11 ) );
        WC()->cart->add_to_cart( 2005, 1, 0, array(), array( 'ct_gift' => true, 'ct_round' => 11 ) );
        unset( $_REQUEST['add-to-cart'] );
    }
);
// The grouped form posts every child; one left at 0 is not named, so its $0 gift sends nothing.
$step(
    'grouped_unpicked_child_gift',
    function () {
        $_REQUEST['add-to-cart'] = 2002;
        $_REQUEST['quantity']    = array( 2001 => '1', 2005 => '0' );
        WC()->cart->add_to_cart( 2001, 1 );
        WC()->cart->add_to_cart( 2005, 1, 0, array(), array( 'ct_gift' => true, 'ct_round' => 12 ) );
        unset( $_REQUEST['add-to-cart'], $_REQUEST['quantity'] );
    }
);
// A plugin removes the shopper's line and adds nothing.
$step( 'vanish', function () { WC()->cart->add_to_cart( 2005, 1, 0, array(), array( 'ct_vanish' => true ) ); } );
// A side cart's own AJAX action that posts product_id.
$step(
    'free_by_custom_ajax',
    function () {
        $_REQUEST['product_id'] = 2001;
        WC()->cart->add_to_cart( 2001, 1, 0, array(), array( 'ct_gift' => true, 'ct_round' => 6 ) );
        unset( $_REQUEST['product_id'] );
    }
);
// The Store API's add item, alone and in a batch (the block product button), with the T-Shirt free for these steps.
add_filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );
$store_api = function ( $path, $body ) {
    $request = new WP_REST_Request( 'POST', $path );
    $request->set_header( 'Content-Type', 'application/json' );
    $request->set_body( wp_json_encode( $body ) );

    return rest_do_request( $request )->get_status();
};
$status = array();
$GLOBALS['ct_free_ids'] = array( 2005, 2004 );
$step( 'free_by_store_api', function () use ( $store_api, &$status ) { $status[] = $store_api( '/wc/store/v1/cart/add-item', array( 'id' => 2005, 'quantity' => 1 ) ); } );
$step(
    'free_by_store_api_batch',
    function () use ( $store_api, &$status ) {
        // The Store API raises an existing line's quantity without firing woocommerce_add_to_cart, so start a new line.
        WC()->cart->remove_cart_item( WC()->cart->generate_cart_id( 2005 ) );
        // Two free adds in one batch, each its own sub-request.
        $status[] = $store_api(
            '/wc/store/v1/batch',
            array(
                'requests' => array(
                    array( 'method' => 'POST', 'path' => '/wc/store/v1/cart/add-item', 'body' => array( 'id' => 2005, 'quantity' => 1 ) ),
                    array( 'method' => 'POST', 'path' => '/wc/store/v1/cart/add-item', 'body' => array( 'id' => 2004, 'quantity' => 1 ) ),
                ),
            )
        );
    }
);
// Two Store API requests one after the other, each naming the free T-Shirt: each sends, since a finished request's names are forgotten.
$rest_names_left = null;
$step(
    'free_by_store_api_twice',
    function () use ( $store_api, $names, $pipeline, &$rest_names_left ) {
        foreach ( array( 1, 2 ) as $round ) {
            WC()->cart->remove_cart_item( WC()->cart->generate_cart_id( 2005 ) );
            $store_api( '/wc/store/v1/cart/add-item', array( 'id' => 2005, 'quantity' => 1 ) );
        }

        // Each finished REST request's names are gone (object IDs get reused).
        $rest_names_left = array_values( array_filter( array_keys( $names->getValue( $pipeline ) ), function ( $scope ) { return 0 === strpos( $scope, 'rest-' ); } ) );
    }
);
unset( $GLOBALS['ct_free_ids'] );
// A form add the request names, after the REST requests are done.
$step(
    'free_by_form_after_rest',
    function () {
        $_REQUEST['add-to-cart'] = 2005;
        WC()->cart->add_to_cart( 2005, 1, 0, array(), array( 'ct_gift' => true, 'ct_round' => 8 ) );
        unset( $_REQUEST['add-to-cart'] );
    }
);
// A gift plugin that adds a $0 gift on a quantity change, here the Store API's update item in a batch.
add_action(
    'woocommerce_after_cart_item_quantity_update',
    function () {
        if ( ! empty( $GLOBALS['ct_gift_on_update'] ) ) {
            WC()->cart->add_to_cart( 2005, 1, 0, array(), array( 'ct_gift' => true, 'ct_round' => 3 ) );
        }
    }
);
$step(
    'gift_on_store_api_update',
    function () use ( $store_api, &$status ) {
        $beanie = WC()->cart->generate_cart_id( 2001 );
        $GLOBALS['ct_gift_on_update'] = true;
        $status[] = $store_api( '/wc/store/v1/batch', array( 'requests' => array( array( 'method' => 'POST', 'path' => '/wc/store/v1/cart/update-item', 'body' => array( 'key' => $beanie, 'quantity' => 2 ) ) ) ) );
        unset( $GLOBALS['ct_gift_on_update'] );
    }
);
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
$step( 'late_join', function () { WC()->cart->add_to_cart( 2005, 1, 0, array(), array( 'ct_late_join' => true ) ); } );
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
    'status'  => $status,
    'rest_names_left' => $rest_names_left,
    'nested'  => isset( $GLOBALS['ct_nested_filtered'] ) ? array_values( array_unique( $GLOBALS['ct_nested_filtered'] ) ) : array(),
);
