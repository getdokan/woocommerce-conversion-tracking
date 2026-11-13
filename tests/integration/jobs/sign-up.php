<?php
/**
 * Creates accounts the ways WordPress and WooCommerce do, then sends page events twice as the new customer. Runs in a test store process (see SignUpTest).
 */

// phpcs:disable

use StorePulse\ConversionTracking\Events\PageEvents;
use StorePulse\ConversionTracking\Events\SignUp;

$names = function () {
    return array_map(
        function ( $event ) {
            return $event->get_name();
        },
        PageEvents::current()
    );
};

$result = array();

// My account and checkout create customers through wc_create_new_customer(); wp-login and wp-admin through wp_insert_user().
$customer = wc_create_new_customer( 'new-customer@example.com', 'newcustomer', 'Pass-123456' );
$wp_user  = wp_insert_user(
    array(
        'user_login' => 'wploginuser',
        'user_email' => 'wplogin@example.com',
        'user_pass'  => 'Pass-123456',
    )
);

$result['customer_flagged'] = SignUp::is_pending( $customer );
$result['wp_user_flagged']  = SignUp::is_pending( $wp_user );

// Logged out: nothing is sent and the flag waits for the first login.
wp_set_current_user( 0 );
$result['logged_out'] = $names();

wp_set_current_user( $customer );
$result['first_view']  = $names();
$result['second_view'] = $names();
$result['cleared']     = ! SignUp::is_pending( $customer );

wp_set_current_user( $wp_user );
$result['wp_user_first_login'] = $names();

return $result;
