<?php
/**
 * Sends wcct/v1 requests as different users in a test store process (see REST/SettingsRouteTest).
 *
 * Job args: requests (list of { as, method, path, body }), grant (role given manage_wcct the way Pro grants it).
 * Users: admin (1), guest (0), customer (501), and a shop_manager and a subscriber created here.
 */

// phpcs:disable

global $wpdb;

$wcct_args  = isset( $GLOBALS['wcct_job']['args'] ) ? $GLOBALS['wcct_job']['args'] : array();
$wcct_users = array(
    'admin'        => 1,
    'guest'        => 0,
    'customer'     => 501,
    'shop_manager' => wp_insert_user( array( 'user_login' => 'ct_shop_manager', 'user_pass' => wp_generate_password(), 'user_email' => 'shopmanager@example.com', 'role' => 'shop_manager' ) ),
    'subscriber'   => wp_insert_user( array( 'user_login' => 'ct_subscriber', 'user_pass' => wp_generate_password(), 'user_email' => 'subscriber@example.com', 'role' => 'subscriber' ) ),
);

if ( ! empty( $wcct_args['grant'] ) ) {
    $wcct_role = $wcct_args['grant'];

    add_filter(
        'user_has_cap',
        function ( $allcaps, $caps, $args, $user ) use ( $wcct_role ) {
            if ( $user instanceof WP_User && in_array( $wcct_role, $user->roles, true ) ) {
                $allcaps['manage_wcct'] = true;
            }

            return $allcaps;
        },
        10,
        4
    );
}

$wcct_raw = function ( $name ) use ( $wpdb ) {
    return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
};

$wcct_out = array(
    'before'    => array( 'v1' => $wcct_raw( 'wcct_settings' ), 'v3' => $wcct_raw( 'wcct_settings_v3' ) ),
    'responses' => array(),
);

foreach ( $wcct_args['requests'] as $wcct_request ) {
    wp_set_current_user( $wcct_users[ $wcct_request['as'] ] );

    $request = new WP_REST_Request( $wcct_request['method'], $wcct_request['path'] );

    if ( isset( $wcct_request['body'] ) ) {
        $request->set_header( 'content-type', 'application/json' );
        $request->set_body( json_encode( $wcct_request['body'] ) );
    }

    $response = rest_do_request( $request );

    $wcct_out['responses'][] = array(
        'status' => $response->get_status(),
        'data'   => rest_get_server()->response_to_data( $response, false ),
    );
}

$wcct_out['after'] = array( 'v1' => $wcct_raw( 'wcct_settings' ), 'v3' => $wcct_raw( 'wcct_settings_v3' ) );

return $wcct_out;
