<?php
/**
 * Fires woocommerce_thankyou twice off the order received page, as a funnel thank-you page would, and returns the footer output. Runs in a test store process (see ThankyouFallbackTest).
 */

// phpcs:disable

global $wcct_job;

// Order state for the once-per-order checks (#83).
$wcct_order = wc_get_order( $wcct_job['order_id'] );

if ( ! empty( $wcct_job['status'] ) ) {
    $wcct_order->set_status( $wcct_job['status'] );
    $wcct_order->save();
}

foreach ( isset( $wcct_job['sent'] ) ? $wcct_job['sent'] : array() as $wcct_platform ) {
    \StorePulse\ConversionTracking\Events\SentFlags::mark_sent( $wcct_order, $wcct_platform );
}

do_action( 'woocommerce_thankyou', $wcct_job['order_id'] );
do_action( 'woocommerce_thankyou', $wcct_job['order_id'] );

$pipeline = wcct_init()->event_dispatcher->get_pipeline();
$events   = $pipeline->get_page_events();

return array(
    'order_received' => is_order_received_page(),
    'markup'         => $pipeline->build_footer_markup( $events ),
    'scripts'        => $pipeline->build_footer_scripts( $events ),
);
