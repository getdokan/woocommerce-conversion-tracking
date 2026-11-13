<?php

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Install;

/**
 * Tests for the install type recorded on the first 3.0 run
 */
class InstallTest extends TestCase {

    protected function tearDown(): void {
        unset( $GLOBALS['wcct_test_options'] );
    }

    public function test_2_x_settings_make_an_upgrade() {
        $GLOBALS['wcct_test_options']['wcct_settings'] = array( 'facebook' => array( 'enabled' => true ) );

        $this->assertSame( Install::UPGRADE, Install::record() );
        $this->assertSame( 'upgrade', $GLOBALS['wcct_test_options']['wcct_install_type'] );
        $this->assertTrue( Install::is_upgrade() );
    }

    public function test_no_or_empty_2_x_settings_make_a_new_install() {
        $this->assertSame( Install::FRESH, Install::type() );

        $GLOBALS['wcct_test_options']['wcct_settings'] = array();

        $this->assertSame( 'new', Install::record() );
        $this->assertFalse( Install::is_upgrade() );
    }

    public function test_recorded_once() {
        Install::record();

        $GLOBALS['wcct_test_options']['wcct_settings'] = array( 'facebook' => array( 'enabled' => true ) );

        $this->assertSame( Install::FRESH, Install::record() );
        $this->assertSame( Install::FRESH, Install::type() );
    }

    public function test_bad_recorded_value_is_ignored() {
        $GLOBALS['wcct_test_options']['wcct_install_type'] = 'maybe';
        $GLOBALS['wcct_test_options']['wcct_settings']     = array( 'facebook' => array( 'enabled' => true ) );

        $this->assertTrue( Install::is_upgrade() );
    }
}
