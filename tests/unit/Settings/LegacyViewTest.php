<?php

require_once dirname( __DIR__ ) . '/Fakes/settings-functions.php';

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Settings\LegacyView;
use StorePulse\ConversionTracking\Settings\Schema;
use StorePulse\ConversionTracking\Settings\Values;

/**
 * Tests for reading the 2.x settings as 3.0 settings
 */
class LegacyViewTest extends TestCase {

    protected function tearDown(): void {
        unset( $GLOBALS['wcct_test_filters'] );
    }

    public function test_lite_platforms_are_read() {
        $view = LegacyView::from_v1(
            array(
                'facebook'         => array( 'enabled' => true, 0 => array( 'pixel_id' => '123', 'events' => array( 'AddToCart' => 'on', 'Registration' => 'on', 'Purchase' => '' ) ) ),
                'twitter'          => array( 'enabled' => true, 0 => array( 'events' => array( 'Purchase' => 'on', 'universal_tag_id' => 'o8x2k', 'AddtoCart-label' => 'old' ) ) ),
                'custom'           => array( 'enabled' => true, 'checkout' => '<script>a()</script>', 'registration' => '' ),
                'perfect_audience' => array( 'enabled' => true, 0 => array( 'advertiser_id' => '1' ) ),
            )
        );

        $this->assertSame(
            array(
                'facebook' => array( 'pixel_id' => '123', 'events' => array( 'add_to_cart', 'sign_up' ), 'paused' => false ),
                'twitter'  => array(
                    'pixel_id'          => 'o8x2k',
                    'events'            => array( 'page_view', 'purchase' ),
                    'purchase_event_id' => '',
                    'cart_event_id'     => '',
                    'checkout_event_id' => '',
                    'signup_event_id'   => '',
                    'needs_resetup'     => array( 'purchase', 'add_to_cart' ),
                    'paused'            => false,
                ),
                'custom'   => array(
                    'thankyou_enabled' => true,
                    'thankyou_script'  => '<script>a()</script>',
                    'signup_enabled'   => false,
                    'signup_script'    => '',
                    'customer_details' => false,
                ),
            ),
            $view['platforms']
        );
    }

    public function test_a_switched_off_integration_with_an_id_reads_as_paused() {
        $view = LegacyView::from_v1(
            array(
                'facebook' => array( 'enabled' => false, 0 => array( 'pixel_id' => '123456789012345' ) ),
                'twitter'  => array( 'enabled' => false, 0 => array( 'events' => array( 'universal_tag_id' => '' ) ) ),
                'ga4'      => array( 'enabled' => false, 0 => array( 'measurement_id' => 'G-1' ) ),
                'adwords'  => array( 'enabled' => false ),
            )
        );

        $this->assertTrue( $view['platforms']['facebook']['paused'] );
        $this->assertFalse( $view['platforms']['twitter']['paused'] );
        $this->assertTrue( $view['platforms']['google']['paused'] );
    }

    public function test_a_disabled_custom_integration_keeps_its_scripts_off() {
        $view = LegacyView::from_v1( array( 'custom' => array( 'enabled' => false, 'checkout' => 'x', 'registration' => 'y' ) ) );

        $this->assertFalse( $view['platforms']['custom']['thankyou_enabled'] );
        $this->assertFalse( $view['platforms']['custom']['signup_enabled'] );
        $this->assertSame( 'x', $view['platforms']['custom']['thankyou_script'] );
    }

    public function test_customer_details_start_on_only_for_an_upgrader_whose_checkout_script_uses_them() {
        $values = function ( $v1 ) {
            return Values::get( Schema::get(), LegacyView::from_v1( $v1 ) )['platforms']['custom']['customer_details'];
        };

        // New install: nothing saved.
        $this->assertFalse( $values( false ) );
        $this->assertFalse( $values( array( 'custom' => array( 'enabled' => true, 'checkout' => '<script>t("{order_total}")</script>', 'registration' => '' ) ) ) );
        // 2.x never filled them in the registration script.
        $this->assertFalse( $values( array( 'custom' => array( 'enabled' => true, 'checkout' => '', 'registration' => '{customer_email}' ) ) ) );
        $this->assertTrue( $values( array( 'custom' => array( 'enabled' => true, 'checkout' => '<script>t("{customer_email}")</script>' ) ) ) );
        $this->assertTrue( $values( array( 'custom' => array( 'enabled' => false, 'checkout' => '{customer_last_name}' ) ) ) );
        $this->assertTrue( LegacyView::uses_customer_placeholder( 'id={customer_id}' ) );
        $this->assertFalse( LegacyView::uses_customer_placeholder( '{customer_phone} {order_id}' ) );
    }

    public function test_nothing_saved_reads_as_nothing() {
        $this->assertSame( array(), LegacyView::from_v1( false ) );
        $this->assertSame( array(), LegacyView::from_v1( array() ) );
    }

    public function test_pro_adds_its_part_through_the_filter() {
        $GLOBALS['wcct_test_filters']['wcct_settings_v1_view'] = function ( $view, $v1 ) {
            $view['platforms']['bing_ads'] = array( 'tag_id' => $v1['bing_ads'][0]['tag_id'] );

            return $view;
        };

        $view = LegacyView::from_v1( array( 'bing_ads' => array( 0 => array( 'tag_id' => '1234567' ) ) ) );

        $this->assertSame( array( 'bing_ads' => array( 'tag_id' => '1234567' ) ), $view['platforms'] );
    }
}
