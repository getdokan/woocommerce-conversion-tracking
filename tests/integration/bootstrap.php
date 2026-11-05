<?php
/**
 * Bootstrap for the characterisation tests
 *
 * Installs a fresh WordPress + WooCommerce store (setup/store.php, setup/orders.php), then every test requests
 * real pages from it in child processes (bin/run.php). Pro's tests/bootstrap.php loads this file
 * too, after setting WCCT_PRO_DIR.
 *
 * Environment: WP_TESTS_DIR (set by wp-env), WCCT_LITE_DIR (Lite to test, default this checkout),
 * WCCT_PRO_DIR, WC_DIR (default: next to Lite).
 */

// phpcs:disable

require_once __DIR__ . '/includes/class-wp.php';
require_once __DIR__ . '/includes/class-pages.php';
require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-fixture.php';
require_once __DIR__ . '/includes/class-page-test-case.php';

$wcct_lite_dir = rtrim( getenv( 'WCCT_LITE_DIR' ) ? getenv( 'WCCT_LITE_DIR' ) : dirname( __DIR__, 2 ), '/' );
$wcct_pro_dir  = (string) getenv( 'WCCT_PRO_DIR' );
$wcct_wc_dir   = rtrim( getenv( 'WC_DIR' ) ? getenv( 'WC_DIR' ) : dirname( (string) realpath( $wcct_lite_dir ) ) . '/woocommerce', '/' );
$wcct_plugins  = array( $wcct_wc_dir . '/woocommerce.php', $wcct_lite_dir . '/conversion-tracking.php' );

if ( $wcct_pro_dir ) {
    $wcct_plugins[] = rtrim( $wcct_pro_dir, '/' ) . '/woocommerce-conversion-tracking-pro.php';
}

WCCT_Test_WP::configure( $wcct_plugins );

$wcct_store = WCCT_Test_WP::install_store( array( __DIR__ . '/setup/store.php', __DIR__ . '/setup/orders.php' ) );

// Fixtures are only meaningful for the store they were recorded against.
if ( array( 3001, 3002 ) !== $wcct_store['orders'] || array( 2001, 2002, 2003, 2004, 2005 ) !== $wcct_store['products'] || 501 !== $wcct_store['customer'] ) {
    WCCT_Test_WP::abort( 'The store was not seeded with the fixed IDs: ' . json_encode( $wcct_store ) );
}

$wcct_version = function ( $file ) {
    return preg_match( '/^[\s*]*Version:\s*(\S+)/mi', (string) file_get_contents( $file ), $match ) ? $match[1] : '?';
};

printf(
    "Store: WordPress %s, WooCommerce %s, HPOS %s\nLite %s: %s\n%s\n",
    $wcct_store['wordpress'],
    $wcct_store['woocommerce'],
    $wcct_store['hpos'] ? 'on' : 'off',
    $wcct_version( $wcct_plugins[1] ),
    $wcct_lite_dir,
    $wcct_pro_dir ? 'Pro ' . $wcct_version( $wcct_plugins[2] ) . ': ' . $wcct_pro_dir : 'Pro: not loaded'
);
