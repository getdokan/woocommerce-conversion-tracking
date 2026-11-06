<?php
// phpcs:disable

/**
 * The wcct/v1/doctor routes in a real store, with two sample checks registered through wcct_doctor_checks (jobs/doctor.php)
 */
class DoctorRouteTest extends WCCT_Test_Rest_Case {

    const HASH = '0123456789abcdef0123456789abcdef';

    private function doctor( array $steps ) {
        return $this->rest( $steps, 'doctor' );
    }

    /**
     * Checks that apply in the store: Lite's and the job's local sample. Pro's feed-ids check needs a Meta pixel ID, which these jobs don't save.
     */
    private static function local_checks() {
        return array( 'consent-off', 'test-plugin' );
    }

    public function test_only_users_who_manage_tracking_reach_the_routes() {
        $steps = array();
        $users = array( 'admin' => 200, 'shop_manager' => 403, 'customer' => 403, 'subscriber' => 403, 'seller' => 403, 'logged_out' => 401 );

        foreach ( array_keys( $users ) as $user ) {
            $steps[] = array( 'request', 'GET', '/wcct/v1/doctor', null, $user );
            $steps[] = array( 'request', 'POST', '/wcct/v1/doctor', array(), $user );
            $steps[] = array( 'request', 'POST', '/wcct/v1/doctor/dismiss', array( 'hash' => self::HASH ), $user );
            $steps[] = array( 'request', 'POST', '/wcct/v1/doctor/seen', array(), $user );
        }

        $expected = array();

        foreach ( $users as $status ) {
            $expected = array_merge( $expected, array_fill( 0, 4, $status ) );
        }

        $this->assertSame( $expected, self::statuses( $this->doctor( $steps ) ) );
    }

    public function test_run_dismiss_undo_and_a_changed_finding_coming_back() {
        $results = $this->doctor(
            array(
                array( 'request', 'GET', '/wcct/v1/doctor', null, 'admin' ),
                array( 'set_option', 'wcct_test_plugin_found', 'PixelYourSite' ),
                array( 'request', 'POST', '/wcct/v1/doctor', array(), 'admin' ),
                array( 'option', 'wcct_doctor_results' ),
            )
        );

        // Before the first run: nothing stored, nothing found.
        $this->assertSame( 200, $results[0]['status'] );
        $this->assertNull( $results[0]['data']['ran_at'] );
        $this->assertSame( array( 0, count( self::local_checks() ) ), array( $results[0]['data']['checked'], $results[0]['data']['total'] ) );

        $report = $results[2]['data'];

        $this->assertSame( count( self::local_checks() ), $report['checked'] );
        $this->assertMatchesRegularExpression( '/^today /', $report['ran_at_label'] );
        $this->assertSame( 'Just now', $report['ran_ago'] );
        $this->assertSame( array( 'test-plugin', 'consent-off' ), array_column( $report['findings'], 'check' ), 'Problems first, then good to know' );

        $plugin = $report['findings'][0];

        $this->assertSame( 'PixelYourSite is also active', $plugin['found'] );
        $this->assertSame( 'Each sale can reach Meta twice.', $plugin['why'] );
        $this->assertSame( array( 'type' => 'recheck', 'label' => 'Check again', 'url' => '', 'external' => false, 'confirm' => '' ), $plugin['action'] );
        $this->assertSame( array( 'sure', 'warning', false, false ), array( $plugin['confidence'], $plugin['severity'], $plugin['dismissed'], $plugin['new'] ) );
        $this->assertSame( array( 'info', '#/platforms' ), array( $report['findings'][1]['severity'], $report['findings'][1]['action']['url'] ) );
        $this->assertArrayNotHasKey( 'evidence', $plugin );
        $this->assertArrayHasKey( 'test-plugin', $results[3]['results'] );

        $hash    = $plugin['hash'];
        $results = $this->doctor(
            array(
                array( 'set_option', 'wcct_test_plugin_found', 'PixelYourSite' ),
                array( 'request', 'POST', '/wcct/v1/doctor', array(), 'admin' ),
                array( 'request', 'POST', '/wcct/v1/doctor/dismiss', array( 'hash' => $hash ), 'admin' ),
                array( 'request', 'POST', '/wcct/v1/doctor', array(), 'admin' ),
                array( 'request', 'POST', '/wcct/v1/doctor/dismiss', array( 'hash' => $hash, 'dismissed' => false ), 'admin' ),
                array( 'request', 'POST', '/wcct/v1/doctor/dismiss', array( 'hash' => $hash ), 'admin' ),
                array( 'set_option', 'wcct_test_plugin_found', 'Pixel Manager' ),
                array( 'request', 'POST', '/wcct/v1/doctor', array( 'checks' => array( 'test-plugin', 'no-such-check' ) ), 'admin' ),
                array( 'request', 'POST', '/wcct/v1/doctor/dismiss', array( 'hash' => 'not-a-hash' ), 'admin' ),
                array( 'request', 'POST', '/wcct/v1/doctor/dismiss', array(), 'admin' ),
                array( 'request', 'POST', '/wcct/v1/doctor', array( 'scope' => 'everything' ), 'admin' ),
            )
        );

        $dismissed = function ( $report ) use ( $hash ) {
            foreach ( $report['findings'] as $finding ) {
                if ( $hash === $finding['hash'] ) {
                    return $finding['dismissed'];
                }
            }

            return null;
        };

        $this->assertTrue( $dismissed( $results[2]['data'] ) );
        $this->assertTrue( $dismissed( $results[3]['data'] ), 'Stays dismissed on the next run' );
        $this->assertFalse( $dismissed( $results[4]['data'] ), 'Undo' );

        $changed = $results[7]['data']['findings'][0];

        $this->assertSame( 'Pixel Manager is also active', $changed['found'] );
        $this->assertNotSame( $hash, $changed['hash'] );
        $this->assertFalse( $changed['dismissed'], 'A changed finding reappears' );
        $this->assertSame( array( 400, 400, 400 ), array( $results[8]['status'], $results[9]['status'], $results[10]['status'] ) );
    }

    public function test_consent_mode_on_clears_the_note() {
        $results = $this->doctor(
            array(
                array( 'set_option', 'wcct_consent_mode', 'on' ),
                array( 'request', 'POST', '/wcct/v1/doctor', array(), 'admin' ),
            )
        );

        $this->assertSame( array(), self::pro_loaded() ? array_values( array_filter( $results[1]['data']['findings'], function ( $f ) { return 'feed-ids' !== $f['check']; } ) ) : $results[1]['data']['findings'] );
        $this->assertContains( array( 'check' => 'consent-off', 'label' => 'Respect cookie choices is on' ), $results[1]['data']['working'] );
        $this->assertContains( array( 'check' => 'test-plugin', 'label' => 'No test plugin is active' ), $results[1]['data']['working'] );
    }

    public function test_a_remote_check_posts_to_the_store_without_cookies_and_with_a_timeout() {
        $results = $this->doctor(
            array(
                array( 'set_option', 'wcct_test_loopback', 'ok' ),
                array( 'request', 'POST', '/wcct/v1/doctor', array(), 'admin' ),
                array( 'global', 'wcct_test_loopback_requests' ),
                array( 'set_option', 'wcct_test_loopback', 'blocked' ),
                array( 'request', 'POST', '/wcct/v1/doctor', array( 'scope' => 'remote' ), 'admin' ),
                array( 'set_option', 'wcct_test_loopback', 'down' ),
                array( 'request', 'POST', '/wcct/v1/doctor', array( 'checks' => array( 'test-loopback' ) ), 'admin' ),
                array( 'global', 'wcct_test_loopback_requests' ),
            )
        );

        $this->assertSame( count( self::local_checks() ) + 1, $results[1]['data']['checked'] );
        $this->assertContains( array( 'check' => 'test-loopback', 'label' => 'Check-ins reach your store' ), $results[1]['data']['working'] );

        $request = $results[2][0];

        $this->assertStringEndsWith( '/wcct/v1/events', $request['url'] );
        $this->assertSame( array( 'POST', 3, array(), '{"probe":"doctor"}' ), array( $request['method'], $request['timeout'], $request['cookies'], $request['body'] ) );

        $blocked = array_values( array_filter( $results[4]['data']['findings'], function ( $f ) { return 'test-loopback' === $f['check']; } ) );
        $down    = array_values( array_filter( $results[6]['data']['findings'], function ( $f ) { return 'test-loopback' === $f['check']; } ) );

        $this->assertSame( array( 'Check-ins are blocked for shoppers', 'unsure' ), array( $blocked[0]['found'], $blocked[0]['confidence'] ) );
        $this->assertNotSame( $blocked[0]['hash'], $down[0]['hash'], 'Another failure is another finding' );
        $this->assertCount( 3, $results[7] );
    }

    public function test_activating_a_plugin_rechecks_quietly_and_lights_the_badge() {
        $results = $this->doctor(
            array(
                array( 'set_option', 'wcct_test_loopback', 'ok' ),
                array( 'request', 'POST', '/wcct/v1/doctor', array(), 'admin' ),
                array( 'set_option', 'wcct_test_plugin_found', 'PixelYourSite' ),
                array( 'action', 'activated_plugin', 'pixelyoursite/pixelyoursite.php' ),
                array( 'action', 'activated_plugin', 'another/another.php' ),
                array( 'shutdown', 'StorePulse\ConversionTracking\Doctor\Recheck' ),
                array( 'request', 'GET', '/wcct/v1/doctor', null, 'admin' ),
                array( 'global', 'wcct_test_loopback_requests' ),
                array( 'request', 'POST', '/wcct/v1/doctor/seen', array(), 'admin' ),
                array( 'option', 'wcct_doctor_new' ),
                array( 'option', 'wcct_doctor_results' ),
            )
        );

        $this->assertSame( 0, $results[1]['data']['new'] );
        $this->assertSame( 1, $results[5], 'Bulk activation re-checks once' );

        $report = $results[6]['data'];

        $this->assertSame( 1, $report['new'] );
        $this->assertSame( array( 'PixelYourSite is also active', true ), array( $report['findings'][0]['found'], $report['findings'][0]['new'] ) );
        $this->assertCount( 1, $results[7], 'The quiet re-check makes no requests' );
        $this->assertSame( 0, $results[8]['data']['new'] );
        $this->assertSame( array(), $results[9] );
        $this->assertSame( $results[1]['data']['ran_at'], $report['ran_at'], 'The quiet re-check is not a merchant run' );
        $this->assertGreaterThan( 0, $results[10]['rechecked_at'] );
    }

    public function test_activating_conversion_tracking_itself_records_no_run() {
        $lite    = 'woocommerce-conversion-tracking/conversion-tracking.php';
        $results = $this->doctor(
            array(
                array( 'set_option', 'wcct_test_plugin_found', 'PixelYourSite' ),
                array( 'action', 'activated_plugin', $lite ),
                array( 'shutdown', 'StorePulse\ConversionTracking\Doctor\Recheck' ),
                array( 'option', 'wcct_doctor_results' ),
                array( 'request', 'GET', '/wcct/v1/doctor', null, 'admin' ),
                // Another plugin before the first run: still nothing recorded.
                array( 'action', 'activated_plugin', 'pixelyoursite/pixelyoursite.php' ),
                array( 'shutdown', 'StorePulse\ConversionTracking\Doctor\Recheck' ),
                array( 'option', 'wcct_doctor_results' ),
                array( 'option', 'wcct_doctor_new' ),
            )
        );

        $this->assertSame( 0, $results[2], 'Activating Lite queues no re-check' );
        $this->assertNull( $results[3] );
        $this->assertNull( $results[4]['data']['ran_at'], 'The dashboard still shows "Not yet"' );
        $this->assertSame( 1, $results[6] );
        $this->assertNull( $results[7] );
        $this->assertNull( $results[8] );
    }

    public function test_the_recheck_filter_turns_it_off() {
        $results = $this->doctor(
            array(
                array( 'request', 'POST', '/wcct/v1/doctor', array(), 'admin' ),
                array( 'set_option', 'wcct_test_plugin_found', 'PixelYourSite' ),
                array( 'action', 'wcct_test_add_filter', 'wcct_doctor_recheck_on_activation' ),
                array( 'action', 'activated_plugin', 'pixelyoursite/pixelyoursite.php' ),
                array( 'shutdown', 'StorePulse\ConversionTracking\Doctor\Recheck' ),
                array( 'option', 'wcct_doctor_new' ),
            )
        );

        $this->assertSame( 0, $results[4] );
        $this->assertNull( $results[5] );
    }
}
