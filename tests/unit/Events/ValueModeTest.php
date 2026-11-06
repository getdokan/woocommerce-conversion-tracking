<?php

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Events\ValueMode;
use StorePulse\ConversionTracking\Install;

/**
 * Tests for the per-platform value mode, its settings keys and its defaults (D8)
 */
class ValueModeTest extends TestCase {

    protected function tearDown(): void {
        unset( $GLOBALS['wcct_test_options'], $GLOBALS['wcct_test_filters'] );
    }

    public function test_new_install_defaults_to_items_everywhere() {
        foreach ( array( 'facebook', 'adwords', 'twitter', 'bing_ads', 'ga4' ) as $platform ) {
            $this->assertSame( ValueMode::ITEMS, ValueMode::for_platform( $platform ), $platform );
        }
    }

    public function test_upgrader_keeps_the_total_on_meta_ads_x_and_microsoft() {
        $GLOBALS['wcct_test_options']['wcct_install_type'] = Install::UPGRADE;

        foreach ( array( 'facebook', 'adwords', 'twitter', 'bing_ads' ) as $platform ) {
            $this->assertSame( ValueMode::ALL, ValueMode::for_platform( $platform ), $platform );
        }

        $this->assertSame( ValueMode::ITEMS, ValueMode::for_platform( 'ga4' ) );
    }

    public function test_recorded_install_type_wins_over_the_2_x_settings_present_now() {
        $GLOBALS['wcct_test_options']['wcct_settings']     = array( 'twitter' => array( 'enabled' => true ) );
        $GLOBALS['wcct_test_options']['wcct_install_type'] = Install::FRESH;

        $this->assertSame( ValueMode::ITEMS, ValueMode::for_platform( 'twitter' ) );
    }

    public function test_reads_the_mode_under_each_platform_key_of_the_3_0_settings() {
        $GLOBALS['wcct_test_options']['wcct_install_type'] = Install::UPGRADE;
        $GLOBALS['wcct_test_options']['wcct_settings_v3']  = array(
            'platforms' => array(
                'facebook' => array( 'value_mode' => 'shipping' ),
                'google'   => array( 'value_mode' => 'items' ),
                'twitter'  => array( 'value_mode' => 'bogus' ),
                'bing_ads' => array( 'value_mode' => 'shipping' ),
                'ga4'      => array( 'value_mode' => 'all' ),
            ),
        );

        $this->assertSame( ValueMode::SHIPPING, ValueMode::for_platform( 'facebook' ) );
        $this->assertSame( ValueMode::ITEMS, ValueMode::for_platform( 'adwords' ) );
        $this->assertSame( ValueMode::ALL, ValueMode::for_platform( 'twitter' ), 'an invalid stored mode falls back to the default' );
        $this->assertSame( ValueMode::SHIPPING, ValueMode::for_platform( 'bing_ads' ) );
        $this->assertSame( ValueMode::ITEMS, ValueMode::for_platform( 'ga4' ), 'GA4 never reads a stored mode' );
    }

    public function test_mode_is_filterable_and_a_bad_filter_falls_back_to_items() {
        $GLOBALS['wcct_test_filters']['wcct_value_mode'] = function ( $mode, $platform ) {
            return 'adwords' === $platform ? ValueMode::ALL : 'nope';
        };

        $this->assertSame( ValueMode::ALL, ValueMode::for_platform( 'adwords' ) );
        $this->assertSame( ValueMode::ITEMS, ValueMode::for_platform( 'facebook' ) );
    }
}
