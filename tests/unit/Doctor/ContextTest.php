<?php

require_once dirname( __DIR__ ) . '/Fakes/doctor-functions.php';

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Doctor\Context;

/**
 * Tests for the run context: the time budget and the cookie-less loopback a remote check uses
 */
class ContextTest extends TestCase {

    protected function tearDown(): void {
        unset( $GLOBALS['wcct_test_options'], $GLOBALS['wcct_test_filters'] );
    }

    public function test_loopback_posts_json_without_cookies_and_with_a_timeout() {
        $calls   = array();
        $context = new Context(
            20,
            function ( $url, $args ) use ( &$calls ) {
                $calls[] = array( $url, $args );

                return array(
                    'response' => array( 'code' => 204 ),
                    'body'     => '',
                );
            }
        );

        $result = $context->loopback( 'post', '/wcct/v1/events', array( 'body' => array( 'event' => 'doctor' ) ) );

        $this->assertSame( array( 'code' => 204, 'body' => '', 'error' => '' ), $result );
        $this->assertSame( 'https://shop.test/wp-json/wcct/v1/events', $calls[0][0] );
        $this->assertSame( 'POST', $calls[0][1]['method'] );
        $this->assertSame( array(), $calls[0][1]['cookies'] );
        $this->assertSame( 0, $calls[0][1]['redirection'] );
        $this->assertEqualsWithDelta( 5, $calls[0][1]['timeout'], 0.01 );
        $this->assertSame( '{"event":"doctor"}', $calls[0][1]['body'] );
        $this->assertSame( 'application/json', $calls[0][1]['headers']['Content-Type'] );
    }

    public function test_the_timeout_never_goes_past_the_budget() {
        $timeouts = array();
        $context  = new Context(
            2,
            function ( $url, $args ) use ( &$timeouts ) {
                $timeouts[] = $args['timeout'];

                return array( 'response' => array( 'code' => 200 ) );
            }
        );

        $context->loopback( 'GET', 'wcct/v1/events', array( 'timeout' => 30 ) );

        $this->assertLessThanOrEqual( 2, $timeouts[0] );
        $this->assertGreaterThan( 1.5, $timeouts[0] );
    }

    public function test_a_spent_budget_makes_no_request() {
        $called  = false;
        $context = new Context(
            0,
            function () use ( &$called ) {
                $called = true;
            }
        );

        $this->assertSame( array( 'code' => 0, 'body' => '', 'error' => 'wcct_doctor_timeout' ), $context->loopback( 'POST', '/wcct/v1/events' ) );
        $this->assertFalse( $called );
        $this->assertSame( 0.0, $context->timeout() );
    }

    public function test_a_failed_request_reports_its_error_code() {
        $context = new Context(
            5,
            function () {
                return new WP_Error( 'http_request_failed', 'cURL error 28' );
            }
        );

        $this->assertSame( array( 'code' => 0, 'body' => '', 'error' => 'http_request_failed' ), $context->loopback( 'POST', '/wcct/v1/events' ) );
    }

    public function test_the_budget_is_filtered() {
        $GLOBALS['wcct_test_filters']['wcct_doctor_time_budget'] = function () {
            return 3;
        };

        $this->assertEqualsWithDelta( 3, ( new Context() )->remaining(), 0.05 );
    }

    public function test_platform_values_and_paused_platforms() {
        $GLOBALS['wcct_test_options']['wcct_settings_v3']      = array( 'platforms' => array( 'facebook' => array( 'pixel_id' => '111111111111111' ) ) );
        $GLOBALS['wcct_test_options']['wcct_paused_platforms'] = array( 'ga4' );
        $context = new Context( 0 );

        $this->assertSame( array( 'pixel_id' => '111111111111111' ), $context->platform( 'facebook' ) );
        $this->assertSame( array(), $context->platform( 'twitter' ) );
        $this->assertTrue( $context->is_paused( 'ga4' ) );
        $this->assertFalse( $context->is_paused( 'facebook' ) );
    }
}
