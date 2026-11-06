<?php

require_once dirname( __DIR__ ) . '/Fakes/settings-functions.php';

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Platforms\GoogleTag;
use StorePulse\ConversionTracking\Platforms\Pause;
use StorePulse\ConversionTracking\Platforms\Registry;
use StorePulse\ConversionTracking\Settings\Schema;
use StorePulse\ConversionTracking\Settings\Values;

/**
 * Tests for pausing a platform: one stored list that every reader goes through
 */
class PauseTest extends TestCase {

    const GOOGLE = array(
        'mode'           => 'both',
        'measurement_id' => 'G-ABC123',
        'ads_id'         => 'AW-123456789',
        'events'         => array( 'purchase' ),
    );

    protected function setUp(): void {
        $GLOBALS['wcct_test_options'] = array();
        Pause::reset();
        GoogleTag::reset();
    }

    protected function tearDown(): void {
        unset( $GLOBALS['wcct_test_options'], $GLOBALS['wcct_test_filters'], $GLOBALS['wcct_test_hooks'], $GLOBALS['wcct_test_actions'], $GLOBALS['wcct_test_not_autoloaded'] );
        Pause::reset();
        GoogleTag::reset();
    }

    public function test_a_google_pause_stops_analytics_and_ads() {
        $GLOBALS['wcct_test_options']['wcct_settings_v3']      = array( 'platforms' => array( 'google' => self::GOOGLE ) );
        $GLOBALS['wcct_test_options']['wcct_paused_platforms'] = array( 'google' );

        $this->assertTrue( Pause::is_paused( 'ga4' ) );
        $this->assertTrue( Pause::is_paused( 'adwords' ) );
        $this->assertTrue( ( new WCCT_Test_Fake_Integration( 'ga4' ) )->is_paused() );
        $this->assertTrue( ( new WCCT_Test_Fake_Integration( 'adwords' ) )->is_paused() );
        $this->assertFalse( GoogleTag::settings( 'ga4' )['enabled'] );
        $this->assertFalse( GoogleTag::settings( 'adwords' )['enabled'] );
        $this->assertSame( array(), Registry::active( Registry::normalise( array( new WCCT_Test_Fake_Integration( 'ga4' ), new WCCT_Test_Fake_Integration( 'adwords' ) ) ) ) );
        $this->assertFalse( Pause::is_paused( 'facebook' ) );
    }

    public function test_a_pause_of_one_google_integration_stays_with_it() {
        $GLOBALS['wcct_test_options']['wcct_settings_v3']      = array( 'platforms' => array( 'google' => self::GOOGLE ) );
        $GLOBALS['wcct_test_options']['wcct_paused_platforms'] = array( 'ga4' );

        $this->assertFalse( GoogleTag::settings( 'ga4' )['enabled'] );
        $this->assertTrue( GoogleTag::settings( 'adwords' )['enabled'] );
        $this->assertFalse( Pause::is_platform_paused( 'google' ) );

        $GLOBALS['wcct_test_options']['wcct_paused_platforms'] = array( 'ga4', 'adwords' );

        $this->assertTrue( Pause::is_platform_paused( 'google' ) );
    }

    public function test_the_settings_flags_count_until_the_option_is_written() {
        $GLOBALS['wcct_test_options']['wcct_settings_v3'] = array(
            'platforms' => array(
                'google'   => self::GOOGLE + array( 'paused' => true ),
                'facebook' => array( 'pixel_id' => '123', 'paused' => false ),
            ),
        );

        $this->assertSame( array( 'google' ), Pause::ids() );
        $this->assertFalse( GoogleTag::settings( 'ga4' )['enabled'] );

        $GLOBALS['wcct_test_options']['wcct_paused_platforms'] = array();
        Pause::reset();
        GoogleTag::reset();

        $this->assertSame( array(), Pause::ids() );
        $this->assertTrue( GoogleTag::settings( 'ga4' )['enabled'] );
    }

    public function test_a_2x_integration_switched_off_reads_as_paused_before_the_migration() {
        $GLOBALS['wcct_test_options']['wcct_settings'] = array(
            'facebook' => array( 'enabled' => false, array( 'pixel_id' => '123' ) ),
            'twitter'  => array( 'enabled' => true, array( 'pixel_id' => 'o8x2k' ) ),
        );

        $this->assertTrue( Pause::is_paused( 'facebook' ) );
        $this->assertFalse( Pause::is_paused( 'twitter' ) );
    }

    public function test_pausing_writes_the_platform_and_fires_the_action() {
        $GLOBALS['wcct_test_options']['wcct_settings_v3'] = array( 'platforms' => array( 'google' => self::GOOGLE ) );

        $this->assertTrue( GoogleTag::settings( 'ga4' )['enabled'] );
        $this->assertSame( array( 'google' ), Pause::set( 'google', true ) );
        $this->assertSame( array( 'google' ), $GLOBALS['wcct_test_options']['wcct_paused_platforms'] );
        $this->assertSame( array( array( 'wcct_platform_pause_changed', array( 'google', true, array( 'google' ) ) ) ), $GLOBALS['wcct_test_actions'] );

        // The memoised Google settings are read again.
        $this->assertFalse( GoogleTag::settings( 'ga4' )['enabled'] );
        $this->assertFalse( GoogleTag::settings( 'adwords' )['enabled'] );
    }

    public function test_resuming_google_clears_its_integrations_and_keeps_the_others() {
        $GLOBALS['wcct_test_options']['wcct_paused_platforms'] = array( 'facebook', 'ga4', 'adwords', 'google' );

        $this->assertSame( array( 'facebook' ), Pause::set( 'google', false ) );
        $this->assertSame( array( 'facebook', 'twitter' ), Pause::set( 'twitter', true ) );
        $this->assertSame( array( 'facebook', 'twitter' ), Pause::set( 'twitter', true ) );
    }

    public function test_the_first_write_keeps_the_pauses_read_from_the_settings() {
        $GLOBALS['wcct_test_options']['wcct_settings_v3'] = array( 'platforms' => array( 'google' => self::GOOGLE + array( 'paused' => true ) ) );

        $this->assertSame( array( 'google', 'facebook' ), Pause::set( 'facebook', true ) );
    }

    public function test_the_settings_route_reports_the_stored_pause() {
        $doc = array( 'platforms' => array( 'twitter' => array( 'pixel_id' => 'o8x2k', 'paused' => true ) ) );

        $GLOBALS['wcct_test_options']['wcct_paused_platforms'] = array( 'google' );

        $values = Values::get( Schema::get(), $doc );

        $this->assertTrue( $values['platforms']['google']['paused'] );
        $this->assertFalse( $values['platforms']['twitter']['paused'] );
        $this->assertFalse( $values['platforms']['facebook']['paused'] );
    }

    public function test_the_filter_decides_for_every_reader() {
        $GLOBALS['wcct_test_options']['wcct_settings_v3']      = array( 'platforms' => array( 'google' => self::GOOGLE ) );
        $GLOBALS['wcct_test_options']['wcct_paused_platforms'] = array( 'google' );
        $GLOBALS['wcct_test_filters']['wcct_platform_paused']  = function ( $paused, $id ) {
            return 'adwords' === $id ? false : $paused;
        };

        $this->assertFalse( GoogleTag::settings( 'ga4' )['enabled'] );
        $this->assertTrue( GoogleTag::settings( 'adwords' )['enabled'] );
    }

    public function test_an_option_saved_without_autoload_still_counts() {
        $GLOBALS['wcct_test_options']['wcct_paused_platforms'] = array( 'facebook' );
        $GLOBALS['wcct_test_not_autoloaded']                   = array( 'wcct_paused_platforms' );

        $this->assertArrayNotHasKey( 'wcct_paused_platforms', wp_load_alloptions() );
        $this->assertTrue( Pause::is_paused( 'facebook' ) );
        $this->assertSame( array( 'facebook' ), Pause::stored() );
    }

    public function test_resuming_switches_a_2x_integration_with_an_id_back_on() {
        $GLOBALS['wcct_test_options']['wcct_settings'] = array(
            'facebook' => array( 'enabled' => false, array( 'pixel_id' => '123' ) ),
            'twitter'  => array( 'enabled' => false, array( 'pixel_id' => '' ) ),
            'ga4'      => array( 'enabled' => false, array( 'measurement_id' => 'G-ABC123' ) ),
            'adwords'  => array( 'enabled' => false, array( 'account_id' => '' ) ),
        );

        $this->assertSame( array( 'facebook', 'google' ), Pause::ids() );

        // Pausing never touches the 2.x switches.
        Pause::set( 'facebook', true );
        $this->assertFalse( $GLOBALS['wcct_test_options']['wcct_settings']['facebook']['enabled'] );

        Pause::set( 'facebook', false );
        Pause::set( 'google', false );
        Pause::set( 'twitter', false );

        $v1 = $GLOBALS['wcct_test_options']['wcct_settings'];

        $this->assertSame( '1', $v1['facebook']['enabled'] );
        $this->assertSame( '1', $v1['ga4']['enabled'] );
        // No ID saved: left off.
        $this->assertFalse( $v1['adwords']['enabled'] );
        $this->assertFalse( $v1['twitter']['enabled'] );
        $this->assertSame( array(), Pause::ids() );
    }

    public function test_resuming_leaves_2x_switches_alone_when_the_platform_was_not_paused_there() {
        $v1 = array(
            'ga4'     => array( 'enabled' => false, array( 'measurement_id' => 'G-ABC123' ) ),
            'adwords' => array( 'enabled' => true, array( 'account_id' => 'AW-1' ) ),
        );

        $GLOBALS['wcct_test_options']['wcct_settings'] = $v1;

        Pause::set( 'google', true );
        Pause::set( 'google', false );

        $this->assertSame( $v1, $GLOBALS['wcct_test_options']['wcct_settings'] );
    }

    public function test_platform_of_maps_google_integrations() {
        $this->assertSame( 'google', Pause::platform_of( 'ga4' ) );
        $this->assertSame( 'google', Pause::platform_of( 'adwords' ) );
        $this->assertSame( 'bing_ads', Pause::platform_of( 'bing_ads' ) );
    }
}
