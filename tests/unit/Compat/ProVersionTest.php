<?php

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Compat\ProVersion;

/**
 * Which Pro Free treats as compatible, outdated or absent
 */
class ProVersionTest extends TestCase {

    /**
     * @dataProvider versions
     */
    public function test_state_per_pro_version( $version, $expected ) {
        $this->assertSame( $expected, ProVersion::state( $version ) );
    }

    public function versions() {
        return array(
            'no Pro'                   => array( '', ProVersion::NONE ),
            'no Pro (null)'            => array( null, ProVersion::NONE ),
            'Pro 1.1.4'                => array( '1.1.4', ProVersion::OUTDATED ),
            'Pro 1.0.1'                => array( '1.0.1', ProVersion::OUTDATED ),
            'Pro 2.9.9'                => array( '2.9.9', ProVersion::OUTDATED ),
            'Pro 3.0.0'                => array( '3.0.0', ProVersion::COMPATIBLE ),
            'Pro 3.0'                  => array( '3.0', ProVersion::COMPATIBLE ),
            'Pro 3.0.0 beta'           => array( '3.0.0-beta.1', ProVersion::COMPATIBLE ),
            'Pro 3.0.0 dev'            => array( '3.0.0-dev', ProVersion::COMPATIBLE ),
            'Pro 3.2.1'                => array( '3.2.1', ProVersion::COMPATIBLE ),
            'Pro 2.9.9 beta'           => array( '2.9.9-beta', ProVersion::OUTDATED ),
            'unreadable version'       => array( 'abc', ProVersion::OUTDATED ),
            'not a string'             => array( array( '3.0.0' ), ProVersion::NONE ),
            'surrounding whitespace'   => array( ' 3.0.0 ', ProVersion::COMPATIBLE ),
        );
    }

    public function test_minimum_is_3_0() {
        $this->assertSame( '3.0.0', ProVersion::MINIMUM );
        $this->assertSame( '3.0', ProVersion::minimum_label() );
    }

    public function test_no_pro_constant_means_no_pro() {
        if ( defined( 'WCCT_PRO_VERSION' ) ) {
            $this->markTestSkipped( 'WCCT_PRO_VERSION is defined in this process' );
        }

        $this->assertSame( '', ProVersion::installed() );
        $this->assertSame( ProVersion::NONE, ProVersion::current() );
        $this->assertFalse( ProVersion::is_active() );
        $this->assertFalse( ProVersion::is_outdated() );
    }
}
