<?php
/**
 * A 3.0 REST save while the 2.x settings are read as 3.0, then a 2.x settings page save of the Custom integration, as admin. Runs before the page (see CustomPlacementsTest).
 *
 * Job args (before_args): rest (body of the REST save), classic (settings[] the 2.x page posts).
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

// The state the 2.x page save has to fix: the first 3.0 save copied the 2.x scripts.
if ( 200 !== $wcct_response->get_status() || ! isset( $wcct_v3['platforms']['custom']['thankyou_script'] ) ) {
    throw new RuntimeException( 'REST save: ' . $wcct_response->get_status() . ' ' . wp_json_encode( $wcct_v3 ) );
}

$_POST['settings']    = wp_slash( $wcct_args['classic'] );
$_REQUEST['_wpnonce'] = wp_create_nonce( 'wcct-settings' );

$wcct_die = function () {
    return function () {
        throw new RuntimeException( 'wcct-json' );
    };
};

add_filter( 'wp_doing_ajax', '__return_true' );
add_filter( 'wp_die_ajax_handler', $wcct_die );
ob_start();

try {
    ( new WCCT_Ajax() )->wcct_save_settings();
} catch ( RuntimeException $e ) {
    if ( 'wcct-json' !== $e->getMessage() ) {
        throw $e;
    }
}

$wcct_json = json_decode( ob_get_clean(), true );

remove_filter( 'wp_doing_ajax', '__return_true' );
remove_filter( 'wp_die_ajax_handler', $wcct_die );
unset( $_POST['settings'], $_REQUEST['_wpnonce'] );
wp_set_current_user( $wcct_user );

if ( empty( $wcct_json['success'] ) ) {
    throw new RuntimeException( 'Classic save: ' . wp_json_encode( $wcct_json ) );
}
