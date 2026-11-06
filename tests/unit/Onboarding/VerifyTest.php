<?php

require_once dirname( __DIR__ ) . '/Fakes/settings-functions.php';

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Onboarding\Verify;

/**
 * Tests for the Verify step's seen ping
 */
class VerifyTest extends TestCase {

    const NOW   = 1767225600;
    const TOKEN = 'abcdefghijklmnopqrstuvwxyz012345';

    protected function setUp(): void {
        $GLOBALS['wcct_test_options'] = array(
            Verify::OPTION => array( 'token' => self::TOKEN, 'started' => self::NOW - 60, 'seen' => 0, 'platforms' => array() ),
        );
    }

    protected function tearDown(): void {
        unset( $GLOBALS['wcct_test_options'] );
    }

    public function test_only_the_open_tests_token_is_accepted() {
        $state = Verify::stored();

        $this->assertSame( Verify::OK, Verify::accept( $state, self::TOKEN, self::NOW ) );
        $this->assertSame( Verify::INVALID, Verify::accept( $state, 'wrong', self::NOW ) );
        $this->assertSame( Verify::INVALID, Verify::accept( $state, null, self::NOW ) );
        $this->assertSame( Verify::INVALID, Verify::accept( array( 'token' => '' ), '', self::NOW ) );
        $this->assertSame( Verify::EXPIRED, Verify::accept( $state, self::TOKEN, self::NOW + Verify::TTL ) );
    }

    public function test_seen_pings_are_throttled() {
        $this->assertSame( Verify::OK, Verify::record_seen( self::TOKEN, array( 'facebook' => '1', 'twitter' => '0', 'evil' => '1' ), array( 'facebook', 'twitter' ), self::NOW ) );
        $this->assertSame( Verify::THROTTLED, Verify::record_seen( self::TOKEN, array(), array( 'facebook' ), self::NOW + Verify::THROTTLE - 1 ) );
        $this->assertSame( Verify::OK, Verify::record_seen( self::TOKEN, array( 'facebook' => '1' ), array( 'facebook' ), self::NOW + Verify::THROTTLE ) );
    }

    public function test_the_state_lists_known_platforms_without_the_token() {
        Verify::record_seen( self::TOKEN, array( 'facebook' => '1', 'twitter' => '0', 'evil' => '1' ), array( 'facebook', 'twitter' ), self::NOW );

        $this->assertSame(
            array( 'active' => true, 'started_at' => self::NOW - 60, 'seen_at' => self::NOW, 'platforms' => array( 'facebook' => true, 'twitter' => false ) ),
            Verify::state( self::NOW )
        );
    }

    public function test_the_ping_sends_once_per_link_and_tab() {
        $script = Verify::ping_script( 'https://shop.test/wp-json/wcct/v1/events', self::TOKEN, array( '"facebook": function () { return window.fbq; }' ) );

        $this->assertStringContainsString( 'var memo = "wcct_verify_" + token', $script );
        $this->assertStringContainsString( 'started - last < 10000) return;', $script );
        $this->assertStringContainsString( '"type=seen&token="', $script );
    }
}
