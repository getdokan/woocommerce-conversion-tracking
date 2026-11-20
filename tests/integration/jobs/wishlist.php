<?php
/**
 * Adds to a wishlist through the YITH and WooCommerce Wishlists hooks and returns what reaches the browser. Runs in a test store process (see WishlistTest).
 *
 * Job args: plugin (true: a wishlist plugin is active).
 */

// phpcs:disable

$wcct_args     = $GLOBALS['wcct_job']['args'];
$wcct_pipeline = wcct_init()->event_dispatcher->get_pipeline();
$wcct_hook     = $GLOBALS['wp_filter']['yith_wcwl_added_to_wishlist'] ?? null;
$wcct_args_yith = array();

foreach ( $wcct_hook ? $wcct_hook->callbacks : array() as $wcct_callbacks ) {
    foreach ( $wcct_callbacks as $wcct_callback ) {
        $wcct_args_yith[] = $wcct_callback['accepted_args'];
    }
}

if ( ! empty( $wcct_args['plugin'] ) ) {
    add_filter( 'wcct_wishlist_active', '__return_true' );
}

$wcct_out = array(
    'yith_accepted_args' => $wcct_args_yith,
    // Applied as a filter, the hook's first argument comes back unchanged.
    'yith_as_filter'     => apply_filters( 'yith_wcwl_added_to_wishlist', 2001, 1, 0 ),
);

// A YITH add over REST: WooCommerce starts no session there.
WC()->session = null;
do_action( 'yith_wcwl_added_to_wishlist', 2001, 1, 0 );

$wcct_out['session_started'] = (bool) WC()->session;

// A WooCommerce Wishlists add of the Blue Hoodie variation.
do_action( 'woocommerce_wishlist_add_item', 'item-key', 2002, 1, 2003, array( 'attribute_color' => 'Blue' ), array(), 9 );

$wcct_out['queued']   = WC()->session ? $wcct_pipeline->pending()->after( '' ) : array();
$wcct_out['triggers'] = $wcct_pipeline->triggers();
$wcct_out['runner']   = $wcct_pipeline->runner_script();

return $wcct_out;
