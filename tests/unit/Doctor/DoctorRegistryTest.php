<?php

require_once dirname( __DIR__ ) . '/Fakes/doctor-functions.php';
require_once dirname( __DIR__ ) . '/Fakes/FakeChecks.php';

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Doctor\Checks\ConsentOffCheck;
use StorePulse\ConversionTracking\Doctor\Registry;

/**
 * Tests for the wcct_doctor_checks registry
 */
class DoctorRegistryTest extends TestCase {

    protected function tearDown(): void {
        unset( $GLOBALS['wcct_test_filters'] );
    }

    private function register( array $entries ) {
        $GLOBALS['wcct_test_filters'][ Registry::FILTER ] = function ( $checks ) use ( $entries ) {
            return array_merge( $checks, $entries );
        };
    }

    public function test_lite_registers_its_checks() {
        $this->assertSame( array( 'tracking-plugins', 'id-formats', 'consent-plugin', 'tag-manager', 'purchase-on', 'purchases-24h', 'consent-off' ), array_keys( Registry::all() ) );
        $this->assertCount( 7, Registry::applicable(), 'Free: "We checked 7 things"' );
        $this->assertInstanceOf( ConsentOffCheck::class, Registry::all()['consent-off'] );
    }

    public function test_class_names_and_instances_register_and_broken_entries_are_skipped() {
        $first = new WCCT_Test_Sample_Check( 'sample' );

        $this->register(
            array(
                WCCT_Test_Named_Check::class,
                $first,
                new WCCT_Test_Sample_Check( 'sample' ),
                new WCCT_Test_Sample_Check( 'Bad ID' ),
                new WCCT_Test_Sample_Check( '' ),
                new WCCT_Test_No_Id_Check(),
                WCCT_Test_Not_A_Check::class,
                WCCT_Test_Abstract_Check::class,
                'No_Such_Class',
                42,
                null,
            )
        );

        $checks = Registry::all();

        $this->assertSame( array( 'tracking-plugins', 'id-formats', 'consent-plugin', 'tag-manager', 'purchase-on', 'purchases-24h', 'consent-off', 'named', 'sample' ), array_keys( $checks ) );
        $this->assertSame( $first, $checks['sample'], 'The first check with an ID wins' );
    }

    public function test_a_filter_that_returns_junk_leaves_no_checks() {
        $GLOBALS['wcct_test_filters'][ Registry::FILTER ] = function () {
            return 'nope';
        };

        $this->assertSame( array(), Registry::all() );
    }

    public function test_checks_that_do_not_apply_are_left_out() {
        $this->register( array( new WCCT_Test_Sample_Check( 'off', 'local', false ), new WCCT_Test_Sample_Check( 'on' ) ) );

        $this->assertSame( array( 'tracking-plugins', 'id-formats', 'consent-plugin', 'tag-manager', 'purchase-on', 'purchases-24h', 'consent-off', 'on' ), array_keys( Registry::applicable() ) );
    }
}
