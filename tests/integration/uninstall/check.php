<?php
/**
 * Checks what an uninstall left on the current site (wp --skip-plugins eval-file check.php <kept>).
 *
 * <kept>: "pro" (only Free was deleted), "free" (only Pro was deleted) or "none" (both were deleted).
 */

// phpcs:disable

global $wpdb;

$kept     = isset( $args[0] ) ? $args[0] : 'none';
$failures = array();
$site     = get_current_blog_id();

$is_pro = function ( $name ) {
    $name = preg_replace( '/^_(site_)?transient_(timeout_)?/', '', $name );

    return in_array( $name, array( 'wcct_permissions', 'wcct_data_feed', 'wcct_secret_feed_password' ), true ) || 0 === strpos( $name, 'wcct_pro_' ) || 0 === strpos( $name, 'wcct_feed_' );
};

$wcct      = $wpdb->esc_like( 'wcct_' ) . '%';
$any_wcct  = '%' . $wpdb->esc_like( 'wcct_' ) . '%';
$transient = $wpdb->esc_like( '_transient_' ) . $any_wcct;
$site_tran = $wpdb->esc_like( '_site_transient_' ) . $any_wcct;

$table_exists = function ( $table ) use ( $wpdb ) {
    return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
};

$expect = function ( $what, $actual, $expected ) use ( &$failures, $site ) {
    if ( $actual !== $expected ) {
        $failures[] = sprintf( 'site %d: %s: expected %s, got %s', $site, $what, json_encode( $expected ), json_encode( $actual ) );
    }
};

// Options and transients.
$options = $wpdb->get_col(
    $wpdb->prepare(
        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s
        OR option_name IN ( 'woocommerce_wc_conv_tracking_settings', 'woocommerce-conversion-tracking_allow_tracking', 'woocommerce-conversion-tracking_tracking_notice', 'woocommerce-conversion-tracking_tracking_last_send' ) ORDER BY option_name",
        $wcct,
        $transient,
        $site_tran
    )
);
$pro_left  = array_values( array_filter( $options, $is_pro ) );
$free_left = array_values( array_diff( $options, $pro_left ) );

$expect( 'Free options left', 'free' === $kept ? count( $free_left ) > 0 : $free_left, 'free' === $kept ? true : array() );
$expect( 'Pro options left', 'pro' === $kept ? count( $pro_left ) > 0 : $pro_left, 'pro' === $kept ? true : array() );

if ( 'free' === $kept ) {
    $expect( 'Free options kept', array_values( array_diff( array( 'wcct_settings', 'wcct_settings_v3', 'wcct_verify', 'wcct_last_purchase_facebook', 'woocommerce_wc_conv_tracking_settings', '_transient_wcct_upgrade_to_20' ), $free_left ) ), array() );
}

if ( 'pro' === $kept ) {
    $expect( 'Pro options kept', array_values( array_diff( array( 'wcct_permissions', 'wcct_data_feed', 'wcct_feed_status', 'wcct_feed_login', 'wcct_secret_feed_password', '_transient_wcct_feed_example' ), $pro_left ) ), array() );
}

if ( is_multisite() && is_main_site() ) {
    $network = $wpdb->get_col( $wpdb->prepare( "SELECT meta_key FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s OR meta_key LIKE %s", $wcct, $site_tran ) );
    $expect( 'network options left', 'free' === $kept ? count( $network ) > 0 : $network, 'free' === $kept ? true : array() );
}

// Order log table.
$expect( 'order log table', $table_exists( $wpdb->prefix . 'wcct_order_log' ), 'free' === $kept );

// Action Scheduler groups and their actions.
foreach ( array( 'wcct' => 'free', 'wcct-product-feed' => 'pro' ) as $group => $owner ) {
    $rows = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions a INNER JOIN {$wpdb->prefix}actionscheduler_groups g ON g.group_id = a.group_id WHERE g.slug = %s", $group ) );
    $slug = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_groups WHERE slug = %s", $group ) );

    $expect( "actions in group {$group}", $rows > 0, $owner === $kept );
    $expect( "group {$group}", $slug > 0, $owner === $kept );
}

$orphans = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE hook LIKE %s", $wcct ) );
$expect( 'wcct_ actions left', $orphans > 0, 'none' !== $kept );

// Cron.
$hooks = array();
foreach ( (array) _get_cron_array() as $events ) {
    $hooks = array_merge( $hooks, array_keys( (array) $events ) );
}
$hooks = array_values( array_filter( array_unique( $hooks ), function ( $hook ) { return 0 === strpos( $hook, 'wcct_' ); } ) );
sort( $hooks );
$expect( 'cron hooks', $hooks, 'free' === $kept ? array( 'wcct_example_cron' ) : ( 'pro' === $kept ? array( 'wcct_feed_example_cron' ) : array() ) );

// Order meta: the sent flags stay in both stores, the thank-you flag goes.
$hpos_meta = function ( $key ) use ( $wpdb ) {
    return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key = %s", $key ) );
};
$post_meta = function ( $key ) use ( $wpdb ) {
    return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", $key ) );
};

foreach ( array( '_wcct_sent_facebook', '_wcct_ga4_tracked' ) as $key ) {
    $expect( "{$key} in wc_orders_meta", $hpos_meta( $key ), 1 );
    $expect( "{$key} in postmeta", $post_meta( $key ), 1 );
}

$expect( '_wcct_thankyou_seen in wc_orders_meta', $hpos_meta( '_wcct_thankyou_seen' ), 'free' === $kept ? 1 : 0 );
$expect( '_wcct_thankyou_seen in postmeta', $post_meta( '_wcct_thankyou_seen' ), 'free' === $kept ? 1 : 0 );
$expect( '_wc_conv_track product meta', $post_meta( '_wc_conv_track' ), 'free' === $kept ? 1 : 0 );

// User meta (one table for the network).
$user_meta = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_wcct_' ) . '%' ) );
$expect( 'user meta', $user_meta > 0, 'free' === $kept );

// Feed folder.
$expect( 'uploads/wcct-feed', is_dir( wp_upload_dir( null, false )['basedir'] . '/wcct-feed' ), 'pro' === $kept );

if ( $failures ) {
    WP_CLI::error( implode( "\n", $failures ) );
}

WP_CLI::success( sprintf( 'site %d: %s', $site, 'none' === $kept ? 'nothing left but the sent flags' : "only {$kept} data left" ) );
