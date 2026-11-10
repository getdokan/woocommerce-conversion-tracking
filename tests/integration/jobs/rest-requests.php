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

/**
 * An order for the Activity log tests, for 'call' steps: placed $props['age'] seconds ago from the checkout, paid by cash on delivery.
 *
 * @param array $props status, created_via, age, thankyou_age (null for no thank-you page visit), renewal, rows (each: platform, loaded, columns, age; see wcct_test_log_row()).
 *
 * @return int Order ID.
 */
function wcct_test_log_order( array $props = array() ) {
    $props = array_merge( array( 'status' => 'processing', 'created_via' => 'checkout', 'age' => 3600, 'thankyou_age' => 3595, 'renewal' => false, 'rows' => array() ), $props );
    $order = new WC_Order();

    $order->set_currency( 'USD' );
    $order->set_payment_method( 'cod' );
    $order->set_created_via( $props['created_via'] );
    $order->set_billing_first_name( 'Private' );
    $order->set_billing_last_name( 'Person' );
    $order->set_billing_email( 'private.person@example.com' );
    $order->add_product( wc_get_product( 2001 ), 1 );
    $order->calculate_totals( false );
    $order->set_status( $props['status'] );
    $order->set_date_created( time() - $props['age'] );
    $order->set_date_paid( in_array( $props['status'], array( 'processing', 'completed' ), true ) ? time() - $props['age'] : null );

    if ( null !== $props['thankyou_age'] ) {
        $order->update_meta_data( \StorePulse\ConversionTracking\Log\CheckIn::THANKYOU_META, time() - $props['thankyou_age'] );
    }

    if ( $props['renewal'] ) {
        $order->update_meta_data( '_subscription_renewal', 99 );
    }

    $order->save();

    foreach ( $props['rows'] as $row ) {
        wcct_test_log_row( $order->get_id(), $row[0], $row[1], isset( $row[2] ) ? $row[2] : array(), isset( $row[3] ) ? $row[3] : 3590 );
    }

    return $order->get_id();
}

/**
 * A check-in row written $age seconds ago, for 'call' steps.
 *
 * @param int    $order_id Order ID.
 * @param string $platform Platform ID.
 * @param bool   $loaded   Handed over.
 * @param array  $columns  Other columns.
 * @param int    $age      Seconds ago.
 *
 * @return bool
 */
function wcct_test_log_row( $order_id, $platform, $loaded, array $columns = array(), $age = 3590 ) {
    $at = \StorePulse\ConversionTracking\Log\OrderLog::datetime( time() - $age );

    return \StorePulse\ConversionTracking\Log\OrderLog::upsert(
        array_merge(
            array(
                'order_id'         => (int) $order_id,
                'platform'         => $platform,
                'source'           => 'browser',
                'status'           => 'processing',
                'library_loaded'   => $loaded ? 1 : 0,
                'consent'          => 'off',
                'reason'           => $loaded ? '' : 'timeout',
                'event_id'         => 'wcct_' . (int) $order_id . '_purchase',
                'thankyou_seen_at' => $at,
                'sent_at'          => $loaded ? $at : null,
                'created_at'       => $at,
                'updated_at'       => $at,
            ),
            $columns
        )
    );
}

/**
 * Move the seeded orders (3001, 3002) out of the Activity log window, or 3001 to $age seconds ago, and drop the count-once cutoff, for 'call' steps.
 *
 * @param int|null $age Seconds ago for order 3001; null moves both out.
 *
 * @return null
 */
function wcct_test_log_clear( $age = null ) {
    foreach ( array( 3001, 3002 ) as $order_id ) {
        $order = wc_get_order( $order_id );
        $order->set_date_created( time() - ( null !== $age && 3001 === $order_id ? $age : 3 * DAY_IN_SECONDS ) );
        $order->save();
    }

    delete_option( 'wcct_count_once_since' );

    return null;
}

/**
 * Move rows of an order back in time, for 'call' steps.
 *
 * @param int $order_id Order ID.
 * @param int $seconds  Seconds.
 *
 * @return null
 */
function wcct_test_log_age_rows( $order_id, $seconds ) {
    global $wpdb;

    $table = \StorePulse\ConversionTracking\Log\OrderLog::table();

    foreach ( array( 'thankyou_seen_at', 'created_at', 'updated_at', 'sent_at' ) as $column ) {
        $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET {$column} = DATE_SUB({$column}, INTERVAL %d SECOND) WHERE order_id = %d AND {$column} IS NOT NULL", $seconds, $order_id ) );
    }

    return null;
}

/**
 * How many orders the cached Activity list holds, as a new request would read it, for 'call' steps.
 *
 * @return int
 */
function wcct_test_log_cached_count() {
    \StorePulse\ConversionTracking\Log\Activity::reset();

    return count( \StorePulse\ConversionTracking\Log\Activity::orders() );
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
