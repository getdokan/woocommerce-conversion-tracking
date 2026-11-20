<?php

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Events\SentFlags;

/**
 * Tests for the per-order, per-platform sent flags
 */
class SentFlagsTest extends TestCase {

    public function test_meta_key_per_platform() {
        $this->assertSame( '_wcct_sent_facebook', SentFlags::meta_key( 'facebook' ) );
        $this->assertSame( '_wcct_sent_bing_ads', SentFlags::meta_key( 'bing_ads' ) );
    }

    public function test_ga4_keeps_its_old_key() {
        $this->assertSame( '_wcct_ga4_tracked', SentFlags::meta_key( 'ga4' ) );
    }

    public function test_invalid_platform_has_no_key() {
        $this->assertSame( '', SentFlags::meta_key( '' ) );
        $this->assertSame( '', SentFlags::meta_key( '<>' ) );
        $this->assertSame( '', SentFlags::meta_key( array( 'facebook' ) ) );
        $this->assertSame( '_wcct_sent_facebook', SentFlags::meta_key( 'Face<book>' ) );
    }

    public function test_mark_sent_sets_the_flag_for_that_platform_only() {
        $order = new WCCT_Test_Fake_Order();

        $this->assertFalse( SentFlags::is_sent( $order, 'facebook' ) );
        $this->assertTrue( SentFlags::mark_sent( $order, 'facebook' ) );
        $this->assertTrue( SentFlags::is_sent( $order, 'facebook' ) );
        $this->assertFalse( SentFlags::is_sent( $order, 'twitter' ) );
        $this->assertSame( 1, $order->saves );
    }

    public function test_mark_sent_twice_writes_once() {
        $order = new WCCT_Test_Fake_Order();

        SentFlags::mark_sent( $order, 'twitter' );

        $this->assertFalse( SentFlags::mark_sent( $order, 'twitter' ) );
        $this->assertSame( 1, $order->saves );
    }

    public function test_old_ga4_flag_counts_as_sent() {
        $order       = new WCCT_Test_Fake_Order();
        $order->meta = array( '_wcct_ga4_tracked' => 1 );

        $this->assertTrue( SentFlags::is_sent( $order, 'ga4' ) );
    }

    public function test_clear_removes_the_flag() {
        $order = new WCCT_Test_Fake_Order();

        SentFlags::mark_sent( $order, 'adwords' );
        SentFlags::clear( $order, 'adwords' );

        $this->assertFalse( SentFlags::is_sent( $order, 'adwords' ) );
    }

    public function test_missing_order_is_never_sent_and_never_written() {
        $this->assertFalse( SentFlags::is_sent( false, 'facebook' ) );
        $this->assertFalse( SentFlags::mark_sent( null, 'facebook' ) );
        SentFlags::clear( 'x', 'facebook' );
        $this->addToAssertionCount( 1 );
    }
}
