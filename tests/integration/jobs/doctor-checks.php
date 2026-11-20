<?php
/**
 * Lite's Doctor checks against seeded store states (see REST/DoctorChecksTest), then the steps of rest-requests.php.
 *
 * Requests to this site (the purchases check's check-in probe) are answered by dispatching them to the REST server as a
 * logged-out visitor and recorded in $GLOBALS['wcct_test_doctor_requests']; the wcct_test_block_rest option makes the
 * REST API refuse logged-out visitors, as a security plugin would.
 */

// phpcs:disable

use StorePulse\ConversionTracking\Log\CheckIn;
use StorePulse\ConversionTracking\Log\OrderLog;

$GLOBALS['wcct_test_doctor_requests'] = array();
$GLOBALS['wcct_test_doctor_orders']   = array();

add_filter(
    'rest_authentication_errors',
    function ( $result ) {
        if ( get_option( 'wcct_test_block_rest' ) && ! is_user_logged_in() ) {
            return new WP_Error( 'rest_not_logged_in', 'Only logged-in users.', array( 'status' => 401 ) );
        }

        return $result;
    },
    99
);

add_filter(
    'pre_http_request',
    function ( $pre, $args, $url ) {
        $GLOBALS['wcct_test_doctor_requests'][] = array( 'url' => $url, 'method' => $args['method'], 'cookies' => $args['cookies'], 'body' => $args['body'] );

        $user = get_current_user_id();
        wp_set_current_user( 0 );

        $route   = '/' . ltrim( (string) substr( $url, strlen( rest_url() ) ), '/' );
        $request = new WP_REST_Request( $args['method'], $route );
        $request->set_header( 'content-type', 'application/json' );
        $request->set_body( (string) $args['body'] );

        // rest_do_request() skips the authentication check the server runs for real requests.
        $error    = rest_get_server()->check_authentication();
        $response = is_wp_error( $error ) ? rest_convert_error_to_response( $error ) : rest_do_request( $request );

        wp_set_current_user( $user );

        return array(
            'headers'  => array(),
            'body'     => wp_json_encode( rest_get_server()->response_to_data( $response, false ) ),
            'response' => array( 'code' => $response->get_status(), 'message' => '' ),
            'cookies'  => array(),
            'filename' => null,
        );
    },
    10,
    3
);

/**
 * A shopper order created some seconds ago, for 'call' steps.
 *
 * @param int    $age         Seconds since it was created.
 * @param string $created_via checkout, admin, ...
 *
 * @return int Order ID.
 */
function wcct_test_doctor_order( $age, $created_via = 'checkout' ) {
    $order = new WC_Order();
    $order->set_currency( 'USD' );
    $order->set_created_via( $created_via );
    $order->set_total( '10.00' );
    $order->set_date_created( time() - (int) $age );
    $order->set_status( 'processing' );
    $order->save();

    $GLOBALS['wcct_test_doctor_orders'][] = $order->get_id();

    return $order->get_id();
}

/**
 * A check-in for an order and platform, through CheckIn::record() as the route records it; fields may carry the page load's token (load).
 *
 * @param int    $index    Which order of wcct_test_doctor_order(), from 0.
 * @param string $platform Platform ID.
 * @param array  $fields   loaded, consent, reason.
 *
 * @return string Result.
 */
function wcct_test_doctor_checkin( $index, $platform, array $fields ) {
    OrderLog::maybe_install();

    return CheckIn::record( wc_get_order( $GLOBALS['wcct_test_doctor_orders'][ $index ] ), $platform, $fields );
}

return require __DIR__ . '/rest-requests.php';
