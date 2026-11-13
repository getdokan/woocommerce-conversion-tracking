<?php

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Events\PendingEvents;

/**
 * Tests for the per-visitor pending events queue
 */
class PendingEventsTest extends TestCase {

    public function test_add_keeps_code_per_platform_and_drops_empty_code() {
        $session = new WCCT_Test_Fake_Session();
        $queue   = new PendingEvents( $session, 1000 );

        $this->assertTrue( $queue->add( 'wcct_a', array( 'facebook' => 'fbq();', 'twitter' => '', 'ga4' => '  ', 'custom' => null ) ) );
        $this->assertSame( array( array( 'id' => 'wcct_a', 'scripts' => array( 'facebook' => 'fbq();' ) ) ), $queue->after( '' ) );
    }

    public function test_nothing_is_queued_without_code_an_id_or_a_session() {
        $session = new WCCT_Test_Fake_Session();

        $this->assertFalse( ( new PendingEvents( $session ) )->add( 'wcct_a', array( 'facebook' => '' ) ) );
        $this->assertFalse( ( new PendingEvents( $session ) )->add( '', array( 'facebook' => 'fbq();' ) ) );
        $this->assertFalse( ( new PendingEvents( null ) )->add( 'wcct_a', array( 'facebook' => 'fbq();' ) ) );
        $this->assertSame( array(), ( new PendingEvents( null ) )->after( '' ) );
        $this->assertSame( 0, $session->writes );
    }

    public function test_after_returns_only_entries_queued_after_the_seen_one() {
        $queue = new PendingEvents( new WCCT_Test_Fake_Session(), 1000 );

        $queue->add( 'wcct_a', array( 'facebook' => 'a' ) );
        $queue->add( 'wcct_b', array( 'facebook' => 'b' ) );
        $queue->add( 'wcct_c', array( 'facebook' => 'c' ) );

        $this->assertSame( array( 'wcct_b', 'wcct_c' ), array_column( $queue->after( 'wcct_a' ), 'id' ) );
        $this->assertSame( array(), $queue->after( 'wcct_c' ) );
        // An unknown seen ID (pruned, or another browser's) means none of these ran yet.
        $this->assertSame( array( 'wcct_a', 'wcct_b', 'wcct_c' ), array_column( $queue->after( 'wcct_gone' ), 'id' ) );
    }

    public function test_reading_does_not_write_the_session() {
        $session = new WCCT_Test_Fake_Session();
        $queue   = new PendingEvents( $session, 1000 );

        $queue->add( 'wcct_a', array( 'facebook' => 'a' ) );
        $queue->after( '' );
        $queue->after( 'wcct_a' );
        $queue->all();

        $this->assertSame( 1, $session->writes );
    }

    public function test_old_entries_expire() {
        $session = new WCCT_Test_Fake_Session();

        ( new PendingEvents( $session, 1000 ) )->add( 'wcct_old', array( 'facebook' => 'a' ) );
        ( new PendingEvents( $session, 1001 + PendingEvents::TTL ) )->add( 'wcct_new', array( 'facebook' => 'b' ) );

        $later = new PendingEvents( $session, 1001 + PendingEvents::TTL );

        $this->assertSame( array( 'wcct_new' ), array_column( $later->after( '' ), 'id' ) );
        $this->assertSame( array( 'wcct_new' ), array_column( $session->data[ PendingEvents::SESSION_KEY ], 'id' ) );
    }

    public function test_the_queue_is_capped() {
        $queue = new PendingEvents( new WCCT_Test_Fake_Session(), 1000 );

        for ( $i = 1; $i <= PendingEvents::MAX + 5; $i++ ) {
            $queue->add( 'wcct_' . $i, array( 'facebook' => 'x' ) );
        }

        $ids = array_column( $queue->all(), 'id' );

        $this->assertCount( PendingEvents::MAX, $ids );
        $this->assertSame( 'wcct_6', $ids[0] );
        $this->assertSame( 'wcct_' . ( PendingEvents::MAX + 5 ), end( $ids ) );
    }

    public function test_bad_session_data_is_ignored() {
        $session       = new WCCT_Test_Fake_Session();
        $session->data = array( PendingEvents::SESSION_KEY => array( 'junk', array( 'id' => 5 ), array( 'id' => 'wcct_a', 'time' => 1000, 'scripts' => 'x' ) ) );

        $this->assertSame( array(), ( new PendingEvents( $session, 1000 ) )->after( '' ) );

        $session->data = array( PendingEvents::SESSION_KEY => 'junk' );

        $this->assertSame( array(), ( new PendingEvents( $session, 1000 ) )->all() );
    }
}
