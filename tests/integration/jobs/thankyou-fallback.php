<?php
/**
 * Fires woocommerce_thankyou twice off the order received page, as a funnel thank-you page would, and returns the footer output. Runs in a test store process (see ThankyouFallbackTest).
 */

// phpcs:disable

global $wcct_job;

do_action( 'woocommerce_thankyou', $wcct_job['order_id'] );
do_action( 'woocommerce_thankyou', $wcct_job['order_id'] );

$pipeline = wcct_init()->event_dispatcher->get_pipeline();
$events   = $pipeline->get_page_events();

return array(
    'order_received' => is_order_received_page(),
    'markup'         => $pipeline->build_footer_markup( $events ),
    'scripts'        => $pipeline->build_footer_scripts( $events ),
);
