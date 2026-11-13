<?php

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Migration\Lock;

/**
 * Tests for the migration lock
 */
class LockTest extends TestCase {

    private function clock( &$now ) {
        return function () use ( &$now ) {
            return $now;
        };
    }

    public function test_first_caller_takes_the_lock_and_second_is_refused() {
        $now   = 1000;
        $store = new InMemoryOptionStore();
        $first = new Lock( $store, 'lock', 600, $this->clock( $now ) );
        $other = new Lock( $store, 'lock', 600, $this->clock( $now ) );

        $this->assertTrue( $first->acquire() );
        $this->assertFalse( $other->acquire() );
        $this->assertArrayHasKey( 'lock', $store->options );
    }

    public function test_release_frees_the_lock() {
        $store = new InMemoryOptionStore();
        $first = new Lock( $store, 'lock' );

        $first->acquire();
        $first->release();

        $this->assertArrayNotHasKey( 'lock', $store->options );
        $this->assertTrue( ( new Lock( $store, 'lock' ) )->acquire() );
    }

    public function test_two_callers_that_both_saw_no_lock_get_one_winner() {
        $store = new InMemoryOptionStore();
        $first = new Lock( $store, 'lock' );
        $other = new Lock( $store, 'lock' );

        $this->assertTrue( $first->acquire() );

        // The second caller read "missing" before the first inserted; the insert decides.
        $store->on_read = function () {
            return false;
        };
        $this->assertFalse( $other->acquire() );
    }

    public function test_lock_is_taken_over_after_the_timeout() {
        $now   = 1000;
        $store = new InMemoryOptionStore();
        $dead  = new Lock( $store, 'lock', 600, $this->clock( $now ) );
        $dead->acquire();

        $now   = 1599;
        $early = new Lock( $store, 'lock', 600, $this->clock( $now ) );
        $this->assertFalse( $early->acquire() );

        $now  = 1600;
        $late = new Lock( $store, 'lock', 600, $this->clock( $now ) );
        $this->assertTrue( $late->acquire() );
        $this->assertSame( 1600, (int) $store->options['lock'] );
    }

    public function test_only_one_caller_takes_over_a_stale_lock() {
        $now   = 5000;
        $store = new InMemoryOptionStore();
        $store->options['lock'] = '1000|stale';

        $first = new Lock( $store, 'lock', 600, $this->clock( $now ) );
        $other = new Lock( $store, 'lock', 600, $this->clock( $now ) );

        $this->assertTrue( $first->acquire() );

        // The second caller read the stale value before the first swapped it.
        $store->on_read = function () {
            return '1000|stale';
        };
        $this->assertFalse( $other->acquire() );
    }

    public function test_garbage_lock_value_counts_as_stale() {
        $store = new InMemoryOptionStore();
        $store->options['lock'] = 'not-a-time';

        $this->assertTrue( ( new Lock( $store, 'lock' ) )->acquire() );
    }

    public function test_release_does_not_delete_a_lock_taken_over_by_someone_else() {
        $now   = 1000;
        $store = new InMemoryOptionStore();
        $slow  = new Lock( $store, 'lock', 600, $this->clock( $now ) );
        $slow->acquire();

        $now  = 2000;
        $next = new Lock( $store, 'lock', 600, $this->clock( $now ) );
        $next->acquire();
        $held = $store->options['lock'];

        $slow->release();

        $this->assertSame( $held, $store->options['lock'] );
    }

    public function test_release_without_acquire_does_nothing() {
        $store = new InMemoryOptionStore();
        $store->options['lock'] = '1000|other';

        ( new Lock( $store, 'lock' ) )->release();

        $this->assertSame( '1000|other', $store->options['lock'] );
    }
}
