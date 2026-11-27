<?php

require_once dirname( __DIR__ ) . '/Fakes/settings-functions.php';

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Onboarding\State;

/**
 * Tests for the setup wizard progress
 */
class StateTest extends TestCase {

    const IDS = array( 'facebook', 'google', 'twitter', 'custom' );

    protected function setUp(): void {
        $GLOBALS['wcct_test_options'] = array();
    }

    protected function tearDown(): void {
        unset( $GLOBALS['wcct_test_options'] );
    }

    public function test_a_new_store_starts_before_the_first_step() {
        $this->assertSame( array( 'status' => 'new', 'step' => 0, 'platforms' => array(), 'eu' => '' ), State::load( self::IDS ) );
    }

    public function test_malformed_stored_values_fall_back_to_defaults() {
        $state = State::normalize( array( 'status' => 'bogus', 'step' => 99, 'platforms' => array( 'facebook', 'bing_ads', 3, 'facebook' ), 'eu' => 'maybe' ), self::IDS );

        $this->assertSame( array( 'status' => 'new', 'step' => 6, 'platforms' => array( 'facebook' ), 'eu' => '' ), $state );
        $this->assertSame( State::defaults(), State::normalize( 'garbage', self::IDS ) );
    }

    public function test_a_draft_saves_progress_and_keeps_the_furthest_step() {
        $result = State::apply( State::defaults(), array( 'step' => 3, 'platforms' => array( 'facebook', 'google' ), 'eu' => 'yes' ), self::IDS );

        $this->assertSame( array(), $result['errors'] );
        $this->assertSame( array( 'status' => 'in_progress', 'step' => 3, 'platforms' => array( 'facebook', 'google' ), 'eu' => 'yes' ), $result['state'] );

        // Going back a step doesn't lose the furthest one.
        $result = State::apply( $result['state'], array( 'step' => 2 ), self::IDS );
        $this->assertSame( 3, $result['state']['step'] );
        $this->assertSame( array( 'facebook', 'google' ), $result['state']['platforms'] );
    }

    public function test_skip_marks_the_wizard_skipped_and_keeps_the_draft() {
        $state  = array( 'status' => 'in_progress', 'step' => 4, 'platforms' => array( 'google' ), 'eu' => '' );
        $result = State::apply( $state, array( 'action' => 'skip', 'step' => 4 ), self::IDS );

        $this->assertSame( array( 'status' => 'skipped', 'step' => 4, 'platforms' => array( 'google' ), 'eu' => '' ), $result['state'] );

        // Continue setup picks the draft up again.
        $this->assertSame( 'in_progress', State::apply( $result['state'], array( 'step' => 5 ), self::IDS )['state']['status'] );
    }

    public function test_finish_marks_it_done_at_the_last_step() {
        $result = State::apply( State::defaults(), array( 'action' => 'finish', 'platforms' => array( 'twitter' ) ), self::IDS );

        $this->assertSame( array( 'status' => 'done', 'step' => 6, 'platforms' => array( 'twitter' ), 'eu' => '' ), $result['state'] );
    }

    public function test_a_finished_wizard_is_not_reopened_by_drafts_or_skips() {
        $done = array( 'status' => 'done', 'step' => 6, 'platforms' => array( 'facebook' ), 'eu' => 'no' );

        foreach ( array( 'save', 'skip', 'finish' ) as $action ) {
            $result = State::apply( $done, array( 'action' => $action, 'step' => 1, 'platforms' => array( 'google' ), 'eu' => 'yes' ), self::IDS );

            $this->assertSame( $done, $result['state'], $action );
            $this->assertSame( array(), $result['errors'], $action );
        }
    }

    public function test_invalid_values_are_reported_and_change_nothing() {
        $state  = State::defaults();
        $result = State::apply( $state, array( 'action' => 'reset', 'step' => 7, 'platforms' => array( 'facebook', 'myspace' ), 'eu' => 'maybe' ), self::IDS );

        $this->assertSame( array( 'action', 'step', 'platforms', 'eu' ), array_keys( $result['errors'] ) );
        $this->assertSame( $state, $result['state'] );

        $this->assertArrayHasKey( 'platforms', State::apply( $state, array( 'platforms' => array( array( 'facebook' ) ) ), self::IDS )['errors'] );
        $this->assertArrayHasKey( 'step', State::apply( $state, array( 'step' => '3' ), self::IDS )['errors'] );
    }

    public function test_save_writes_only_the_onboarding_option() {
        $GLOBALS['wcct_test_options']['wcct_settings_v3'] = array( 'consent_mode' => false );

        $result = State::apply( State::defaults(), array( 'step' => 4, 'eu' => 'yes' ), self::IDS );
        State::save( $result['state'] );

        $this->assertSame( array( 'wcct_settings_v3', 'wcct_onboarding' ), array_keys( $GLOBALS['wcct_test_options'] ) );
        $this->assertSame( array( 'consent_mode' => false ), $GLOBALS['wcct_test_options']['wcct_settings_v3'] );
        $this->assertSame( $result['state'], State::load( self::IDS ) );
    }
}
