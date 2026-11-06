<?php

require_once dirname( __DIR__ ) . '/Fakes/settings-functions.php';

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Log\LastPurchase;

/**
 * Tests for the per-platform last purchase
 */
class LastPurchaseTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['wcct_test_options'] = array();
    }

    protected function tearDown(): void {
        unset( $GLOBALS['wcct_test_options'] );
    }

    public function test_one_option_per_platform() {
        $this->assertSame( 'wcct_last_purchase_facebook', LastPurchase::option( 'facebook' ) );
        $this->assertSame( 'wcct_last_purchase_bing_ads', LastPurchase::option( 'Bing_Ads' ) );
        $this->assertSame( '', LastPurchase::option( '<>' ) );
    }

    public function test_record_and_read() {
        $this->assertNull( LastPurchase::get( 'facebook' ) );
        $this->assertTrue( LastPurchase::record( 'facebook', new WCCT_Test_Fake_Order( array( 'id' => 1312 ) ), 1767225600 ) );

        $this->assertSame( array( 'last_purchase_at' => 1767225600, 'order_id' => 1312, 'order_number' => '1312' ), LastPurchase::get( 'facebook' ) );
        $this->assertNull( LastPurchase::get( 'twitter' ) );
    }

    public function test_bad_input_writes_nothing() {
        $this->assertFalse( LastPurchase::record( '', new WCCT_Test_Fake_Order(), 1 ) );
        $this->assertFalse( LastPurchase::record( 'facebook', null, 1 ) );
        $this->assertSame( array(), $GLOBALS['wcct_test_options'] );
    }
}
