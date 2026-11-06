<?php
/**
 * Posts the browser's Purchase confirmation for order 3001 to admin-ajax and returns the response and the flags it left. See PurchaseOnceTest.
 */

// phpcs:disable

$wcct_args = isset( $GLOBALS['wcct_job']['before_args'] ) ? $GLOBALS['wcct_job']['before_args'] : array();
$_POST     = array(
    'action'   => 'wcct_purchase_sent',
    'platform' => $wcct_args['platform'],
    'order_id' => '3001',
    'key'      => $wcct_args['key'],
);

add_filter( 'wp_doing_ajax', '__return_true' );
ob_start();

try {
    do_action( 'wp_ajax_nopriv_wcct_purchase_sent' );
} catch ( WCCT_Test_Exit $e ) {
    // wp_send_json() ends the request.
}

$wcct_response = ob_get_clean();
$wcct_order    = wc_get_order( 3001 );
$wcct_flags    = array();

foreach ( array( 'facebook', 'adwords', 'ga4', 'twitter', 'custom' ) as $wcct_platform ) {
    $wcct_flags[ $wcct_platform ] = \StorePulse\ConversionTracking\Events\SentFlags::is_sent( $wcct_order, $wcct_platform );
}

return array(
    'response' => $wcct_response,
    'flags'    => $wcct_flags,
);
