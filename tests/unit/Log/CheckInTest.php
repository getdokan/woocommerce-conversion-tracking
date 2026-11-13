<?php

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Log\CheckIn;
use StorePulse\ConversionTracking\Log\OrderLog;

/**
 * Tests for the check-in fields and how a check-in changes a row
 */
class CheckInTest extends TestCase {

    const NOW = 1767225600;

    private function row( array $columns = array() ) {
        return array_merge(
            array(
                'library_loaded' => 0,
                'updated_at'     => OrderLog::datetime( self::NOW - 600 ),
            ),
            $columns
        );
    }

    public function test_fields_are_cleaned_to_known_values() {
        $this->assertSame(
            array( 'loaded' => true, 'consent' => 'denied', 'reason' => '', 'event_id' => 'wcct_9_purchase', 'load' => 'k3x9a' ),
            CheckIn::normalise( array( 'loaded' => '1', 'consent' => 'denied', 'reason' => 'timeout', 'event_id' => 'wcct_9_purchase', 'load' => 'k3x9a' ) )
        );
        $this->assertSame(
            array( 'loaded' => false, 'consent' => 'off', 'reason' => '', 'event_id' => 'scriptalert1', 'load' => 'ab' ),
            CheckIn::normalise( array( 'loaded' => 'yes', 'consent' => 'maybe', 'reason' => 'blocked', 'event_id' => '<script>alert(1)', 'load' => 'a-b' ) )
        );
        $this->assertSame( 64, strlen( CheckIn::normalise( array( 'event_id' => str_repeat( 'a', 100 ) ) )['event_id'] ) );
        $this->assertSame( 32, strlen( CheckIn::normalise( array( 'load' => str_repeat( 'b', 40 ) ) )['load'] ) );
        $this->assertSame( 'hidden', CheckIn::normalise( array( 'loaded' => 0, 'reason' => 'hidden' ) )['reason'] );
    }

    public function test_a_first_hand_over_writes_sent_at() {
        $this->assertSame(
            array(
                'library_loaded' => 1,
                'sent_at'        => '2026-01-01 00:00:00',
                'consent'        => 'granted',
                'reason'         => '',
                'event_id'       => 'wcct_9_purchase',
                'load_id'        => 'pageA1',
                'updated_at'     => '2026-01-01 00:00:00',
            ),
            CheckIn::merge( null, array( 'loaded' => true, 'consent' => 'granted', 'event_id' => 'wcct_9_purchase', 'load' => 'pageA1' ), self::NOW )
        );
        $this->assertNull( CheckIn::merge( null, array( 'loaded' => true ), self::NOW )['load_id'], 'No token from an old cached page' );
    }

    public function test_a_hand_over_from_another_page_load_is_a_repeat() {
        $sent = $this->row( array( 'library_loaded' => 1, 'load_id' => 'pageA1' ) );

        $this->assertTrue( CheckIn::is_repeat( $sent, array( 'loaded' => true, 'load' => 'pageB2' ) ), 'A second page load handed it over again' );
        $this->assertTrue( CheckIn::is_repeat( $this->row( array( 'library_loaded' => 1, 'load_id' => 'pageB2' ) ), array( 'loaded' => '1', 'load' => 'pageA1' ) ), 'An earlier miss, then two loads' );
    }

    public function test_the_admin_ajax_retry_after_a_lost_response_is_not_a_repeat() {
        // The REST check-in was recorded but its answer was lost, so the page retried the same check-in on admin-ajax.
        $sent = $this->row( array( 'library_loaded' => 1, 'load_id' => 'pageA1' ) );

        $this->assertFalse( CheckIn::is_repeat( $sent, array( 'loaded' => true, 'load' => 'pageA1' ) ) );
        $this->assertFalse( CheckIn::is_repeat( $sent, array( 'loaded' => false, 'load' => 'pageB2' ) ), 'A miss is never a repeat' );
        $this->assertFalse( CheckIn::is_repeat( $this->row( array( 'load_id' => 'pageA1' ) ), array( 'loaded' => true, 'load' => 'pageB2' ) ), 'Not handed over before' );
        $this->assertFalse( CheckIn::is_repeat( $this->row( array( 'library_loaded' => 1, 'load_id' => null ) ), array( 'loaded' => true, 'load' => 'pageB2' ) ), 'A row from before the token' );
        $this->assertFalse( CheckIn::is_repeat( $sent, array( 'loaded' => true ) ), 'An old cached page without a token' );
        $this->assertFalse( CheckIn::is_repeat( null, array( 'loaded' => true, 'load' => 'pageA1' ) ) );
    }

    public function test_a_first_miss_writes_the_reason_and_no_sent_at() {
        $changes = CheckIn::merge( null, array( 'loaded' => false, 'consent' => 'denied', 'reason' => 'timeout' ), self::NOW );

        $this->assertSame( 0, $changes['library_loaded'] );
        $this->assertNull( $changes['sent_at'] );
        $this->assertSame( 'timeout', $changes['reason'] );
        $this->assertSame( 'denied', $changes['consent'] );
    }

    public function test_a_handed_over_row_is_final() {
        $row = $this->row( array( 'library_loaded' => 1 ) );

        $this->assertNull( CheckIn::merge( $row, array( 'loaded' => false, 'reason' => 'hidden' ), self::NOW ) );
        $this->assertNull( CheckIn::merge( $row, array( 'loaded' => true ), self::NOW ) );
    }

    public function test_a_hand_over_after_a_miss_updates_the_row_at_once() {
        $changes = CheckIn::merge( $this->row( array( 'updated_at' => OrderLog::datetime( self::NOW - 1 ) ) ), array( 'loaded' => true ), self::NOW );

        $this->assertSame( 1, $changes['library_loaded'] );
        $this->assertSame( '', $changes['reason'] );
    }

    public function test_misses_are_throttled_per_row() {
        $this->assertNull( CheckIn::merge( $this->row( array( 'updated_at' => OrderLog::datetime( self::NOW - CheckIn::THROTTLE + 1 ) ) ), array( 'loaded' => false ), self::NOW ) );
        $this->assertNotNull( CheckIn::merge( $this->row( array( 'updated_at' => OrderLog::datetime( self::NOW - CheckIn::THROTTLE ) ) ), array( 'loaded' => false ), self::NOW ) );
    }

    public function test_a_timeout_after_a_hidden_page_is_not_throttled() {
        $row = $this->row( array( 'reason' => 'hidden', 'updated_at' => OrderLog::datetime( self::NOW - 5 ) ) );

        $this->assertSame( 'timeout', CheckIn::merge( $row, array( 'loaded' => false, 'reason' => 'timeout' ), self::NOW )['reason'] );
        $this->assertNull( CheckIn::merge( $this->row( array( 'reason' => 'timeout', 'updated_at' => OrderLog::datetime( self::NOW - 5 ) ) ), array( 'loaded' => false, 'reason' => 'hidden' ), self::NOW ) );
    }

    public function test_the_route_is_neutral() {
        $this->assertSame( 'wcct/v1/events', CheckIn::ROUTE );
        $this->assertDoesNotMatchRegularExpression( '/track|pixel|conversion|analytic|beacon|collect|purchase|facebook|google|meta|ads/i', CheckIn::ROUTE );
    }
}
