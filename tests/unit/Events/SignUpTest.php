<?php

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Events\SignUp;

/**
 * Tests for the sign-up flag lifecycle
 */
class SignUpTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['wcct_test_user_meta'] = array();
        $GLOBALS['wcct_test_filters']   = array();
    }

    protected function tearDown(): void {
        unset( $GLOBALS['wcct_test_user_meta'], $GLOBALS['wcct_test_filters'], $GLOBALS['wcct_test_is_admin'], $GLOBALS['wcct_test_doing_ajax'] );
    }

    public function test_a_new_account_is_flagged() {
        $this->assertFalse( SignUp::is_pending( 7 ) );

        SignUp::flag( 7 );

        $this->assertTrue( SignUp::is_pending( 7 ) );
        $this->assertFalse( SignUp::is_pending( 8 ) );
    }

    public function test_take_clears_the_flag_and_succeeds_once() {
        SignUp::flag( 7 );

        $this->assertTrue( SignUp::take( 7 ) );
        $this->assertFalse( SignUp::is_pending( 7 ) );
        // The next page view sends nothing.
        $this->assertFalse( SignUp::take( 7 ) );
    }

    public function test_users_without_the_flag_send_nothing() {
        $this->assertFalse( SignUp::take( 7 ) );
        $this->assertFalse( SignUp::take( 0 ) );
        $this->assertFalse( SignUp::take( 'x' ) );
    }

    public function test_invalid_users_are_not_flagged() {
        SignUp::flag( 0 );
        SignUp::flag( 'x' );
        SignUp::flag( -3 );

        $this->assertSame( array(), $GLOBALS['wcct_test_user_meta'] );
    }

    public function test_a_pending_sign_up_expires_after_seven_days() {
        update_user_meta( 7, SignUp::META_KEY, time() - SignUp::TTL - 60 );

        $this->assertFalse( SignUp::take( 7 ) );
        // The stale flag is cleared too.
        $this->assertFalse( SignUp::is_pending( 7 ) );

        update_user_meta( 8, SignUp::META_KEY, time() - SignUp::TTL + 60 );

        $this->assertTrue( SignUp::take( 8 ) );
    }

    public function test_a_flag_without_a_time_is_not_sent() {
        update_user_meta( 7, SignUp::META_KEY, 'yes' );

        $this->assertFalse( SignUp::take( 7 ) );
        $this->assertFalse( SignUp::is_pending( 7 ) );
    }

    public function test_accounts_made_in_wp_admin_are_not_flagged() {
        $GLOBALS['wcct_test_is_admin'] = true;

        SignUp::flag( 7 );

        $this->assertFalse( SignUp::is_pending( 7 ) );
    }

    public function test_accounts_made_over_admin_ajax_are_flagged() {
        $GLOBALS['wcct_test_is_admin']   = true;
        $GLOBALS['wcct_test_doing_ajax'] = true;

        SignUp::flag( 7 );

        $this->assertTrue( SignUp::is_pending( 7 ) );
    }

    public function test_the_filter_can_flag_an_admin_made_account() {
        $GLOBALS['wcct_test_is_admin']                      = true;
        $GLOBALS['wcct_test_filters']['wcct_track_sign_up'] = function ( $track ) {
            return true;
        };

        SignUp::flag( 7 );

        $this->assertTrue( SignUp::is_pending( 7 ) );
    }

    public function test_the_filter_can_skip_an_account() {
        $GLOBALS['wcct_test_filters']['wcct_track_sign_up'] = function ( $track, $user_id ) {
            return 9 !== $user_id;
        };

        SignUp::flag( 9 );
        SignUp::flag( 10 );

        $this->assertFalse( SignUp::is_pending( 9 ) );
        $this->assertTrue( SignUp::is_pending( 10 ) );
    }
}
