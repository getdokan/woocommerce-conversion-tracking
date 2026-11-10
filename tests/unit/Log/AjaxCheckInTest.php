<?php

require_once dirname( __DIR__ ) . '/Fakes/settings-functions.php';

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Log\AjaxCheckIn;
use StorePulse\ConversionTracking\Log\CheckIn;
use StorePulse\ConversionTracking\Onboarding\Verify;

if ( ! class_exists( 'WCCT_Test_Json_Sent' ) ) {
    /**
     * Thrown by the wp_send_json_* stubs instead of ending the request.
     */
    class WCCT_Test_Json_Sent extends Exception {
        public $success;
        public $data;
        public $status;

        public function __construct( $success, $data, $status ) {
            parent::__construct( 'json sent' );
            $this->success = $success;
            $this->data    = $data;
            $this->status  = $status;
        }
    }
}

if ( ! function_exists( 'wp_send_json_success' ) ) {
    function wp_send_json_success( $data = null, $status = null ) {
        throw new WCCT_Test_Json_Sent( true, $data, $status );
    }
}

if ( ! function_exists( 'wp_send_json_error' ) ) {
    function wp_send_json_error( $data = null, $status = null ) {
        throw new WCCT_Test_Json_Sent( false, $data, $status );
    }
}

if ( ! function_exists( 'wp_unslash' ) ) {
    function wp_unslash( $value ) {
        return is_string( $value ) ? stripslashes( $value ) : $value;
    }
}

if ( ! function_exists( '_deprecated_function' ) ) {
    function _deprecated_function( $name, $version, $replacement = '' ) {
        $GLOBALS['wcct_test_deprecated'][] = $name;
    }
}

if ( ! function_exists( 'current_action' ) ) {
    function current_action() {
        return 'wp_ajax_nopriv_wcct_purchase_sent';
    }
}

/**
 * Tests for the admin-ajax fallback of the check-in
 */
class AjaxCheckInTest extends TestCase {

    private $recorded = array();

    protected function tearDown(): void {
        $_POST = array();
        unset( $GLOBALS['wcct_test_deprecated'], $GLOBALS['wcct_test_options'] );
    }

    private function handler() {
        $order = new WCCT_Test_Fake_Order( array( 'id' => 9, 'key' => 'wc_order_abc' ) );

        return new AjaxCheckIn(
            function () {
                return array( 'facebook', 'twitter' );
            },
            function ( $order_id, $key ) use ( $order ) {
                return 9 === $order_id && 'wc_order_abc' === $key ? $order : false;
            },
            function ( $order, $platform, $fields ) {
                $this->recorded[] = array( $order->get_id(), $platform, $fields );

                return CheckIn::RECORDED;
            }
        );
    }

    private function post( array $fields ) {
        $_POST = $fields;

        try {
            $this->handler()->handle();
        } catch ( WCCT_Test_Json_Sent $sent ) {
            return array( $sent->success, $sent->data, $sent->status );
        }

        $this->fail( 'No JSON sent' );
    }

    public function test_a_valid_check_in_is_recorded() {
        $response = $this->post( array( 'order_id' => '9', 'key' => 'wc_order_abc', 'platform' => 'facebook', 'loaded' => '1', 'consent' => 'granted', 'event_id' => 'wcct_9_purchase', 'reason' => '', 'action' => 'wcct_events' ) );

        $this->assertSame( array( true, array( 'result' => 'recorded' ), null ), $response );
        $this->assertSame( array( array( 9, 'facebook', array( 'loaded' => '1', 'consent' => 'granted', 'reason' => '', 'event_id' => 'wcct_9_purchase' ) ) ), $this->recorded );
    }

    public function test_the_same_rejections_as_the_route() {
        $this->assertSame( array( false, array( 'code' => 'wcct_rest_unknown_platform' ), 400 ), $this->post( array( 'order_id' => '9', 'key' => 'wc_order_abc', 'platform' => 'bing_ads' ) ) );
        $this->assertSame( array( false, array( 'code' => 'wcct_rest_unknown_platform' ), 400 ), $this->post( array( 'order_id' => '9', 'key' => 'wc_order_abc' ) ) );
        $this->assertSame( array( false, array( 'code' => 'wcct_rest_invalid_order' ), 403 ), $this->post( array( 'order_id' => '9', 'key' => 'wc_order_wrong', 'platform' => 'facebook' ) ) );
        $this->assertSame( array( false, array( 'code' => 'wcct_rest_invalid_order' ), 403 ), $this->post( array( 'order_id' => 'x', 'key' => 'wc_order_abc', 'platform' => 'facebook' ) ) );
        $this->assertSame( array( false, array( 'code' => 'wcct_rest_invalid_order' ), 403 ), $this->post( array( 'order_id' => array( 9 ), 'key' => array( 'wc_order_abc' ), 'platform' => 'facebook' ) ) );
        $this->assertSame( array(), $this->recorded );
    }

    public function test_the_2x_actions_answer_success_and_write_nothing() {
        try {
            $this->handler()->legacy();
        } catch ( WCCT_Test_Json_Sent $sent ) {
            $this->assertTrue( $sent->success );
        }

        $this->assertSame( array( 'wp_ajax_nopriv_wcct_purchase_sent' ), $GLOBALS['wcct_test_deprecated'] );
        $this->assertSame( array(), $this->recorded );
        $this->assertSame( array( 'wcct_purchase_sent', 'wcct_ga4_purchase_sent' ), AjaxCheckIn::LEGACY_ACTIONS );
    }

    public function test_the_test_links_seen_ping_has_the_routes_token_check_and_throttle() {
        $token = 'abcdefghijklmnopqrstuvwxyz012345';

        $GLOBALS['wcct_test_options'] = array( Verify::OPTION => array( 'token' => $token, 'started' => time() - 5, 'seen' => 0, 'platforms' => array() ) );

        $this->assertSame( array( false, array( 'code' => 'wcct_rest_invalid_token' ), 403 ), $this->post( array( 'action' => 'wcct_events', 'type' => 'seen', 'token' => 'wrong' ) ) );
        $this->assertSame( array( false, array( 'code' => 'wcct_rest_invalid_token' ), 403 ), $this->post( array( 'type' => 'seen', 'token' => array( $token ) ) ) );
        $this->assertSame( array( true, array( 'result' => 'ok' ), null ), $this->post( array( 'type' => 'seen', 'token' => $token, 'platforms' => array( 'facebook' => '1', 'twitter' => '0', 'evil' => '1' ) ) ) );
        $this->assertSame( array( false, array( 'code' => 'wcct_rest_throttled' ), 429 ), $this->post( array( 'type' => 'seen', 'token' => $token, 'platforms' => array( 'twitter' => '1' ) ) ) );
        $this->assertSame( array( 'facebook' => true, 'twitter' => false ), Verify::stored()['platforms'] );
        $this->assertSame( array(), $this->recorded );
    }
}
