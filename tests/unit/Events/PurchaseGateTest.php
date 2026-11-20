<?php

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Events\PurchaseGate;
use StorePulse\ConversionTracking\Events\SentFlags;

/**
 * Tests for the once-per-order Purchase decision
 */
class PurchaseGateTest extends TestCase {

    protected function tearDown(): void {
        unset( $GLOBALS['wcct_test_options'], $GLOBALS['wcct_test_filters'] );
    }

    private function order( array $props = array() ) {
        return new WCCT_Test_Fake_Order( array_merge( array( 'id' => 10, 'created' => 2000 ), $props ) );
    }

    public function test_paid_order_sends() {
        $this->assertTrue( PurchaseGate::should_send( $this->order(), 'facebook' ) );
        $this->assertTrue( PurchaseGate::should_send( $this->order( array( 'status' => 'completed' ) ), 'facebook' ) );
    }

    public function test_failed_and_cancelled_orders_never_send() {
        foreach ( array( 'failed', 'cancelled', 'wc-failed' ) as $status ) {
            $this->assertFalse( PurchaseGate::should_send( $this->order( array( 'status' => $status ) ), 'facebook' ), $status );
        }
    }

    public function test_skipped_statuses_follow_their_filter() {
        $GLOBALS['wcct_test_filters']['wcct_skipped_order_statuses'] = function () {
            return array( 'failed' );
        };

        $this->assertTrue( PurchaseGate::should_send( $this->order( array( 'status' => 'cancelled' ) ), 'facebook' ) );
    }

    public function test_pending_and_on_hold_send_by_default() {
        $this->assertTrue( PurchaseGate::should_send( $this->order( array( 'status' => 'pending' ) ), 'twitter' ) );
        $this->assertTrue( PurchaseGate::should_send( $this->order( array( 'status' => 'on-hold' ) ), 'twitter' ) );
        $this->assertTrue( PurchaseGate::is_unpaid_status( 'wc-pending' ) );
        $this->assertFalse( PurchaseGate::is_unpaid_status( 'processing' ) );
    }

    public function test_unpaid_statuses_are_filterable() {
        $GLOBALS['wcct_test_filters']['wcct_unpaid_order_statuses'] = function () {
            return array( 'wc-on-hold' );
        };

        $this->assertFalse( PurchaseGate::should_send( $this->order( array( 'status' => 'pending' ) ), 'twitter' ) );
        $this->assertTrue( PurchaseGate::should_send( $this->order( array( 'status' => 'on-hold' ) ), 'twitter' ) );
        $this->assertTrue( PurchaseGate::is_unpaid_status( 'pending' ) );
    }

    public function test_broken_unpaid_filter_keeps_the_defaults() {
        $GLOBALS['wcct_test_filters']['wcct_unpaid_order_statuses'] = function () {
            return 'pending';
        };

        $this->assertSame( array( 'pending', 'on-hold' ), PurchaseGate::unpaid_statuses() );
    }

    public function test_sent_platform_does_not_send_again_but_others_do() {
        $order = $this->order();

        SentFlags::mark_sent( $order, 'facebook' );

        $this->assertFalse( PurchaseGate::should_send( $order, 'facebook' ) );
        $this->assertTrue( PurchaseGate::should_send( $order, 'adwords' ) );
    }

    public function test_old_ga4_flag_stops_ga4() {
        $this->assertFalse( PurchaseGate::should_send( $this->order( array( 'meta' => array( '_wcct_ga4_tracked' => 1 ) ) ), 'ga4' ) );
    }

    public function test_orders_created_before_the_cutoff_count_as_sent() {
        $GLOBALS['wcct_test_options']['wcct_count_once_since'] = 2000;

        $this->assertFalse( PurchaseGate::should_send( $this->order( array( 'created' => 1999 ) ), 'facebook' ) );
        $this->assertTrue( PurchaseGate::should_send( $this->order( array( 'created' => 2000 ) ), 'facebook' ) );
        $this->assertTrue( PurchaseGate::should_send( $this->order( array( 'created' => null ) ), 'facebook' ) );
    }

    public function test_no_cutoff_counts_nothing_as_sent() {
        $this->assertFalse( PurchaseGate::counted_before( $this->order( array( 'created' => 1 ) ) ) );
    }

    public function test_cutoff_is_written_once() {
        $first = PurchaseGate::ensure_since();

        $this->assertGreaterThan( 0, $first );
        $this->assertSame( $first, $GLOBALS['wcct_test_options']['wcct_count_once_since'] );

        $GLOBALS['wcct_test_options']['wcct_count_once_since'] = 123;

        $this->assertSame( 123, PurchaseGate::ensure_since() );
    }

    public function test_not_an_order_never_sends() {
        $this->assertFalse( PurchaseGate::should_send( null, 'facebook' ) );
        $this->assertFalse( PurchaseGate::should_send( new stdClass(), 'facebook' ) );
    }
}
