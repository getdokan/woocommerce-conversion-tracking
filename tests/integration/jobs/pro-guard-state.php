<?php
/**
 * What Free shows and runs with the Pro loaded in this process. Runs in a test store process (see Compat/OldProTest).
 */

// phpcs:disable

use StorePulse\ConversionTracking\Compat\ProGuard;

// Only Free's notice: other admin_notices callbacks need a full admin screen.
ob_start();

if ( false !== has_action( 'admin_notices', array( ProGuard::class, 'notice' ) ) ) {
    ProGuard::notice();
}

$wcct_notices = ob_get_clean();

// Old Pro's own notice (it asks for Free when Free is missing).
$wcct_old_pro_notice = '';
$wcct_old_pro_hooked = false;

if ( class_exists( 'WeDevs_WC_Conversion_Tracking_Pro' ) && false !== has_action( 'admin_notices', array( WeDevs_WC_Conversion_Tracking_Pro::init(), 'check_wcct' ) ) ) {
    $wcct_old_pro_hooked = true;
    ob_start();
    WeDevs_WC_Conversion_Tracking_Pro::init()->check_wcct();
    $wcct_old_pro_notice = trim( ob_get_clean() );
}

$wcct_notice = preg_match( '~<div[^>]*id="wcct-pro-update-notice".*?</div>~s', $wcct_notices, $wcct_match ) ? $wcct_match[0] : '';

return array(
    'notice'       => trim( preg_replace( '/\s+/', ' ', $wcct_notice ) ),
    'notices'      => substr_count( $wcct_notices, 'class="notice' ),
    'old_notice'   => $wcct_old_pro_notice,
    'old_hooked'   => $wcct_old_pro_hooked,
    'network'      => false !== has_action( 'network_admin_notices', array( ProGuard::class, 'notice' ) ),
    'state'        => \StorePulse\ConversionTracking\Compat\ProVersion::current(),
    'pro_version'  => \StorePulse\ConversionTracking\Compat\ProVersion::installed(),
    'legacy_hooks' => has_filter( 'wcct_integrations' ),
    'platforms'    => array_map( 'get_class', wcct_init()->manager->get_integrations() ),
);
