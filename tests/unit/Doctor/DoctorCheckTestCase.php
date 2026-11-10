<?php

require_once dirname( __DIR__ ) . '/Fakes/doctor-functions.php';

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Consent\ConsentMode;
use StorePulse\ConversionTracking\Doctor\Check;
use StorePulse\ConversionTracking\Doctor\Context;
use StorePulse\ConversionTracking\Settings\Store;

/**
 * Shared setup for the tests of Lite's Doctor checks: saved settings, active plugins and the findings as arrays.
 */
abstract class DoctorCheckTestCase extends TestCase {

    protected function setUp(): void {
        $GLOBALS['wcct_test_options'] = array();
    }

    protected function tearDown(): void {
        unset( $GLOBALS['wcct_test_options'], $GLOBALS['wcct_test_filters'] );
        ConsentMode::use_api( null );
    }

    /**
     * Save the 3.0 settings: platform ID => values.
     */
    protected function platforms( array $platforms, array $global = array() ) {
        $GLOBALS['wcct_test_options'][ Store::OPTION ] = array_merge( $global, array( 'platforms' => $platforms ) );
    }

    protected function activate( string ...$basenames ) {
        $GLOBALS['wcct_test_options']['active_plugins'] = $basenames;
    }

    protected function option( string $name, $value ) {
        $GLOBALS['wcct_test_options'][ $name ] = $value;
    }

    protected function filter( string $hook, callable $callback ) {
        $GLOBALS['wcct_test_filters'][ $hook ] = $callback;
    }

    /**
     * Run a check and return its findings as the route sends them.
     */
    protected function run_check( Check $check, $context = null ): array {
        return array_map(
            function ( $finding ) {
                return $finding->to_array();
            },
            $check->run( $context ? $context : new Context( 0 ) )
        );
    }
}
