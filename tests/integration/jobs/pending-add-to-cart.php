<?php
/**
 * Adds to the cart as a shopper would and returns what reaches the browser. Runs in a test store process (see PendingEventsTest).
 */

// phpcs:disable

use StorePulse\ConversionTracking\Events\Pipeline;

$pipeline = wcct_init()->event_dispatcher->get_pipeline();

WC()->cart->empty_cart();
WC()->cart->add_to_cart( 2001, 2 );
WC()->cart->add_to_cart( 2002, 1, 2003, array( 'attribute_color' => 'Blue' ) );

$queued    = $pipeline->pending()->after( '' );
$fragments = apply_filters( 'woocommerce_add_to_cart_fragments', array() );

// The browser has run the first add: only the second is still unseen.
$_COOKIE[ Pipeline::SEEN_COOKIE ] = $queued[0]['id'];

return array(
    'queued'    => $queued,
    'fragment'  => isset( $fragments[ Pipeline::AJAX_ACTION ] ) ? $fragments[ Pipeline::AJAX_ACTION ] : null,
    'unseen'    => array_column( $pipeline->unseen_events(), 'id' ),
    // The footer of a page in this request: per-visitor events never go into it.
    'footer'    => $pipeline->build_footer_scripts( array() ),
    'runner'    => $pipeline->runner_script(),
);
