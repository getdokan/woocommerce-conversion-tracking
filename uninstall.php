<?php
/**
 * Deletes Conversion Tracking's data when the plugin is deleted; Pro's data stays while Pro is installed.
 *
 * @package WooCommerce Conversion Tracking
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// The plugin isn't loaded during uninstall; its two classes need nothing else.
require_once __DIR__ . '/includes/Uninstall/Cleanup.php';
require_once __DIR__ . '/includes/Uninstall/Uninstaller.php';

( new \StorePulse\ConversionTracking\Uninstall\Uninstaller(
    basename( __DIR__ ),
    \StorePulse\ConversionTracking\Uninstall\Uninstaller::pro_installed()
) )->run();
