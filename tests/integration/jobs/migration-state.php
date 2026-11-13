<?php
/**
 * The migration's options after a request bootstrapped the store (see Characterisation/MigrationTest), and the retry flow when args.fail is set.
 */

// phpcs:disable

use StorePulse\ConversionTracking\Migration\RetryNotice;
use StorePulse\ConversionTracking\Migration\SettingsMigration;

global $wpdb;

$wcct_args = isset( $GLOBALS['wcct_job']['args'] ) ? $GLOBALS['wcct_job']['args'] : array();

$wcct_state = function () use ( $wpdb ) {
    $raw = function ( $name ) use ( $wpdb ) {
        return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
    };

    wp_cache_flush();

    return array(
        'v1_raw'   => $raw( 'wcct_settings' ),
        'v3'       => get_option( 'wcct_settings_v3', null ),
        'snapshot' => get_option( 'wcct_pre3_snapshot', null ),
        'flags'    => get_option( 'wcct_migration_flags', null ),
        'marker'   => get_option( 'wcct_migration_done', null ),
        'lock'     => $raw( 'wcct_migration_lock' ),
        'error'    => get_option( 'wcct_migration_error', null ),
        'since'    => get_option( 'wcct_count_once_since', null ),
        'paused'   => get_option( 'wcct_paused_platforms', null ),
        'version'  => WCCT_VERSION,
        'expected' => false === get_option( 'wcct_settings', false ) ? null : SettingsMigration::map( get_option( 'wcct_settings' ), true )['settings'],
    );
};

$wcct_notice = function ( $user_id ) {
    wp_set_current_user( $user_id );
    ob_start();
    RetryNotice::render();

    return ob_get_clean();
};

$wcct_handle = function ( $user_id, $nonce ) {
    wp_set_current_user( $user_id );
    $_REQUEST['_wpnonce'] = $nonce ? wp_create_nonce( RetryNotice::ACTION ) : 'bad';

    try {
        RetryNotice::handle();
    } catch ( WCCT_Test_Exit $e ) {
        return $e->getMessage();
    }

    return 'no exit';
};

$wcct_out = array( 'after_request' => $wcct_state() );

// Resume a platform, then write an unrelated field to the 2.x option.
if ( ! empty( $wcct_args['resume'] ) ) {
    \StorePulse\ConversionTracking\Platforms\Pause::set( $wcct_args['resume'], false );
    $wcct_out['after_resume'] = $wcct_state();

    $wcct_v1                         = get_option( 'wcct_settings' );
    $wcct_v1['twitter']              = array( 'enabled' => '1', 0 => array( 'pixel_id' => 'o9x3k' ) );
    update_option( 'wcct_settings', $wcct_v1 );

    $wcct_out['after_save']       = $wcct_state();
    $wcct_out['after_save']['v1'] = get_option( 'wcct_settings' );

    return $wcct_out;
}

// The settings page, as an administrator opens it.
if ( ! empty( $wcct_args['classic'] ) ) {
    require_once ABSPATH . 'wp-admin/includes/admin.php';
    wp_set_current_user( 1 );

    ob_start();
    wcct_init()->admin->conversion_tracking_template();
    $wcct_out['classic'] = ob_get_clean();

    return $wcct_out;
}

if ( empty( $wcct_args['fail'] ) ) {
    return $wcct_out;
}

// Start over as a 2.x store whose migration fails halfway.
delete_option( 'wcct_settings_v3' );
delete_option( 'wcct_migration_done' );
delete_option( 'wcct_pre3_snapshot' );
delete_option( 'wcct_migration_flags' );

$wcct_fail = function () {
    throw new RuntimeException( 'Simulated failure' );
};
add_filter( SettingsMigration::FILTER, $wcct_fail );

$wcct_customer = wp_insert_user( array( 'user_login' => 'ct_migration_customer', 'user_pass' => wp_generate_password(), 'user_email' => 'ct-migration@example.com', 'role' => 'customer' ) );

$wcct_out['failed_status']     = SettingsMigration::maybe_run();
$wcct_out['after_failure']     = $wcct_state();
$wcct_out['locked_status']     = SettingsMigration::maybe_run();
$wcct_out['notice_admin']      = $wcct_notice( 1 );
$wcct_out['notice_customer']   = $wcct_notice( $wcct_customer );
$wcct_out['handle_customer']   = $wcct_handle( $wcct_customer, true );
$wcct_out['handle_bad_nonce']  = $wcct_handle( 1, false );
$wcct_out['after_refused']     = $wcct_state();

remove_filter( SettingsMigration::FILTER, $wcct_fail );

$wcct_out['handle_admin']      = $wcct_handle( 1, true );
$wcct_out['after_retry']       = $wcct_state();
$wcct_out['notice_after']      = $wcct_notice( 1 );

return $wcct_out;
