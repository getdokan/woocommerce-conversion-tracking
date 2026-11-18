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
        $this->assertSame( array( 'info', '#/platforms/facebook?field=consent_mode' ), array( $report['findings'][1]['severity'], $report['findings'][1]['action']['url'] ) );
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

    public function test_the_alerts_switch_turns_the_recheck_off_and_on() {
        $recheck = array(
            array( 'action', 'activated_plugin', 'pixelyoursite/pixelyoursite.php' ),
            array( 'shutdown', 'StorePulse\ConversionTracking\Doctor\Recheck' ),
            array( 'option', 'wcct_doctor_new' ),
        );
        $results = $this->doctor(
            array_merge(
                array(
                    array( 'set_option', 'wcct_settings_v3', array( 'alert_doctor_new' => false ) ),
                    array( 'request', 'POST', '/wcct/v1/doctor', array(), 'admin' ),
                    array( 'set_option', 'wcct_test_plugin_found', 'PixelYourSite' ),
                ),
                $recheck,
                array( array( 'set_option', 'wcct_settings_v3', array( 'alert_doctor_new' => true ) ) ),
                $recheck,
                array( array( 'set_option', 'wcct_settings_v3', null ) )
            )
        );

        // Off (Settings > Alerts > Doctor found something new): no re-check, no badge.
        $this->assertSame( array( 0, null ), array( $results[4], $results[5] ) );
        // On again: the next activation re-checks and lights the badge.
        $this->assertSame( 1, $results[8] );
        $this->assertCount( 1, $results[9] );
    }

    public function test_a_settings_save_or_a_pause_rechecks_at_once_and_the_alert_switch_only_keeps_the_badge_off() {
        $save    = array( 'request', 'POST', '/wcct/v1/settings', array( 'alert_doctor_new' => false ), 'admin' );
        $results = $this->doctor(
            array(
                // Before the merchant's first run: nothing recorded.
                $save,
                array( 'option', 'wcct_doctor_results' ),
                array( 'request', 'POST', '/wcct/v1/doctor', array(), 'admin' ),
                // A plugin shows up; the next save (alert off) re-checks without lighting the badge.
                array( 'set_option', 'wcct_test_plugin_found', 'PixelYourSite' ),
                $save,
                array( 'request', 'GET', '/wcct/v1/doctor', null, 'admin' ),
                array( 'option', 'wcct_doctor_new' ),
                // It goes again; a pause re-checks too.
                array( 'set_option', 'wcct_test_plugin_found', '' ),
                array( 'request', 'POST', '/wcct/v1/settings/pause', array( 'platform' => 'facebook', 'paused' => true ), 'admin' ),
                array( 'request', 'GET', '/wcct/v1/doctor', null, 'admin' ),
                // Alert on: a save's new finding lights the badge.
                array( 'set_option', 'wcct_test_plugin_found', 'PixelYourSite' ),
                array( 'request', 'POST', '/wcct/v1/settings', array( 'alert_doctor_new' => true ), 'admin' ),
                array( 'request', 'GET', '/wcct/v1/doctor', null, 'admin' ),
                array( 'set_option', 'wcct_settings_v3', null ),
            )
        );

        $this->assertSame( 200, $results[0]['status'] );
        $this->assertNull( $results[1] );
        $this->assertContains( 'PixelYourSite is also active', array_column( $results[5]['data']['findings'], 'found' ) );
        $this->assertSame( array( 0, null ), array( $results[5]['data']['new'], $results[6] ) );
        $this->assertSame( 200, $results[8]['status'] );
        $this->assertNotContains( 'PixelYourSite is also active', array_column( $results[9]['data']['findings'], 'found' ) );
        $this->assertContains( 'PixelYourSite is also active', array_column( $results[12]['data']['findings'], 'found' ) );
        $this->assertSame( 1, $results[12]['data']['new'] );
    }

    public function test_a_dismissed_warning_stays_dismissed_through_re_checks_even_if_its_cause_goes_and_comes_back() {
        $save    = array( 'request', 'POST', '/wcct/v1/settings', array( 'alert_doctor_new' => true ), 'admin' );
        $plugin  = function ( array $report ) {
            foreach ( $report['findings'] as $finding ) {
                if ( 'test-plugin' === $finding['check'] ) {
                    return array( $finding['dismissed'], $finding['new'] );
                }
            }

            return null;
        };
        $first   = $this->doctor(
            array(
                array( 'set_option', 'wcct_test_plugin_found', 'PixelYourSite' ),
                array( 'request', 'POST', '/wcct/v1/doctor', array(), 'admin' ),
            )
        );
        $hash    = $first[1]['data']['findings'][0]['hash'];
        $results = $this->doctor(
            array(
                array( 'set_option', 'wcct_test_plugin_found', 'PixelYourSite' ),
                array( 'request', 'POST', '/wcct/v1/doctor', array(), 'admin' ),
                array( 'request', 'POST', '/wcct/v1/doctor/dismiss', array( 'hash' => $hash ), 'admin' ),
                // Still there: stays dismissed, not new.
                $save,
                array( 'request', 'GET', '/wcct/v1/doctor', null, 'admin' ),
                // Gone, then back (as with a pause and resume): still dismissed, not new.
                array( 'set_option', 'wcct_test_plugin_found', '' ),
                $save,
                array( 'set_option', 'wcct_test_plugin_found', 'PixelYourSite' ),
                $save,
                array( 'request', 'GET', '/wcct/v1/doctor', null, 'admin' ),
                array( 'set_option', 'wcct_settings_v3', null ),
            )
        );

        $this->assertSame( array( true, false ), $plugin( $results[4]['data'] ) );
        $this->assertSame( array( true, false ), $plugin( $results[9]['data'] ) );
    }

    public function test_switching_respect_cookie_choices_on_clears_the_consent_note_in_the_same_save() {
        $results = $this->doctor(
            array(
                array( 'request', 'POST', '/wcct/v1/doctor', array(), 'admin' ),
                array( 'request', 'POST', '/wcct/v1/settings', array( 'consent_mode' => true ), 'admin' ),
                array( 'request', 'GET', '/wcct/v1/doctor', null, 'admin' ),
                array( 'set_option', 'wcct_settings_v3', null ),
                array( 'set_option', 'wcct_consent_mode', null ),
            )
        );

        $this->assertContains( 'consent-off', array_column( $results[0]['data']['findings'], 'check' ) );
        $this->assertSame( 200, $results[1]['status'] );
        $this->assertNotContains( 'consent-off', array_column( $results[2]['data']['findings'], 'check' ) );
    }

    public function test_deactivating_a_plugin_rechecks_once_on_the_next_admin_load_whatever_the_alert() {
        $recheck = 'StorePulse\\ConversionTracking\\Doctor\\Recheck';
        $pys     = 'pixelyoursite/pixelyoursite.php';
        $results = $this->doctor(
            array(
                // Settings > Alerts > Doctor found something new off: the activation re-check is off, this one still runs.
                array( 'set_option', 'wcct_settings_v3', array( 'alert_doctor_new' => false ) ),
                array( 'set_option', 'wcct_doctor_plugins', null ),
                array( 'set_option', 'wcct_test_plugin_found', 'PixelYourSite' ),
                array( 'call', 'wcct_test_active_plugin', array( $pys, true ) ),
                // An admin load records the plugins it runs with.
                array( 'call', 'wcct_test_admin_init', array( $recheck ) ),
                array( 'request', 'POST', '/wcct/v1/doctor', array(), 'admin' ),
                // Deactivated: the next admin load runs without it.
                array( 'call', 'wcct_test_active_plugin', array( $pys, false ) ),
                array( 'set_option', 'wcct_test_plugin_found', '' ),
                array( 'call', 'wcct_test_admin_init', array( $recheck ) ),
                array( 'request', 'GET', '/wcct/v1/doctor', null, 'admin' ),
                array( 'option', 'wcct_doctor_new' ),
                // Once: an unchanged list doesn't re-check again.
                array( 'set_option', 'wcct_test_plugin_found', 'PixelYourSite' ),
                array( 'call', 'wcct_test_admin_init', array( $recheck ) ),
                array( 'request', 'GET', '/wcct/v1/doctor', null, 'admin' ),
                array( 'set_option', 'wcct_settings_v3', null ),
                array( 'set_option', 'wcct_doctor_plugins', null ),
            )
        );
        $found   = function ( $index ) use ( $results ) {
            return in_array( 'PixelYourSite is also active', array_column( $results[ $index ]['data']['findings'], 'found' ), true );
        };

        $this->assertSame( 1, $results[4] );
        $this->assertTrue( $found( 5 ) );
        $this->assertFalse( $found( 9 ) );
        $this->assertNull( $results[10] );
        $this->assertFalse( $found( 13 ) );
    }
}
