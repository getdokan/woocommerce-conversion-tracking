<?php

require_once __DIR__ . '/DoctorCheckTestCase.php';

use StorePulse\ConversionTracking\Consent\ConsentData;
use StorePulse\ConversionTracking\Consent\ConsentMode;
use StorePulse\ConversionTracking\Doctor\Checks\ConsentPluginCheck;

/**
 * "Respect cookie choices" on with no consent plugin setting a consent type
 */
class ConsentPluginCheckTest extends DoctorCheckTestCase {

    protected function tearDown(): void {
        parent::tearDown();
        ConsentMode::use_signals( null );
    }

    /**
     * Signals with only the given tools active.
     */
    private function tools( array $tools ) {
        ConsentMode::use_signals( array_merge( array_fill_keys( ConsentData::CONSENT_TOOLS, null ), $tools ) );
    }

    public function test_switch_on_without_a_consent_plugin_gives_the_finding() {
        $this->option( ConsentMode::OPTION, 'on' );
        $this->tools( array() );
        ConsentMode::use_api( new WCCT_Test_Fake_Consent_Api( true, '' ) );
        // Something hooks wp_get_consent_type but sets no type: no type is expected, so tags run.
        $this->filter(
            'wp_get_consent_type',
            function ( $type ) {
                return $type;
            }
        );

        $findings = $this->run_check( new ConsentPluginCheck() );

        $this->assertCount( 1, $findings );
        $this->assertSame( 'Cookie choices are on, but no consent plugin is connected', $findings[0]['found'] );
        $this->assertSame( 'Nothing tells your tags what visitors chose, so they run as if everyone agreed.', $findings[0]['why'] );
        $this->assertSame( 'warning', $findings[0]['severity'] );
        $this->assertSame( array( 'All platforms', 'Europe' ), $findings[0]['tags'] );
        $this->assertSame( array( 'link', 'See compatible plugins', true, '' ), array( $findings[0]['action']['type'], $findings[0]['action']['label'], $findings[0]['action']['external'], $findings[0]['action']['confirm'] ) );
    }

    public function test_without_a_consent_type_expected_the_tags_run_and_the_copy_says_so() {
        $this->option( ConsentMode::OPTION, 'on' );
        $this->tools( array() );

        foreach ( array( 'No WP Consent API' => new WCCT_Test_Fake_Consent_Api( false, '' ), 'The API alone sets no type' => new WCCT_Test_Fake_Consent_Api( true, '' ) ) as $case => $api ) {
            ConsentMode::use_api( $api );

            $findings = $this->run_check( new ConsentPluginCheck() );

            $this->assertSame( 'Nothing tells your tags what visitors chose, so they run as if everyone agreed.', $findings[0]['why'], $case );
        }
    }

    public function test_a_consent_plugin_setting_a_consent_type_gives_nothing() {
        $this->option( ConsentMode::OPTION, 'on' );
        ConsentMode::use_api( new WCCT_Test_Fake_Consent_Api( true, 'optin' ) );

        $check = new ConsentPluginCheck();

        $this->assertSame( array(), $this->run_check( $check ) );
        $this->assertSame( 'Cookie choices reach your tags through your consent plugin', $check->label() );
    }

    /**
     * @dataProvider silent_tools
     */
    public function test_a_consent_tool_that_reports_no_type_gives_a_warning( array $tools, $names ) {
        $this->option( ConsentMode::OPTION, 'on' );
        $this->tools( $tools );
        ConsentMode::use_api( new WCCT_Test_Fake_Consent_Api( true, '' ) );

        $findings = $this->run_check( new ConsentPluginCheck() );

        $this->assertCount( 1, $findings );
        $this->assertSame( 'Consent tool detected but not reporting', $findings[0]['found'] );
        $this->assertSame( 'warning', $findings[0]['severity'] );
        $this->assertSame( $names . ' is active but doesn\'t pass visitors\' cookie choices to the WP Consent API, so Conversion Tracking can\'t see them and uses its own defaults: Google tags follow consent mode, and your other tags run as if everyone agreed.', $findings[0]['why'] );
        $this->assertSame( 'Connect ' . $names . ' to the WP Consent API in its settings.', $findings[0]['steps'][0] );
        $this->assertSame( 'Or turn on Google consent mode in ' . $names . ', so it tells Google tags what each visitor chose.', $findings[0]['steps'][1] );
    }

    public function silent_tools() {
        return array(
            'Real Cookie Banner'           => array( array( 'real-cookie-banner' => array( 'gcm' => false ) ), 'Real Cookie Banner' ),
            'CookieAdmin'                  => array( array( 'cookieadmin' => array( 'gcm' => '' ) ), 'CookieAdmin' ),
            'CookieYes legacy'             => array( array( 'cookieyes' => array( 'legacy' => true ) ), 'CookieYes' ),
            'Cookie Notice without its app' => array( array( 'cookie-notice' => array( 'gcm' => null ) ), 'Cookie Notice' ),
            'Two of them'                  => array( array( 'cookieadmin' => array(), 'real-cookie-banner' => array() ), 'CookieAdmin, Real Cookie Banner' ),
        );
    }

    public function test_a_consent_tool_without_the_consent_api_is_not_reporting_either() {
        $this->option( ConsentMode::OPTION, 'on' );
        $this->tools( array( 'complianz' => array( 'consent_mode' => false, 'statistics' => '' ) ) );
        ConsentMode::use_api( new WCCT_Test_Fake_Consent_Api( false, '' ) );

        $findings = $this->run_check( new ConsentPluginCheck() );

        $this->assertSame( 'Consent tool detected but not reporting', $findings[0]['found'] );
        $this->assertSame( 'Install the WP Consent API plugin and connect Complianz to it.', $findings[0]['steps'][0] );
    }

    public function test_a_tool_that_sets_a_type_in_the_browser_gives_nothing() {
        $this->option( ConsentMode::OPTION, 'on' );
        ConsentMode::use_api( new WCCT_Test_Fake_Consent_Api( true, '' ) );

        foreach ( array( 'complianz' => array(), 'cookiebot' => array( 'cbid' => '' ), 'cookieyes' => array( 'legacy' => false ) ) as $slug => $signal ) {
            // A silent tool next to it doesn't matter: a type will come.
            $this->tools( array( $slug => $signal, 'real-cookie-banner' => array() ) );

            $this->assertSame( array(), $this->run_check( new ConsentPluginCheck() ), $slug );
        }
    }

    public function test_a_silent_tool_with_a_server_side_type_gives_nothing() {
        $this->option( ConsentMode::OPTION, 'on' );
        $this->tools( array( 'cookie-notice' => array( 'gcm' => array( 'ad_storage' => 'denied' ) ) ) );
        // Cookie Notice with its app's regulations returns the type from wp_get_consent_type.
        ConsentMode::use_api( new WCCT_Test_Fake_Consent_Api( true, 'optin' ) );

        $this->assertSame( array(), $this->run_check( new ConsentPluginCheck() ) );
    }

    public function test_a_tool_that_owns_google_consent_is_named_in_the_working_line() {
        $this->option( ConsentMode::OPTION, 'on' );
        $this->filter(
            'wcct_consent_mode_external_source',
            function () {
                return 'complianz';
            }
        );
        ConsentMode::use_api( new WCCT_Test_Fake_Consent_Api( true, 'optin' ) );

        $check = new ConsentPluginCheck();

        $this->assertSame( array(), $this->run_check( $check ) );
        $this->assertSame( 'Consent signals are handled by Complianz. Conversion Tracking will not set its own defaults.', $check->label() );
    }

    public function test_not_applicable_with_the_switch_off() {
        ConsentMode::use_api( new WCCT_Test_Fake_Consent_Api( false, '' ) );

        $check = new ConsentPluginCheck();

        $this->assertSame( array(), $this->run_check( $check ) );
        $this->assertSame( 'Respect cookie choices is off, so no tag waits for a cookie choice', $check->label() );
        $this->assertArrayNotHasKey( ConsentMode::OPTION, $GLOBALS['wcct_test_options'], 'Never changes the switch' );
    }
}
