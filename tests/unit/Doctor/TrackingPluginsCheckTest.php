<?php

require_once __DIR__ . '/DoctorCheckTestCase.php';

use StorePulse\ConversionTracking\Doctor\Checks\TrackingPluginsCheck;

/**
 * Other tracking plugins that send to a platform we send to
 */
class TrackingPluginsCheckTest extends DoctorCheckTestCase {

    const META = 'facebook-for-woocommerce/facebook-for-woocommerce.php';

    private function meta_connected( array $values = array() ) {
        $this->platforms( array( 'facebook' => array_merge( array( 'pixel_id' => '123456789012345' ), $values ) ) );
    }

    public function test_meta_for_woocommerce_with_its_pixel_on_is_a_sure_duplicate() {
        $this->meta_connected();
        $this->activate( self::META );
        $this->option( 'wc_facebook_pixel_id', '999999999999999' );

        $findings = $this->run_check( new TrackingPluginsCheck() );

        $this->assertCount( 1, $findings );
        $this->assertSame( 'Meta for WooCommerce (formerly Facebook for WooCommerce) also sends to Meta', $findings[0]['found'] );
        $this->assertSame( array( 'tracking-plugins', 'facebook-for-woocommerce:facebook', 'sure', 'warning', 'Duplicate' ), array( $findings[0]['check'], $findings[0]['key'], $findings[0]['confidence'], $findings[0]['severity'], $findings[0]['label'] ) );
        $this->assertSame( array( 'Meta', 'Double counting' ), $findings[0]['tags'] );
        $this->assertSame( 'recheck', $findings[0]['action']['type'] );
        $this->assertSame( 'Pick one plugin to send to Meta.', $findings[0]['steps'][0] );
    }

    public function test_meta_for_woocommerce_without_a_pixel_or_with_its_filter_off_sends_nothing() {
        $this->meta_connected();
        $this->activate( self::META );

        $this->assertSame( array(), $this->run_check( new TrackingPluginsCheck() ), 'No Pixel ID' );

        $this->option( 'wc_facebook_pixel_id', '999999999999999' );
        $this->filter( 'facebook_for_woocommerce_integration_pixel_enabled', function () { return false; } );

        $this->assertSame( array(), $this->run_check( new TrackingPluginsCheck() ), 'Pixel filtered off' );
    }

    public function test_a_plugin_we_cannot_read_is_a_not_sure_finding_per_shared_platform() {
        $this->platforms(
            array(
                'facebook' => array( 'pixel_id' => '123456789012345' ),
                'google'   => array( 'mode' => 'analytics', 'measurement_id' => 'G-ABC123' ),
                'twitter'  => array( 'pixel_id' => 'o8x2k' ),
            )
        );
        $this->activate( 'pixelyoursite/pixelyoursite.php' );

        $findings = $this->run_check( new TrackingPluginsCheck() );

        $this->assertSame( array( 'pixelyoursite:facebook', 'pixelyoursite:google' ), array_column( $findings, 'key' ) );
        $this->assertSame( 'PixelYourSite is also active and may send to Google', $findings[1]['found'] );
        $this->assertSame( array( 'unsure', array( 'Google', 'Not sure it sends purchases' ) ), array( $findings[1]['confidence'], $findings[1]['tags'] ) );
    }

    public function test_pixel_manager_is_read_per_platform() {
        $this->platforms(
            array(
                'facebook' => array( 'pixel_id' => '123456789012345' ),
                'google'   => array( 'mode' => 'ads', 'ads_id' => 'AW-123', 'purchase_label' => 'abc' ),
            )
        );
        $this->activate( 'woocommerce-google-adwords-conversion-tracking-tag/wgact.php' );
        $this->option(
            'wgact_plugin_options',
            array(
                'facebook' => array( 'pixel_id' => '' ),
                'google'   => array( 'ads' => array( 'conversion_id' => '123456' ) ),
            )
        );

        $findings = $this->run_check( new TrackingPluginsCheck() );

        $this->assertSame( array( 'pixel-manager:google' ), array_column( $findings, 'key' ), 'Its Meta pixel is empty' );
        $this->assertSame( 'sure', $findings[0]['confidence'] );
    }

    public function test_google_plugins_read_their_own_settings() {
        $this->platforms( array( 'google' => array( 'mode' => 'analytics', 'measurement_id' => 'G-ABC123' ) ) );
        $this->activate( 'google-site-kit/google-site-kit.php', 'woocommerce-google-analytics-integration/woocommerce-google-analytics-integration.php', 'google-listings-and-ads/google-listings-and-ads.php' );

        // Site Kit without Analytics or Ads, GA for WooCommerce with purchase tracking off, Google for WooCommerce without an Ads conversion.
        $this->option( 'googlesitekit_active_modules', array( 'search-console' ) );
        $this->option( 'woocommerce_google_analytics_settings', array( 'ga_id' => 'G-OTHER', 'ga_ecommerce_tracking_enabled' => 'no' ) );

        $this->assertSame( array(), $this->run_check( new TrackingPluginsCheck() ) );

        $this->option( 'googlesitekit_active_modules', array( 'search-console', 'analytics-4' ) );
        $this->option( 'woocommerce_google_analytics_settings', array( 'ga_id' => 'G-OTHER', 'ga_ecommerce_tracking_enabled' => 'yes' ) );
        $this->option( 'gla_ads_conversion_action', array( 'id' => '123' ) );

        $findings = $this->run_check( new TrackingPluginsCheck() );

        $this->assertSame( array( 'google-site-kit:google', 'google-listings-and-ads:google', 'woocommerce-google-analytics:google' ), array_column( $findings, 'key' ) );
        $this->assertSame( array( 'unsure', 'sure', 'sure' ), array_column( $findings, 'confidence' ) );
    }

    public function test_pixelyoursite_pro_stands_in_for_the_free_plugin() {
        $this->meta_connected();
        $this->activate( 'pixelyoursite/pixelyoursite.php', 'pixelyoursite-pro/pixelyoursite-pro.php' );

        $this->assertSame( array( 'pixelyoursite-pro:facebook' ), array_column( $this->run_check( new TrackingPluginsCheck() ), 'key' ) );
    }

    public function test_nothing_when_no_tracking_plugin_is_active() {
        $this->meta_connected();
        $this->activate( 'woocommerce/woocommerce.php', 'hello.php' );

        $this->assertSame( array(), $this->run_check( new TrackingPluginsCheck() ) );
        $this->assertSame( 'No other tracking plugin sends to your platforms', ( new TrackingPluginsCheck() )->label() );
    }

    public function test_not_applicable_when_we_do_not_send_to_that_platform() {
        $this->activate( self::META, 'google-site-kit/google-site-kit.php' );
        $this->option( 'wc_facebook_pixel_id', '999999999999999' );

        // Nothing connected.
        $this->platforms( array() );
        $this->assertSame( array(), $this->run_check( new TrackingPluginsCheck() ) );

        // Meta connected but paused.
        $this->meta_connected();
        $this->option( 'wcct_paused_platforms', array( 'facebook' ) );
        $this->assertSame( array(), $this->run_check( new TrackingPluginsCheck() ) );
    }

    public function test_plugins_are_found_by_class_or_constant_and_the_list_is_filterable() {
        $this->meta_connected();
        $this->filter(
            'wcct_doctor_tracking_plugins',
            function () {
                return array(
                    'by-class'   => array( 'name' => 'By class', 'classes' => array( 'TrackingPluginsCheckTest' ), 'platforms' => array( 'facebook' ) ),
                    'by-const'   => array( 'name' => 'By constant', 'constants' => array( 'PHP_VERSION' ), 'platforms' => array( 'facebook' ), 'sending' => function () { return true; } ),
                    'not-loaded' => array( 'name' => 'Not loaded', 'classes' => array( 'No_Such_Class_116' ), 'platforms' => array( 'facebook' ) ),
                );
            }
        );

        $findings = $this->run_check( new TrackingPluginsCheck() );

        $this->assertSame( array( 'by-class:facebook', 'by-const:facebook' ), array_column( $findings, 'key' ) );
        $this->assertSame( array( 'unsure', 'sure' ), array_column( $findings, 'confidence' ) );
    }
}
