<?php

require_once __DIR__ . '/DoctorCheckTestCase.php';

use StorePulse\ConversionTracking\Doctor\Checks\PurchaseOnCheck;

/**
 * Purchase on for every connected platform
 */
class PurchaseOnCheckTest extends DoctorCheckTestCase {

    public function test_a_connected_platform_with_purchase_off_gives_a_finding() {
        $this->platforms(
            array(
                'facebook' => array( 'pixel_id' => '123456789012345', 'events' => array( 'view_product', 'add_to_cart' ) ),
                'google'   => array( 'mode' => 'analytics', 'measurement_id' => 'G-ABC123' ),
            )
        );

        $findings = $this->run_check( new PurchaseOnCheck() );

        $this->assertCount( 1, $findings );
        $this->assertSame( 'Purchase is off for Meta', $findings[0]['found'] );
        $this->assertSame( array( 'link', 'Open Meta', '#/platforms/facebook' ), array( $findings[0]['action']['type'], $findings[0]['action']['label'], $findings[0]['action']['url'] ) );
        $this->assertSame( array( 'sure', 'warning', array( 'Meta' ) ), array( $findings[0]['confidence'], $findings[0]['severity'], $findings[0]['tags'] ) );
    }

    public function test_purchase_on_everywhere_gives_nothing() {
        $this->platforms(
            array(
                'facebook' => array( 'pixel_id' => '123456789012345' ),
                'twitter'  => array( 'pixel_id' => 'o8x2k', 'events' => array( 'purchase' ) ),
            )
        );

        $this->assertSame( array(), $this->run_check( new PurchaseOnCheck() ) );
        $this->assertSame( 'Purchase is on for every connected platform', ( new PurchaseOnCheck() )->label() );
    }

    public function test_not_applicable_to_platforms_that_are_not_connected_or_paused() {
        $this->platforms(
            array(
                'facebook' => array( 'pixel_id' => '', 'events' => array() ),
                // Ads fields don't apply in Analytics mode, so an Ads ID alone doesn't connect it.
                'google'   => array( 'mode' => 'analytics', 'measurement_id' => '', 'ads_id' => 'AW-1', 'events' => array() ),
                'twitter'  => array( 'pixel_id' => 'o8x2k', 'events' => array(), 'paused' => true ),
                'custom'   => array( 'thankyou_enabled' => true, 'thankyou_script' => '<script></script>' ),
            )
        );

        $this->assertSame( array(), $this->run_check( new PurchaseOnCheck() ) );

        $this->platforms( array( 'facebook' => array( 'pixel_id' => '123456789012345', 'events' => array() ) ) );
        $this->option( 'wcct_paused_platforms', array( 'facebook' ) );

        $this->assertSame( array(), $this->run_check( new PurchaseOnCheck() ) );
    }
}
