<?php
/**
 * Runs a list of wcct/v1 REST steps as given users and returns each result. Runs in a test store process (see WCCT_Test_Rest_Case, REST/RoutesTest).
 *
 * Steps: [ 'request', method, path, body|null, user ], [ 'option', name ], [ 'set_option', name, value|null ], [ 'action', hook, arg ],
 * [ 'shutdown', class ] (runs that class's shutdown callbacks, returns how many), [ 'global', name ], [ 'call', callable, args ].
 * Users: admin, shop_manager, customer, subscriber, seller, logged_out. A string '{term:slug}' in a body becomes that product category's ID.
 */

// phpcs:disable

// The harness loads Lite before Pro, so Pro missed wcct_loaded (and its role grants); WordPress loads Pro first.
if ( class_exists( 'WeDevs_WC_Conversion_Tracking_Pro' ) && ! class_exists( 'WCCT_Pro_Admin' ) ) {
    WeDevs_WC_Conversion_Tracking_Pro::init()->includes();
    WeDevs_WC_Conversion_Tracking_Pro::init()->init_classes();
}

// Dokan's vendor roles, for the vendor checks.
foreach ( array( 'seller' => 'Vendor', 'vendor_staff' => 'Vendor Staff' ) as $wcct_role => $wcct_name ) {
    if ( ! get_role( $wcct_role ) ) {
        add_role( $wcct_role, $wcct_name, array( 'read' => true ) );
    }
}

$wcct_users = array( 'logged_out' => 0 );

foreach ( array( 'admin' => 'administrator', 'shop_manager' => 'shop_manager', 'customer' => 'customer', 'subscriber' => 'subscriber', 'seller' => 'seller' ) as $wcct_key => $wcct_role ) {
    $wcct_users[ $wcct_key ] = wp_insert_user(
        array(
            'user_login' => 'wcct_rest_' . $wcct_key,
            'user_email' => 'wcct_rest_' . $wcct_key . '@example.com',
            'user_pass'  => wp_generate_password(),
            'role'       => $wcct_role,
        )
    );
}

$wcct_terms = function ( $value ) use ( &$wcct_terms ) {
    if ( is_array( $value ) ) {
        return array_map( $wcct_terms, $value );
    }

    if ( is_string( $value ) && preg_match( '/^\{term:([a-z0-9-]+)\}$/', $value, $match ) ) {
        $term = get_term_by( 'slug', $match[1], 'product_cat' );

        return $term ? (int) $term->term_id : 0;
    }

    return $value;
};

/**
 * Each platform's sent flag on an order, for 'call' steps.
 *
 * @param int $order_id Order ID.
 *
 * @return string[] Platforms whose flag is set.
 */
function wcct_test_sent_flags( $order_id ) {
    $order = wc_get_order( $order_id );

    return array_values(
        array_filter(
            array( 'facebook', 'adwords', 'ga4', 'twitter', 'custom' ),
            function ( $platform ) use ( $order ) {
                return \StorePulse\ConversionTracking\Events\SentFlags::is_sent( $order, $platform );
            }
        )
    );
}

/**
 * Set an order's status, for 'call' steps.
 *
 * @param int    $order_id Order ID.
 * @param string $status   Status.
 *
 * @return null
 */
function wcct_test_order_status( $order_id, $status ) {
    $order = wc_get_order( $order_id );
    $order->set_status( $status );
    $order->save();

    return null;
}

/**
 * Rows of a read-only query, for 'call' steps.
 *
 * @param string $sql Query; {log} becomes the order log table.
 *
 * @return array[]
 */
function wcct_test_query( $sql ) {
    global $wpdb;

    return $wpdb->get_results( str_replace( '{log}', \StorePulse\ConversionTracking\Log\OrderLog::table(), $sql ), ARRAY_A );
}

$wcct_results = array();

foreach ( $GLOBALS['wcct_job']['steps'] as $wcct_step ) {
    if ( 'option' === $wcct_step[0] ) {
        $wcct_results[] = get_option( $wcct_step[1], null );
        continue;
    }

    if ( 'action' === $wcct_step[0] ) {
        do_action( $wcct_step[1], $wcct_step[2] );
        $wcct_results[] = null;
        continue;
    }

    if ( 'shutdown' === $wcct_step[0] ) {
        $wcct_ran = 0;

        foreach ( isset( $GLOBALS['wp_filter']['shutdown'] ) ? $GLOBALS['wp_filter']['shutdown']->callbacks : array() as $wcct_callbacks ) {
            foreach ( $wcct_callbacks as $wcct_callback ) {
                if ( is_array( $wcct_callback['function'] ) && $wcct_callback['function'][0] instanceof $wcct_step[1] ) {
                    call_user_func( $wcct_callback['function'] );
                    ++$wcct_ran;
                }
            }
        }

        $wcct_results[] = $wcct_ran;
        continue;
    }

    if ( 'global' === $wcct_step[0] ) {
        $wcct_results[] = isset( $GLOBALS[ $wcct_step[1] ] ) ? $GLOBALS[ $wcct_step[1] ] : null;
        continue;
    }

    if ( 'call' === $wcct_step[0] ) {
        $wcct_results[] = call_user_func_array( $wcct_step[1], isset( $wcct_step[2] ) ? $wcct_step[2] : array() );
        continue;
    }

    if ( 'set_option' === $wcct_step[0] ) {
        null === $wcct_step[2] ? delete_option( $wcct_step[1] ) : update_option( $wcct_step[1], $wcct_step[2] );
        $wcct_results[] = null;
        continue;
    }

    wp_set_current_user( $wcct_users[ $wcct_step[4] ] );

    $wcct_request = new WP_REST_Request( $wcct_step[1], $wcct_step[2] );

    if ( null !== $wcct_step[3] ) {
        $wcct_request->set_header( 'content-type', 'application/json' );
        $wcct_request->set_body( wp_json_encode( $wcct_terms( $wcct_step[3] ) ) );
    }

    $wcct_response  = rest_do_request( $wcct_request );
    $wcct_headers   = $wcct_response->get_headers();
    $wcct_results[] = array(
        'status'      => $wcct_response->get_status(),
        'data'        => rest_get_server()->response_to_data( $wcct_response, false ),
        'disposition' => isset( $wcct_headers['Content-Disposition'] ) ? $wcct_headers['Content-Disposition'] : null,
    );
}

wp_set_current_user( 0 );

return $wcct_results;
