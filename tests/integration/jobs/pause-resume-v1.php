<?php
/**
 * Resumes a platform the 2.x settings read as paused and returns the options a later page sees. Runs in a test store process (see PauseTest).
 */

// phpcs:disable

use StorePulse\ConversionTracking\Platforms\Pause;

Pause::set( $GLOBALS['wcct_job']['args']['platform'], false );

return array(
    'wcct_settings'         => get_option( 'wcct_settings' ),
    'wcct_paused_platforms' => get_option( 'wcct_paused_platforms' ),
);
