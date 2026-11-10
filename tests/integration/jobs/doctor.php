<?php
/**
 * Doctor route steps with two sample checks registered through wcct_doctor_checks next to Lite's consent note (see REST/DoctorRouteTest), then the steps of rest-requests.php.
 *
 * wcct_test_plugin_found (option): the local sample check finds a plugin named by it.
 * wcct_test_loopback (option): 'ok', 'blocked' (403) or 'down' (request error) turns on the remote sample check; every request
 * it makes is answered by pre_http_request and recorded in $GLOBALS['wcct_test_loopback_requests'].
 */

// phpcs:disable

use StorePulse\ConversionTracking\Doctor\AbstractCheck;
use StorePulse\ConversionTracking\Doctor\Check;
use StorePulse\ConversionTracking\Doctor\Context;
use StorePulse\ConversionTracking\Doctor\Finding;

class WCCT_Test_Doctor_Plugin_Check extends AbstractCheck {

    public function id() {
        return 'test-plugin';
    }

    public function label() {
        return 'No test plugin is active';
    }

    public function run( Context $context ) {
        $plugin = get_option( 'wcct_test_plugin_found', '' );

        if ( ! $plugin ) {
            return array();
        }

        return array(
            $this->finding(
                array(
                    'key'      => $plugin,
                    'found'    => $plugin . ' is also active',
                    'why'      => 'Each sale can reach Meta twice.',
                    'label'    => 'Duplicate',
                    'tags'     => array( 'Meta' ),
                    'steps'    => array( 'Pick one plugin.', 'Then check again.' ),
                    'action'   => array( 'type' => 'recheck', 'label' => 'Check again' ),
                    'evidence' => array( 'plugin' => $plugin ),
                )
            ),
        );
    }
}

class WCCT_Test_Doctor_Loopback_Check extends AbstractCheck {

    public function id() {
        return 'test-loopback';
    }

    public function label() {
        return 'Check-ins reach your store';
    }

    public function scope() {
        return Check::REMOTE;
    }

    public function applies() {
        return (bool) get_option( 'wcct_test_loopback', '' );
    }

    public function run( Context $context ) {
        $response = $context->loopback( 'POST', '/wcct/v1/events', array( 'body' => array( 'probe' => 'doctor' ), 'timeout' => 3 ) );

        if ( $response['code'] >= 200 && $response['code'] < 300 ) {
            return array();
        }

        return array(
            $this->finding(
                array(
                    'found'      => 'Check-ins are blocked for shoppers',
                    'confidence' => Finding::UNSURE,
                    'evidence'   => array( 'code' => $response['code'], 'error' => $response['error'] ),
                )
            ),
        );
    }
}

add_filter(
    'wcct_doctor_checks',
    function ( $checks ) {
        // The framework's routes with Lite's consent note only; the other checks have DoctorChecksTest.
        $checks = array_values(
            array_filter(
                $checks,
                function ( $check ) {
                    return ! is_string( $check ) || 0 !== strpos( $check, 'StorePulse\\ConversionTracking\\Doctor\\Checks\\' ) || 'StorePulse\\ConversionTracking\\Doctor\\Checks\\ConsentOffCheck' === $check;
                }
            )
        );
        $checks[] = WCCT_Test_Doctor_Plugin_Check::class;
        $checks[] = WCCT_Test_Doctor_Loopback_Check::class;

        return $checks;
    }
);

// [ 'action', 'wcct_test_add_filter', hook ] makes that filter return false.
add_action(
    'wcct_test_add_filter',
    function ( $hook ) {
        add_filter( $hook, '__return_false' );
    }
);

$GLOBALS['wcct_test_loopback_requests'] = array();

add_filter(
    'pre_http_request',
    function ( $pre, $args, $url ) {
        $GLOBALS['wcct_test_loopback_requests'][] = array(
            'url'     => $url,
            'method'  => $args['method'],
            'timeout' => $args['timeout'],
            'cookies' => $args['cookies'],
            'body'    => $args['body'],
        );

        $mode = get_option( 'wcct_test_loopback', '' );

        if ( 'down' === $mode ) {
            return new WP_Error( 'http_request_failed', 'cURL error 28: timed out' );
        }

        return array(
            'headers'  => array(),
            'body'     => '',
            'response' => array( 'code' => 'blocked' === $mode ? 403 : 204, 'message' => '' ),
            'cookies'  => array(),
            'filename' => null,
        );
    },
    10,
    3
);

return require __DIR__ . '/rest-requests.php';
