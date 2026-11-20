<?php

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Migration\Lock;
use StorePulse\ConversionTracking\Migration\SettingsMigration;
use StorePulse\ConversionTracking\Migration\Trigger;

/**
 * Tests for the state-based migration trigger
 */
class TriggerTest extends TestCase {

    /**
     * @var int
     */
    private $runs = 0;

    /**
     * @var callable|null
     */
    private $during_body = null;

    private function trigger( InMemoryOptionStore $store, $body = null ): Trigger {
        $body = $body ? $body : function () {
            $this->runs++;

            if ( $this->during_body ) {
                call_user_func( $this->during_body );
            }
        };

        return new Trigger( $store, new Lock( $store, SettingsMigration::LOCK ), SettingsMigration::MARKER, array( SettingsMigration::class, 'is_pending' ), $body, '3.0.0' );
    }

    private function upgrader_store(): InMemoryOptionStore {
        $store = new InMemoryOptionStore();
        $store->options[ SettingsMigration::V1_OPTION ] = 'a:0:{}';

        return $store;
    }

    public function test_fresh_install_writes_nothing_and_skips_the_body() {
        $store = new InMemoryOptionStore();

        $this->assertSame( Trigger::IDLE, $this->trigger( $store )->maybe_run() );
        $this->assertSame( 0, $this->runs );
        $this->assertSame( array(), $store->writes );
        $this->assertSame( array(), $store->options );
    }

    public function test_upgrader_runs_the_body_once_and_writes_the_marker() {
        $store = $this->upgrader_store();

        $this->assertSame( Trigger::RAN, $this->trigger( $store )->maybe_run() );
        $this->assertSame( Trigger::IDLE, $this->trigger( $store )->maybe_run() );

        $this->assertSame( 1, $this->runs );
        $this->assertSame( '3.0.0', $store->options[ SettingsMigration::MARKER ] );
        $this->assertArrayNotHasKey( SettingsMigration::LOCK, $store->options );
        $this->assertSame(
            array( array( 'insert', SettingsMigration::LOCK ), array( 'save', SettingsMigration::MARKER ), array( 'delete', SettingsMigration::LOCK ) ),
            $store->writes
        );
    }

    public function test_existing_v3_settings_mean_nothing_to_migrate() {
        $store = $this->upgrader_store();
        $store->options[ SettingsMigration::OPTION ] = 'a:0:{}';

        $this->assertSame( Trigger::IDLE, $this->trigger( $store )->maybe_run() );
        $this->assertSame( 0, $this->runs );
    }

    public function test_second_concurrent_caller_skips_while_the_first_runs() {
        $store  = $this->upgrader_store();
        $second = null;

        // The second request arrives while the first is inside the body.
        $this->during_body = function () use ( $store, &$second ) {
            $this->during_body = null;
            $second            = $this->trigger( $store )->maybe_run();
        };

        $this->assertSame( Trigger::RAN, $this->trigger( $store )->maybe_run() );
        $this->assertSame( Trigger::LOCKED, $second );
        $this->assertSame( 1, $this->runs );
    }

    public function test_caller_with_a_stale_cache_rechecks_after_taking_the_lock() {
        $store = $this->upgrader_store();
        $this->trigger( $store )->maybe_run();

        // A request that loaded its cache before the first one finished.
        $store->cache = array( SettingsMigration::V1_OPTION => 'a:0:{}' );

        $this->assertSame( Trigger::IDLE, $this->trigger( $store )->maybe_run() );
        $this->assertSame( 1, $this->runs );
        $this->assertArrayNotHasKey( SettingsMigration::LOCK, $store->options );
    }

    public function test_failed_body_writes_no_marker_and_keeps_the_lock_until_timeout() {
        $store   = $this->upgrader_store();
        $trigger = $this->trigger(
            $store,
            function () {
                throw new RuntimeException( 'bad data' );
            }
        );

        $this->assertSame( Trigger::FAILED, $trigger->maybe_run() );
        $this->assertSame( 'bad data', $trigger->error()->getMessage() );
        $this->assertArrayNotHasKey( SettingsMigration::MARKER, $store->options );
        $this->assertArrayHasKey( SettingsMigration::LOCK, $store->options );

        $this->assertSame( Trigger::LOCKED, $this->trigger( $store )->maybe_run() );
        $this->assertSame( 0, $this->runs );
    }

    public function test_marker_stops_the_trigger_even_if_v3_settings_are_missing() {
        $store = $this->upgrader_store();
        $store->options[ SettingsMigration::MARKER ] = '3.0.0';

        $this->assertFalse( $this->trigger( $store )->is_due() );
        $this->assertSame( Trigger::IDLE, $this->trigger( $store )->maybe_run() );
    }

    public function test_settings_migration_trigger_runs_its_empty_body_once() {
        $store = $this->upgrader_store();

        $this->assertSame( Trigger::RAN, SettingsMigration::trigger( $store )->maybe_run() );
        $this->assertSame( Trigger::IDLE, SettingsMigration::trigger( $store )->maybe_run() );
        $this->assertArrayHasKey( SettingsMigration::MARKER, $store->options );
        $this->assertArrayNotHasKey( SettingsMigration::OPTION, $store->options );
    }
}
