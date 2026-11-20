<?php
/**
 * A 3.0 REST save while the 2.x settings are read as 3.0, then a write to the 2.x option (the 2.x settings page is gone in 3.0). Runs before the page (see CustomPlacementsTest).
 *
 * Job args (before_args): rest (body of the REST save), v1 (the wcct_settings option written after it).
 */

// phpcs:disable

$wcct_args = $GLOBALS['wcct_job']['before_args'];
$wcct_user = get_current_user_id();

wp_set_current_user( 1 );

$wcct_request = new WP_REST_Request( 'POST', '/wcct/v1/settings' );
$wcct_request->set_header( 'content-type', 'application/json' );
$wcct_request->set_body( json_encode( $wcct_args['rest'] ) );

$wcct_response = rest_do_request( $wcct_request );
$wcct_v3       = get_option( 'wcct_settings_v3' );

// The first 3.0 save copies the 2.x scripts.
if ( 200 !== $wcct_response->get_status() || ! isset( $wcct_v3['platforms']['custom']['thankyou_script'] ) ) {
    throw new RuntimeException( 'REST save: ' . $wcct_response->get_status() . ' ' . wp_json_encode( $wcct_v3 ) );
}

update_option( 'wcct_settings', $wcct_args['v1'] );

if ( get_option( 'wcct_settings_v3' ) !== $wcct_v3 ) {
    throw new RuntimeException( 'The 2.x write changed the 3.0 settings.' );
}

wp_set_current_user( $wcct_user );
