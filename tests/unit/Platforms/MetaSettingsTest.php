<?php

require_once dirname( __DIR__ ) . '/Fakes/settings-functions.php';

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Platforms\MetaSettings;
use StorePulse\ConversionTracking\Platforms\PlatformSettings;

/**
 * Tests for Meta's layer over PlatformSettings
 */
class MetaSettingsTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['wcct_test_options'] = array();
    }

    protected function tearDown(): void {
        unset( $GLOBALS['wcct_test_options'], $GLOBALS['wcct_test_filters'] );
        PlatformSettings::reset();
    }

    public function test_the_3_0_settings_are_read_with_every_pixel() {
        $GLOBALS['wcct_test_options']['wcct_settings']    = array( 'facebook' => array( 'enabled' => true, array( 'pixel_id' => '999999999999999', 'events' => array( 'Purchase' => 'on' ) ) ) );
        $GLOBALS['wcct_test_options']['wcct_settings_v3'] = array(
            'platforms' => array(
                'facebook' => array(
                    'pixel_id' => '111111111111111',
                    'events'   => array( 'view_product', 'purchase' ),
                    'pixels'   => array( array( 'pixel_id' => '222222222222222', 'events' => array( 'purchase' ) ) ),
                ),
            ),
        );

        $platform = MetaSettings::platform();

        $this->assertSame( '111111111111111', $platform['pixel_id'] );
        $this->assertSame( '222222222222222', $platform['pixels'][0]['pixel_id'] );
    }

    public function test_2x_settings_are_read_as_3_0_until_the_migration_has_run() {
        $GLOBALS['wcct_test_options']['wcct_settings'] = array(
            'facebook' => array(
                'enabled' => true,
                array( 'pixel_id' => '111111111111111', 'events' => array( 'Purchase' => 'on', 'Registration' => 'on', 'Search' => 'off' ) ),
            ),
        );

        $platform = MetaSettings::platform();

        $this->assertSame( '111111111111111', $platform['pixel_id'] );
        $this->assertSame( array( 'purchase', 'sign_up' ), $platform['events'] );
    }

    public function test_a_3_0_store_without_meta_reads_empty() {
        $GLOBALS['wcct_test_options']['wcct_settings_v3'] = array( 'platforms' => array() );

        $this->assertSame( array(), MetaSettings::platform() );

        PlatformSettings::reset();
        $GLOBALS['wcct_test_options']['wcct_settings_v3'] = 'broken';

        $this->assertSame( array(), MetaSettings::platform() );
    }

    public function test_meta_event_names_map_to_platform_events_through_the_one_map() {
        $this->assertSame( 'view_product', MetaSettings::event( 'ViewContent' ) );
        $this->assertSame( 'sign_up', MetaSettings::event( 'CompleteRegistration' ) );
        // The 2.x settings key maps too.
        $this->assertSame( 'sign_up', MetaSettings::event( 'Registration' ) );
        $this->assertSame( 'view_category', MetaSettings::event( 'ViewCategory' ) );
        $this->assertSame( 'add_to_wishlist', MetaSettings::event( 'AddToWishlist' ) );
        $this->assertSame( '', MetaSettings::event( 'PageView' ) );
        $this->assertSame( '', MetaSettings::event( null ) );
        $this->assertSame( 'Registration', MetaSettings::settings_key( 'CompleteRegistration' ) );
        $this->assertSame( 'Purchase', MetaSettings::settings_key( 'Purchase' ) );
    }
}
