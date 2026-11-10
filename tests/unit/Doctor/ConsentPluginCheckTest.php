<?php

require_once __DIR__ . '/DoctorCheckTestCase.php';

use StorePulse\ConversionTracking\Consent\ConsentMode;
use StorePulse\ConversionTracking\Doctor\Checks\ConsentPluginCheck;

/**
 * "Respect cookie choices" on with no consent plugin setting a consent type
 */
class ConsentPluginCheckTest extends DoctorCheckTestCase {

    public function test_switch_on_without_a_consent_plugin_gives_the_finding() {
        $this->option( ConsentMode::OPTION, 'on' );
        ConsentMode::use_api( new WCCT_Test_Fake_Consent_Api( true, '' ) );
        // Something hooks wp_get_consent_type but sets no type: the runtime expects one, so tags wait.
        $this->filter(
            'wp_get_consent_type',
            function ( $type ) {
                return $type;
            }
        );

        $findings = $this->run_check( new ConsentPluginCheck() );

        $this->assertCount( 1, $findings );
        $this->assertSame( 'Cookie choices are on, but no consent plugin is connected', $findings[0]['found'] );
        $this->assertSame( 'Your tags wait for a cookie choice that never comes, so those visitors\' sales aren\'t tracked.', $findings[0]['why'] );
        $this->assertSame( array( 'All platforms', 'Europe' ), $findings[0]['tags'] );
        $this->assertSame( array( 'link', 'See compatible plugins', true, '' ), array( $findings[0]['action']['type'], $findings[0]['action']['label'], $findings[0]['action']['external'], $findings[0]['action']['confirm'] ) );
    }

    public function test_without_a_consent_type_expected_the_tags_run_and_the_copy_says_so() {
        $this->option( ConsentMode::OPTION, 'on' );

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
