<?php

require_once dirname( __DIR__ ) . '/Fakes/settings-functions.php';

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Settings\Schema;
use StorePulse\ConversionTracking\Settings\Store;
use StorePulse\ConversionTracking\Settings\Values;

/**
 * Tests for where the settings are read from and written to
 */
class StoreTest extends TestCase {

    const V1 = array(
        'facebook' => array(
            'enabled' => true,
            0         => array( 'pixel_id' => '123456789012345', 'events' => array( 'AddToCart' => 'on', 'Purchase' => 'on' ) ),
        ),
        'custom'   => array( 'enabled' => true, 'checkout' => '<script>a()</script>', 'registration' => '' ),
    );

    protected function setUp(): void {
        $GLOBALS['wcct_test_options'] = array();
    }

    protected function tearDown(): void {
        unset( $GLOBALS['wcct_test_options'], $GLOBALS['wcct_test_filters'] );
    }

    public function test_a_fresh_install_reads_empty_settings() {
        $this->assertSame( array(), Store::load() );
    }

    public function test_2x_settings_are_read_until_3x_settings_exist() {
        $GLOBALS['wcct_test_options']['wcct_settings'] = self::V1;

        $this->assertSame( '123456789012345', Store::load()['platforms']['facebook']['pixel_id'] );

        $GLOBALS['wcct_test_options']['wcct_settings_v3'] = array( 'platforms' => array( 'facebook' => array( 'pixel_id' => '999999999999999' ) ) );

        $this->assertSame( '999999999999999', Store::load()['platforms']['facebook']['pixel_id'] );
    }

    public function test_a_malformed_3x_option_reads_as_empty() {
        $GLOBALS['wcct_test_options']['wcct_settings']    = self::V1;
        $GLOBALS['wcct_test_options']['wcct_settings_v3'] = 'broken';

        $this->assertSame( array(), Store::load() );
    }

    public function test_a_save_writes_the_3x_option_and_leaves_2x_untouched() {
        $GLOBALS['wcct_test_options']['wcct_settings'] = self::V1;

        $schema = Schema::get();
        $result = Values::apply( $schema, Store::load(), array( 'platforms' => array( 'facebook' => array( 'pixel_id' => '222222222222222' ) ) ), function () {
            return true;
        } );

        Store::save( $result['doc'] );

        $this->assertSame( self::V1, $GLOBALS['wcct_test_options']['wcct_settings'] );
        $this->assertSame( array( 'wcct_settings', 'wcct_settings_v3' ), array_keys( $GLOBALS['wcct_test_options'] ) );

        $values = Values::get( $schema, Store::load() );

        $this->assertSame( '222222222222222', $values['platforms']['facebook']['pixel_id'] );
        $this->assertSame( array( 'add_to_cart', 'purchase' ), $values['platforms']['facebook']['events'] );
        $this->assertSame( '<script>a()</script>', $values['platforms']['custom']['thankyou_script'] );
    }
}
