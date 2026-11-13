<?php

require_once dirname( __DIR__ ) . '/Fakes/doctor-functions.php';
require_once dirname( __DIR__ ) . '/Fakes/FakeChecks.php';

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Consent\ConsentMode;
use StorePulse\ConversionTracking\Doctor\Check;
use StorePulse\ConversionTracking\Doctor\Checks\ConsentOffCheck;
use StorePulse\ConversionTracking\Doctor\Context;
use StorePulse\ConversionTracking\Doctor\Doctor;
use StorePulse\ConversionTracking\Doctor\Finding;
use StorePulse\ConversionTracking\Doctor\Registry;

/**
 * Tests for running the checks, the stored results, dismissals and the "found something new" badge
 */
class DoctorTest extends TestCase {

    /**
     * Registered sample checks, by ID.
     *
     * @var WCCT_Test_Sample_Check[]
     */
    private $checks = array();

    protected function setUp(): void {
        // Consent mode on: Lite's own check passes, so only the samples find something.
        $GLOBALS['wcct_test_options'] = array( ConsentMode::OPTION => 'on' );
    }

    protected function tearDown(): void {
        unset( $GLOBALS['wcct_test_options'], $GLOBALS['wcct_test_filters'], $GLOBALS['wcct_test_doctor'], $GLOBALS['wcct_test_doctor_order'] );
    }

    private function register( WCCT_Test_Sample_Check ...$checks ) {
        foreach ( $checks as $check ) {
            $this->checks[ $check->id ] = $check;
        }

        $registered = array_values( $this->checks );

        // Lite's own consent check only: the other checks have tests of their own.
        $GLOBALS['wcct_test_filters'][ Registry::FILTER ] = function () use ( $registered ) {
            return array_merge( array( ConsentOffCheck::class ), $registered );
        };
    }

    private function finds( $id, array ...$findings ) {
        $GLOBALS['wcct_test_doctor'][ $id ] = $findings;
    }

    private static function by_check( array $report ) {
        $found = array();

        foreach ( $report['findings'] as $finding ) {
            $found[] = $finding['check'];
        }

        return $found;
    }

    public function test_a_sample_detector_proves_the_result_shape() {
        $this->register( new WCCT_Test_Sample_Check( 'sample' ) );
        $this->finds(
            'sample',
            array(
                'found'      => 'Facebook for WooCommerce is also active',
                'why'        => 'Each sale reaches Meta twice.',
                'action'     => array( 'label' => 'Open Platforms', 'url' => '#/platforms/facebook' ),
                'confidence' => Finding::SURE,
                'evidence'   => array( 'plugin' => 'facebook-for-woocommerce' ),
            )
        );

        $report  = Doctor::run();
        $finding = $report['findings'][0];

        foreach ( array( 'hash', 'check', 'found', 'why', 'action', 'confidence', 'severity', 'label', 'tags', 'steps', 'dismissed', 'new' ) as $key ) {
            $this->assertArrayHasKey( $key, $finding );
        }

        $this->assertSame( 'Facebook for WooCommerce is also active', $finding['found'] );
        $this->assertSame( array( 'type' => 'link', 'label' => 'Open Platforms', 'url' => '#/platforms/facebook', 'external' => false, 'confirm' => '' ), $finding['action'] );
        $this->assertSame( 'sure', $finding['confidence'] );
        $this->assertArrayNotHasKey( 'evidence', $finding );
        $this->assertSame( 2, $report['checked'] );
        $this->assertSame( 2, $report['total'] );
        $this->assertSame( array( array( 'check' => 'consent-off', 'label' => 'Respect cookie choices is on' ) ), $report['working'] );
        $this->assertNotNull( $report['ran_at'] );
        $this->assertSame( 'Just now', $report['ran_ago'] );
    }

    public function test_before_the_first_run_the_report_is_empty() {
        $this->register( new WCCT_Test_Sample_Check( 'sample' ) );

        $report = Doctor::report();

        $this->assertNull( $report['ran_at'] );
        $this->assertSame( array( 0, 2, array(), array() ), array( $report['checked'], $report['total'], $report['findings'], $report['working'] ) );
    }

    public function test_findings_come_sure_first_then_unsure_then_good_to_know() {
        $this->register( new WCCT_Test_Sample_Check( 'note' ), new WCCT_Test_Sample_Check( 'maybe' ), new WCCT_Test_Sample_Check( 'sure' ) );
        $this->finds( 'note', array( 'found' => 'Note', 'severity' => Finding::INFO ) );
        $this->finds( 'maybe', array( 'found' => 'Maybe', 'confidence' => Finding::UNSURE ) );
        $this->finds( 'sure', array( 'found' => 'Sure one', 'key' => 'a' ), array( 'found' => 'Sure two', 'key' => 'b' ) );

        $report = Doctor::run();

        $this->assertSame( array( 'Sure one', 'Sure two', 'Maybe', 'Note' ), array_column( $report['findings'], 'found' ) );
        $this->assertSame( 4, $report['checked'] );
    }

    public function test_a_broken_check_is_skipped_and_not_counted() {
        $this->register( new WCCT_Test_Broken_Check( 'broken' ) );

        $report = Doctor::run();

        $this->assertSame( 1, $report['checked'] );
        $this->assertSame( 'broken', $report['skipped'][0]['check'] );
        $this->assertSame( 'This check could not run.', $report['skipped'][0]['reason'] );
    }

    public function test_remote_checks_run_last_and_are_skipped_once_the_budget_is_spent() {
        $remote = new WCCT_Test_Sample_Check( 'loopback', Check::REMOTE );
        $local  = new WCCT_Test_Sample_Check( 'local' );

        $this->register( $remote, $local );

        $results = Doctor::execute( array( 'loopback' => $remote, 'local' => $local ), new Context( 5 ) );

        $this->assertSame( array( 'local', 'loopback' ), $GLOBALS['wcct_test_doctor_order'] );
        $this->assertSame( 'passed', $results['loopback']['status'] );

        $results = Doctor::execute( array( 'loopback' => $remote, 'local' => $local ), new Context( 0 ) );

        $this->assertSame( array( 'skipped', 'timeout' ), array( $results['loopback']['status'], $results['loopback']['reason'] ) );
        $this->assertSame( 'passed', $results['local']['status'], 'Local checks ignore the budget' );
    }

    public function test_running_one_check_keeps_the_others_results() {
        $this->register( new WCCT_Test_Sample_Check( 'one' ), new WCCT_Test_Sample_Check( 'two' ) );
        $this->finds( 'one', array( 'found' => 'One' ) );
        $this->finds( 'two', array( 'found' => 'Two' ) );
        Doctor::run();

        $this->finds( 'one' );
        $report = Doctor::run( array( 'one' ) );

        $this->assertSame( array( 'two' ), self::by_check( $report ) );
        $this->assertSame( 1, $this->checks['two']->runs );
        $this->assertContains( array( 'check' => 'one', 'label' => 'Sample one is fine' ), $report['working'] );
    }

    public function test_results_of_checks_that_are_gone_are_dropped() {
        $this->register( new WCCT_Test_Sample_Check( 'pro-only' ) );
        $this->finds( 'pro-only', array( 'found' => 'Pro' ) );
        Doctor::run();

        $GLOBALS['wcct_test_filters'][ Registry::FILTER ] = function () {
            return array( ConsentOffCheck::class );
        };
        $report = Doctor::report();

        $this->assertSame( array(), $report['findings'] );
        $this->assertSame( 1, $report['checked'] );
    }

    public function test_dismiss_hides_a_finding_until_its_evidence_changes() {
        $this->register( new WCCT_Test_Sample_Check( 'sample' ) );
        $this->finds( 'sample', array( 'found' => 'Label incomplete', 'evidence' => array( 'label' => 'AW-1/abc' ) ) );

        $hash = Doctor::run()['findings'][0]['hash'];

        Doctor::dismiss( $hash );
        $this->assertTrue( Doctor::report()['findings'][0]['dismissed'] );
        $this->assertTrue( Doctor::run()['findings'][0]['dismissed'], 'Still dismissed after another run' );

        Doctor::dismiss( $hash, false );
        $this->assertFalse( Doctor::report()['findings'][0]['dismissed'], 'Undo' );

        Doctor::dismiss( $hash );
        $this->finds( 'sample', array( 'found' => 'Label incomplete', 'evidence' => array( 'label' => 'AW-1/abd' ) ) );
        $finding = Doctor::run()['findings'][0];

        $this->assertNotSame( $hash, $finding['hash'] );
        $this->assertFalse( $finding['dismissed'], 'A changed finding comes back' );
    }

    public function test_dismissals_are_capped() {
        for ( $i = 0; $i < Doctor::MAX_DISMISSED + 5; $i++ ) {
            Doctor::dismiss( md5( (string) $i ) );
        }

        $stored = get_option( Doctor::DISMISSED_OPTION );

        $this->assertCount( Doctor::MAX_DISMISSED, $stored );
        $this->assertArrayNotHasKey( md5( '0' ), $stored, 'The oldest go first' );
        $this->assertArrayHasKey( md5( (string) ( Doctor::MAX_DISMISSED + 4 ) ), $stored );
    }

    public function test_the_quiet_recheck_runs_local_checks_and_flags_new_problems() {
        $remote = new WCCT_Test_Sample_Check( 'loopback', Check::REMOTE );

        $this->register( new WCCT_Test_Sample_Check( 'plugins' ), $remote );
        $this->finds( 'plugins', array( 'found' => 'Old plugin', 'key' => 'old' ) );
        Doctor::run();

        // Nothing new: the badge stays off.
        $this->assertSame( array(), Doctor::recheck() );
        $this->assertSame( 0, Doctor::report()['new'] );

        $this->finds( 'plugins', array( 'found' => 'Old plugin', 'key' => 'old' ), array( 'found' => 'PixelYourSite', 'key' => 'pys' ), array( 'found' => 'Note', 'key' => 'n', 'severity' => Finding::INFO ) );
        $new    = Doctor::recheck();
        $report = Doctor::report();

        $this->assertCount( 1, $new, 'Good-to-know notes never light the badge' );
        $this->assertSame( 1, $report['new'] );
        $this->assertSame( array( false, true, false ), array_column( $report['findings'], 'new' ) );
        $this->assertSame( 1, $remote->runs, 'Remote checks only run when the merchant asks' );

        // Seeing the page clears it, and the same finding isn't new again.
        Doctor::seen();
        $this->assertSame( 0, Doctor::report()['new'] );
        $this->assertSame( array(), Doctor::recheck() );
    }

    public function test_a_dismissed_finding_is_not_new_and_dismissing_clears_its_badge() {
        $this->register( new WCCT_Test_Sample_Check( 'plugins' ) );
        Doctor::run();
        $this->finds( 'plugins', array( 'found' => 'PixelYourSite', 'key' => 'pys' ) );

        $hash = Doctor::recheck()[0];

        $this->assertSame( 1, Doctor::report()['new'] );

        Doctor::dismiss( $hash );
        $this->assertSame( 0, Doctor::report()['new'] );

        $this->assertSame( array(), Doctor::recheck(), 'Dismissed findings are known' );
    }

    public function test_a_full_run_clears_the_badge_but_running_one_check_does_not() {
        $this->register( new WCCT_Test_Sample_Check( 'plugins' ) );
        Doctor::run();
        $this->finds( 'plugins', array( 'found' => 'PixelYourSite', 'key' => 'pys' ) );
        Doctor::recheck();

        $this->assertSame( 1, Doctor::run( array( 'plugins' ) )['new'] );
        $this->assertSame( 0, Doctor::run()['new'] );
    }

    public function test_the_quiet_recheck_waits_for_the_first_run() {
        $sample = new WCCT_Test_Sample_Check( 'plugins' );

        $this->register( $sample );
        $this->finds( 'plugins', array( 'found' => 'PixelYourSite', 'key' => 'pys' ) );

        $this->assertSame( array(), Doctor::recheck() );
        $this->assertSame( 0, $sample->runs );
        $this->assertNull( Doctor::report()['ran_at'], 'A fresh install still shows "Not yet"' );
    }

    public function test_only_a_full_run_moves_the_last_check_time() {
        $this->register( new WCCT_Test_Sample_Check( 'plugins' ) );
        $GLOBALS['wcct_test_options'][ Doctor::RESULTS_OPTION ] = array(
            'time'    => 1000,
            'results' => array(),
        );

        Doctor::recheck();
        Doctor::run( array( 'plugins' ) );
        Doctor::run( array(), Check::LOCAL );

        $stored = get_option( Doctor::RESULTS_OPTION );

        $this->assertSame( 1000, $stored['time'] );
        $this->assertGreaterThan( 1000, $stored['rechecked_at'] );
        $this->assertSame( gmdate( 'c', 1000 ), Doctor::report()['ran_at'] );
        $this->assertArrayHasKey( 'plugins', $stored['results'] );

        Doctor::run();
        $this->assertGreaterThan( 1000, get_option( Doctor::RESULTS_OPTION )['time'] );
    }

    public function test_a_full_run_forgets_dismissals_of_findings_that_went_away() {
        $remote = new WCCT_Test_Sample_Check( 'loopback', Check::REMOTE );

        $this->register( new WCCT_Test_Sample_Check( 'note' ), $remote );
        $this->finds( 'note', array( 'found' => 'Note', 'severity' => Finding::INFO ) );
        $this->finds( 'loopback', array( 'found' => 'Blocked' ) );

        $report = Doctor::run();

        foreach ( $report['findings'] as $finding ) {
            Doctor::dismiss( $finding['hash'] );
        }

        // The loopback check runs out of time: its dismissal stays. The note is gone: its dismissal goes.
        $this->finds( 'note' );
        Doctor::run( array(), Doctor::SCOPE_ALL, new Context( 0 ) );
        $this->assertCount( 1, get_option( Doctor::DISMISSED_OPTION ) );

        $this->finds( 'note', array( 'found' => 'Note', 'severity' => Finding::INFO ) );
        $back = Doctor::run( array(), Doctor::SCOPE_ALL, new Context( 5 ) );

        $this->assertSame( array( 'Blocked', 'Note' ), array_column( $back['findings'], 'found' ) );
        $this->assertSame( array( true, false ), array_column( $back['findings'], 'dismissed' ), 'The note that went away and came back shows again' );
    }

    public function test_running_one_check_keeps_every_dismissal() {
        $this->register( new WCCT_Test_Sample_Check( 'one' ), new WCCT_Test_Sample_Check( 'two' ) );
        $this->finds( 'two', array( 'found' => 'Two' ) );
        Doctor::dismiss( Doctor::run()['findings'][0]['hash'] );

        Doctor::run( array( 'one' ) );

        $this->assertCount( 1, get_option( Doctor::DISMISSED_OPTION ) );
    }

    public function test_hashes_are_validated() {
        $this->assertTrue( Doctor::is_hash( md5( 'x' ) ) );
        $this->assertFalse( Doctor::is_hash( 'abc' ) );
        $this->assertFalse( Doctor::is_hash( strtoupper( md5( 'x' ) ) ) );
        $this->assertFalse( Doctor::is_hash( null ) );
    }

    public function test_junk_in_the_stored_options_never_breaks_the_report() {
        $this->register( new WCCT_Test_Sample_Check( 'sample' ) );
        $GLOBALS['wcct_test_options'][ Doctor::RESULTS_OPTION ]   = array(
            'time'    => 'x',
            'results' => array(
                'sample'      => array( 'status' => 'found', 'findings' => array( 'junk', array( 'hash' => 'nope' ) ) ),
                'consent-off' => 'junk',
            ),
        );
        $GLOBALS['wcct_test_options'][ Doctor::DISMISSED_OPTION ] = 'junk';
        $GLOBALS['wcct_test_options'][ Doctor::NEW_OPTION ]       = array( 'junk' );

        $report = Doctor::report();

        $this->assertSame( array(), $report['findings'] );
        $this->assertSame( array( array( 'check' => 'sample', 'label' => 'sample' ) ), $report['working'] );
        $this->assertSame( 0, $report['new'] );
    }
}
