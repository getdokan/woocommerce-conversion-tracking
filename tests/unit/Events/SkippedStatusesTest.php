<?php

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Events\SkippedStatuses;

/**
 * Tests for the skipped order statuses
 */
class SkippedStatusesTest extends TestCase {

    protected function tearDown(): void {
        unset( $GLOBALS['wcct_test_filters'] );
    }

    public function test_failed_and_cancelled_are_skipped_by_default() {
        $this->assertSame( array( 'failed', 'cancelled' ), SkippedStatuses::get() );
        $this->assertTrue( SkippedStatuses::is_skipped( 'failed' ) );
        $this->assertTrue( SkippedStatuses::is_skipped( 'wc-cancelled' ) );
        $this->assertFalse( SkippedStatuses::is_skipped( 'processing' ) );
        $this->assertFalse( SkippedStatuses::is_skipped( 'pending' ) );
        $this->assertFalse( SkippedStatuses::is_skipped( 'on-hold' ) );
    }

    public function test_the_list_is_filterable() {
        $GLOBALS['wcct_test_filters']['wcct_skipped_order_statuses'] = function ( $statuses ) {
            $statuses[] = 'wc-pending';

            return $statuses;
        };

        $this->assertTrue( SkippedStatuses::is_skipped( 'pending' ) );
        $this->assertTrue( SkippedStatuses::is_skipped( 'failed' ) );
    }

    public function test_a_filter_can_skip_nothing() {
        $GLOBALS['wcct_test_filters']['wcct_skipped_order_statuses'] = function () {
            return array();
        };

        $this->assertFalse( SkippedStatuses::is_skipped( 'failed' ) );
    }

    public function test_a_broken_filter_falls_back_to_the_defaults() {
        $GLOBALS['wcct_test_filters']['wcct_skipped_order_statuses'] = function () {
            return 'failed';
        };

        $this->assertSame( SkippedStatuses::DEFAULTS, SkippedStatuses::get() );
    }

    public function test_normalise_cleans_the_list() {
        $this->assertSame( array( 'failed', 'refunded' ), SkippedStatuses::normalise( array( ' WC-Failed ', 'failed', 3, null, '', 'refunded' ) ) );
    }

    public function test_bad_status_is_not_skipped() {
        $this->assertFalse( SkippedStatuses::is_skipped( null, array( 'failed' ) ) );
        $this->assertFalse( SkippedStatuses::is_skipped( array( 'failed' ), array( 'failed' ) ) );
    }
}
