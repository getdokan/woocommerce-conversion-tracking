<?php

require_once dirname( __DIR__ ) . '/Fakes/settings-functions.php';

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Settings\Google;

/**
 * Tests for the google platform over the ga4 and adwords integrations
 */
class GoogleTest extends TestCase {

    const GOOGLE = array(
        'measurement_id'     => 'G-ABC123',
        'tag_id'             => 'GT-XYZ',
        'ads_id'             => 'AW-123456789',
        'purchase_label'     => 'AbCdEfGhIj',
        'registration_label' => 'RegLabel12',
        'events'             => array( 'view_product', 'purchase', 'search', 'sign_up' ),
    );

    public function test_both_enables_both_integrations() {
        $integrations = Google::integrations( array_merge( self::GOOGLE, array( 'mode' => 'both' ) ) );

        $this->assertSame(
            array(
                'enabled'        => true,
                'measurement_id' => 'G-ABC123',
                'tag_id'         => 'GT-XYZ',
                'events'         => array( 'view_product', 'purchase', 'search', 'sign_up' ),
            ),
            $integrations['ga4']
        );
        $this->assertSame(
            array(
                'enabled'            => true,
                'account_id'         => 'AW-123456789',
                'purchase_label'     => 'AbCdEfGhIj',
                'registration_label' => 'RegLabel12',
                'events'             => array( 'purchase', 'sign_up' ),
            ),
            $integrations['adwords']
        );
    }

    public function test_each_mode_enables_only_its_integration() {
        $analytics = Google::integrations( array_merge( self::GOOGLE, array( 'mode' => 'analytics' ) ) );
        $ads       = Google::integrations( array_merge( self::GOOGLE, array( 'mode' => 'ads' ) ) );

        $this->assertTrue( $analytics['ga4']['enabled'] );
        $this->assertFalse( $analytics['adwords']['enabled'] );
        $this->assertFalse( $ads['ga4']['enabled'] );
        $this->assertTrue( $ads['adwords']['enabled'] );
    }

    public function test_an_integration_without_its_id_stays_off() {
        $integrations = Google::integrations( array( 'mode' => 'both', 'measurement_id' => 'G-ABC123' ) );

        $this->assertTrue( $integrations['ga4']['enabled'] );
        $this->assertFalse( $integrations['adwords']['enabled'] );
    }

    public function test_an_unknown_mode_reads_as_both() {
        $this->assertSame( 'both', Google::mode( array( 'mode' => 'x' ) ) );
        $this->assertSame( 'both', Google::mode( array() ) );
    }

    public function test_2x_settings_map_to_one_platform() {
        $google = Google::from_v1(
            array( 'enabled' => true, 0 => array( 'measurement_id' => ' G-ABC123 ', 'events' => array( 'view_item' => 'on', 'purchase' => 'on' ) ) ),
            array( 'enabled' => true, 0 => array( 'account_id' => 'AW-1', 'events' => array( 'Purchase' => 'on', 'Purchase-label' => 'lbl', 'registration-label' => 'reg' ) ) )
        );

        $this->assertSame(
            array(
                'measurement_id'     => 'G-ABC123',
                'ads_id'             => 'AW-1',
                'purchase_label'     => 'lbl',
                'registration_label' => 'reg',
                'mode'               => 'both',
                'paused'             => false,
                'events'             => array( 'view_product', 'purchase' ),
            ),
            $google
        );
    }

    public function test_2x_mode_follows_the_enabled_integration() {
        $ga4     = array( 'enabled' => true, 0 => array( 'measurement_id' => 'G-1' ) );
        $adwords = array( 'enabled' => false, 0 => array( 'account_id' => 'AW-1' ) );

        $this->assertSame( 'analytics', Google::from_v1( $ga4, $adwords )['mode'] );
        $this->assertSame( 'ads', Google::from_v1( array( 'enabled' => false ), array( 'enabled' => true, 0 => array( 'account_id' => 'AW-1' ) ) )['mode'] );

        // Nothing enabled: the saved IDs decide.
        $this->assertSame( 'both', Google::from_v1( array( 'enabled' => false, 0 => array( 'measurement_id' => 'G-1' ) ), $adwords )['mode'] );
    }

    public function test_no_2x_google_settings_map_to_nothing() {
        $this->assertSame( array(), Google::from_v1( null, 'junk' ) );
    }
}
