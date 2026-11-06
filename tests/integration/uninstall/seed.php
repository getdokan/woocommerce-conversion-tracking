<?php
/**
 * Writes every kind of data Free and Pro keep, on the current site (wp eval-file, plugins active).
 */

// phpcs:disable

global $wpdb;

$free_options = array(
    'wcct_settings'               => array( 'facebook' => array( 'enabled' => true ) ),
    'wcct_settings_v3'            => array( 'platforms' => array() ),
    'wcct_dismissable_notice'     => 'closed',
    'wcct_paused_platforms'       => array( 'twitter' ),
    'wcct_consent_mode'           => 'on',
    'wcct_install_type'           => 'upgrade',
    'wcct_migration_done'         => '3.0.0',
    'wcct_pre3_snapshot'          => array( 'wcct_settings' => array() ),
    'wcct_onboarding'             => array( 'status' => 'done' ),
    'wcct_count_once_since'       => 1,
    'wcct_secret_capi_token'      => 'wcct:sb1:fake',
    'wcct_order_log_db_version'   => '1',
    'wcct_verify'                 => array( 'token' => 'fake' ),
    'wcct_last_purchase_facebook' => array( 'last_purchase_at' => 1 ),
    'woocommerce_wc_conv_tracking_settings' => array( 'fb_pixel' => '1' ),
    'woocommerce-conversion-tracking_allow_tracking'     => 'no',
    'woocommerce-conversion-tracking_tracking_notice'    => 'hide',
    'woocommerce-conversion-tracking_tracking_last_send' => 1,
);

$pro_options = array(
    'wcct_permissions'          => array( 'shop_manager' => '1' ),
    'wcct_data_feed'            => array( 'types' => array( 'simple' ) ),
    'wcct_feed_status'          => array( 'state' => 'ready' ),
    'wcct_feed_build'           => array( 'id' => 'fake' ),
    'wcct_feed_login'           => array( 'username' => 'meta' ),
    'wcct_secret_feed_password' => 'wcct:sb1:fake',
    'wcct_pro_migration_done'   => '3.0.0',
);

foreach ( array_merge( $free_options, $pro_options ) as $name => $value ) {
    update_option( $name, $value );
}

set_transient( 'wcct_upgrade_to_20', 'yes', HOUR_IN_SECONDS );
set_transient( 'wcct_feed_example', 'yes', HOUR_IN_SECONDS );
set_site_transient( 'wcct_example', 'yes', HOUR_IN_SECONDS );

if ( is_multisite() && is_main_site() ) {
    update_network_option( null, 'wcct_network_example', 'yes' );
}

// The order log table (#112), created by the plugin itself (activation or the schema version check), with one real row.
\StorePulse\ConversionTracking\Log\OrderLog::install();
\StorePulse\ConversionTracking\Log\OrderLog::upsert(
    array(
        'order_id'       => 1,
        'platform'       => 'facebook',
        'source'         => 'browser',
        'library_loaded' => 1,
        'created_at'     => gmdate( 'Y-m-d H:i:s' ),
        'updated_at'     => gmdate( 'Y-m-d H:i:s' ),
    )
);

if ( 1 !== (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wcct_order_log" ) ) {
    WP_CLI::error( 'The order log row was not written.' );
}

as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, 'wcct_prune_order_log', array(), 'wcct' );
as_enqueue_async_action( 'wcct_feed_build', array(), 'wcct-product-feed' );
as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, 'wcct_feed_daily_build', array(), 'wcct-product-feed' );

wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'wcct_example_cron' );
wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'wcct_feed_example_cron' );

// Orders in both stores, so the meta is in wc_orders_meta and postmeta.
update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'yes' );

$order = wc_create_order();
$order->update_meta_data( '_wcct_sent_facebook', time() );
$order->update_meta_data( '_wcct_ga4_tracked', 'yes' );
$order->update_meta_data( '_wcct_thankyou_seen', time() );
$order->save();

$product = new WC_Product_Simple();
$product->set_name( 'Uninstall test product' );
$product->update_meta_data( '_wc_conv_track', '<script>/* 1.x */</script>' );
$product->save();

update_user_meta( 1, '_wcct_sign_up_pending', time() );

$dir = wp_upload_dir()['basedir'] . '/wcct-feed';
wp_mkdir_p( $dir );
file_put_contents( $dir . '/index.html', '' );
file_put_contents( $dir . '/.htaccess', 'Require all denied' );
file_put_contents( $dir . '/product-feed-abc.csv', "id,title\n1,Uninstall test product\n" );

WP_CLI::success( sprintf( 'Seeded site %d (order %d)', get_current_blog_id(), $order->get_id() ) );
