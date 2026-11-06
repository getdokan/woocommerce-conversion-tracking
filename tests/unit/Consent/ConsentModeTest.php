<?php

require_once dirname( __DIR__ ) . '/Fakes/settings-functions.php';

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Consent\ConsentMode;
use StorePulse\ConversionTracking\Settings\Schema;
use StorePulse\ConversionTracking\Settings\Store;
use StorePulse\ConversionTracking\Settings\Values;

/**
 * Tests for the consent switch, wcct_consent_allows(), the status data and the head script
 */
class ConsentModeTest extends TestCase {

    protected function tearDown(): void {
        unset( $GLOBALS['wcct_test_options'], $GLOBALS['wcct_test_filters'] );
        ConsentMode::use_api( null );
    }

    private function turn_on() {
        $GLOBALS['wcct_test_options'][ ConsentMode::OPTION ] = 'on';
    }

    public function test_off_by_default() {
        $this->assertFalse( ConsentMode::is_enabled() );
        $this->assertSame( '', ConsentMode::head_script() );
    }

    public function test_option_and_filter_turn_it_on() {
        $this->turn_on();
        $this->assertTrue( ConsentMode::is_enabled() );

        $GLOBALS['wcct_test_filters']['wcct_consent_mode_enabled'] = function () {
            return false;
        };
        $this->assertFalse( ConsentMode::is_enabled() );
    }

    public function test_set_enabled_writes_the_settings_and_the_mirror() {
        ConsentMode::set_enabled( true );
        $this->assertSame( 'on', get_option( ConsentMode::OPTION ) );
        $this->assertTrue( get_option( Store::OPTION )['consent_mode'] );

        ConsentMode::set_enabled( false );
        $this->assertSame( 'off', get_option( ConsentMode::OPTION ) );
        $this->assertFalse( get_option( Store::OPTION )['consent_mode'] );
    }

    public function test_set_enabled_leaves_the_settings_to_a_pending_migration() {
        $GLOBALS['wcct_test_options']['wcct_settings'] = array( 'facebook' => array( 'enabled' => true ) );

        ConsentMode::set_enabled( true );

        $this->assertSame( 'on', get_option( ConsentMode::OPTION ) );
        $this->assertFalse( get_option( Store::OPTION ) );
    }

    public function test_a_settings_save_mirrors_only_a_changed_switch() {
        ConsentMode::sync_from_settings( array( 'consent_mode' => true ), array() );
        $this->assertTrue( ConsentMode::is_enabled() );

        // Settings without consent_mode, or with it unchanged, leave the mirror alone.
        $GLOBALS['wcct_test_options'][ ConsentMode::OPTION ] = 'off';
        ConsentMode::sync_from_settings( array( 'platforms' => array() ), array() );
        ConsentMode::sync_from_settings( array( 'consent_mode' => true ), array( 'consent_mode' => true ) );
        $this->assertFalse( ConsentMode::is_enabled() );

        ConsentMode::sync_from_settings( array( 'consent_mode' => false ), array( 'consent_mode' => true ) );
        $this->assertSame( 'off', get_option( ConsentMode::OPTION ) );
    }

    public function test_an_unrelated_save_keeps_the_switch_on() {
        ConsentMode::set_enabled( true );

        $old    = Store::load();
        $result = Values::apply(
            Schema::get(),
            $old,
            array( 'platforms' => array( 'facebook' => array( 'pixel_id' => '1234567890123456' ) ) ),
            function () {
                return true;
            }
        );

        $this->assertSame( array(), $result['errors'] );
        Store::save( $result['doc'] );
        ConsentMode::sync_from_settings( $result['doc'], $old );

        $this->assertTrue( ConsentMode::is_enabled() );
        $this->assertTrue( Store::load()['consent_mode'] );
    }

    public function test_allows_everything_with_the_switch_off() {
        ConsentMode::use_api( new WCCT_Test_Fake_Consent_Api( true, 'optin', array() ) );

        $this->assertTrue( wcct_consent_allows( 'marketing' ) );
    }

    public function test_allows_everything_without_the_consent_api() {
        $this->turn_on();
        ConsentMode::use_api( new WCCT_Test_Fake_Consent_Api( false ) );

        $this->assertTrue( wcct_consent_allows( 'marketing' ) );
    }

    public function test_allows_everything_when_no_consent_plugin_sets_a_type() {
        $this->turn_on();
        $api = new WCCT_Test_Fake_Consent_Api( true, '', array() );
        ConsentMode::use_api( $api );

        $this->assertTrue( wcct_consent_allows( 'marketing' ) );
        $this->assertTrue( wcct_consent_allows( 'statistics' ) );
        $this->assertSame( array(), $api->asked );
    }

    public function test_with_a_consent_type_the_api_decides_per_category() {
        $this->turn_on();
        ConsentMode::use_api( new WCCT_Test_Fake_Consent_Api( true, 'optin', array( 'statistics' => true ) ) );

        $this->assertTrue( wcct_consent_allows( 'statistics' ) );
        $this->assertFalse( wcct_consent_allows( 'marketing' ) );
    }

    public function test_the_wcct_consent_allows_filter_has_the_last_word() {
        $this->turn_on();
        ConsentMode::use_api( new WCCT_Test_Fake_Consent_Api( true, 'optin', array() ) );
        $GLOBALS['wcct_test_filters']['wcct_consent_allows'] = function ( $allowed, $category ) {
            return 'marketing' === $category;
        };

        $this->assertTrue( wcct_consent_allows( 'marketing' ) );
        $this->assertFalse( wcct_consent_allows( 'statistics' ) );
    }

    public function test_integrations_ask_for_their_category() {
        $this->turn_on();
        ConsentMode::use_api( new WCCT_Test_Fake_Consent_Api( true, 'optin', array( 'marketing' => true ) ) );

        $this->assertSame( 'marketing', ( new WCCT_Test_Fake_Integration( 'facebook' ) )->get_consent_category() );
        $this->assertTrue( ( new WCCT_Test_Fake_Integration( 'facebook' ) )->consent_allows() );
    }

    public function test_status_without_a_consent_tool() {
        $this->turn_on();
        ConsentMode::use_api( new WCCT_Test_Fake_Consent_Api( false ) );

        $this->assertSame(
            array(
                'enabled'                 => true,
                'external_source'         => false,
                'external_source_name'    => '',
                'owns_google_consent'     => true,
                'wp_consent_api'          => false,
                'complianz_blocks_google' => false,
                'message'                 => '',
            ),
            ConsentMode::status()
        );
    }

    public function test_external_source_filter_names_the_tool() {
        $this->turn_on();
        ConsentMode::use_api( new WCCT_Test_Fake_Consent_Api( true ) );
        $GLOBALS['wcct_test_filters']['wcct_consent_mode_external_source'] = function () {
            return 'complianz';
        };

        $status = ConsentMode::status();

        $this->assertSame( 'complianz', $status['external_source'] );
        $this->assertSame( 'Complianz', $status['external_source_name'] );
        $this->assertFalse( $status['owns_google_consent'] );
        $this->assertSame( 'Consent signals are handled by Complianz. Conversion Tracking will not set its own defaults.', $status['message'] );
    }

    public function test_external_source_filter_can_force_ours() {
        $GLOBALS['wcct_test_filters']['googlesitekit_consent_mode_status'] = function () {
            return 'enabled';
        };
        $this->assertSame( 'site-kit', ConsentMode::external_source() );

        $GLOBALS['wcct_test_filters']['wcct_consent_mode_external_source'] = function () {
            return false;
        };
        $this->assertFalse( ConsentMode::external_source() );
    }

    public function test_head_script_sets_our_default_before_the_runtime() {
        $this->turn_on();
        ConsentMode::use_api( new WCCT_Test_Fake_Consent_Api( true ) );

        $js = ConsentMode::head_script();

        $this->assertStringStartsWith( "window.dataLayer = window.dataLayer || [];\n", $js );
        $this->assertStringContainsString( "if (dl[i] && dl[i][0] === 'consent' && dl[i][1] === 'default') return;", $js );
        $this->assertStringContainsString( "g('consent', 'default', {\"ad_storage\":\"denied\",\"analytics_storage\":\"denied\",\"ad_user_data\":\"denied\",\"ad_personalization\":\"denied\",\"region\":[\"AT\",", $js );
        $this->assertStringContainsString( "\"GB\",\"CH\"],\"wait_for_update\":500});", $js );
        $this->assertStringContainsString( "g('set', 'ads_data_redaction', true);", $js );
        $this->assertStringContainsString( 'window.wcctConsentConfig = {"api":true,"bridge":true,"cookiePrefix":"wp_consent","type":"","typeExpected":false};', $js );
        $this->assertStringContainsString( 'root.wcctConsent = lib.create(', $js );
        $this->assertLessThan( strpos( $js, 'wcctConsentConfig' ), strpos( $js, "'consent', 'default'" ) );
    }

    public function test_a_server_side_consent_type_is_passed_and_expected() {
        $this->turn_on();
        ConsentMode::use_api( new WCCT_Test_Fake_Consent_Api( true, 'optin' ) );

        $this->assertStringContainsString( '"type":"optin","typeExpected":true}', ConsentMode::head_script() );
    }

    public function test_a_type_is_expected_when_something_filters_it() {
        $this->turn_on();
        ConsentMode::use_api( new WCCT_Test_Fake_Consent_Api( true, '' ) );
        $GLOBALS['wcct_test_filters']['wp_get_consent_type'] = function ( $type ) {
            return $type;
        };

        $this->assertStringContainsString( '"type":"","typeExpected":true}', ConsentMode::head_script() );
    }

    public function test_no_type_is_expected_without_the_consent_api() {
        $this->turn_on();
        ConsentMode::use_api( new WCCT_Test_Fake_Consent_Api( false, 'optin' ) );

        $this->assertStringContainsString( '"type":"","typeExpected":false}', ConsentMode::head_script() );
    }

    public function test_no_default_when_the_defaults_cannot_be_encoded() {
        $this->turn_on();
        ConsentMode::use_api( new WCCT_Test_Fake_Consent_Api( false ) );
        $GLOBALS['wcct_test_filters']['wcct_consent_defaults'] = function () {
            return array( 'region' => array( "\xB1\x31" ) );
        };

        $this->assertSame( '', ConsentMode::google_default_script() );
        $this->assertStringNotContainsString( "'consent', 'default'", ConsentMode::head_script() );
    }

    public function test_an_unknown_external_source_slug_is_sanitised() {
        $GLOBALS['wcct_test_filters']['wcct_consent_mode_external_source'] = function () {
            return '<b>My CMP</b>';
        };

        $this->assertSame( 'bmycmpb', ConsentMode::external_source() );
        $this->assertSame( 'Consent signals are handled by bmycmpb. Conversion Tracking will not set its own defaults.', ConsentMode::status()['message'] );
    }

    public function test_hold_markup_only_with_the_switch_on() {
        $html = "<script>ct('purchase');</script>";

        $this->assertSame( $html, ConsentMode::hold_markup( $html, 'marketing' ) );

        $this->turn_on();
        $this->assertSame( "<script type=\"text/plain\" data-wcct-consent=\"marketing\">ct('purchase');</script>", ConsentMode::hold_markup( $html, 'marketing' ) );
    }

    public function test_gate_runner_holds_each_platforms_queued_code() {
        $runner = "for (var platform in entries[i].scripts) {\n                var script = document.createElement('script');\n                script.setAttribute('data-no-optimize', '1');\n                script.text = entries[i].scripts[platform];\n                document.body.appendChild(script);\n            }";

        $this->assertSame( $runner, ConsentMode::gate_runner( $runner, array( 'facebook' => 'marketing' ) ) );

        $this->turn_on();
        $this->assertSame(
            "for (var platform in entries[i].scripts) {\n                if (window.wcctConsent) { window.wcctConsent.inline(({\"facebook\":\"marketing\",\"ga4\":\"statistics\"})[platform] || 'marketing', entries[i].scripts[platform]); }\n            }",
            ConsentMode::gate_runner( $runner, array( 'facebook' => 'marketing', 'ga4' => 'statistics' ) )
        );
        $this->assertSame( '', ConsentMode::gate_runner( 'changed runner', array() ) );
    }

    public function test_no_bridge_without_the_consent_api() {
        $this->turn_on();
        ConsentMode::use_api( new WCCT_Test_Fake_Consent_Api( false ) );

        $this->assertStringContainsString( 'window.wcctConsentConfig = {"api":false,"bridge":false,', ConsentMode::head_script() );
    }

    public function test_no_default_and_no_bridge_when_a_tool_owns_google_consent() {
        $this->turn_on();
        ConsentMode::use_api( new WCCT_Test_Fake_Consent_Api( true ) );
        $GLOBALS['wcct_test_filters']['wcct_consent_mode_external_source'] = function () {
            return 'cookiebot';
        };

        $js = ConsentMode::head_script();

        $this->assertStringNotContainsString( "'consent', 'default'", $js );
        $this->assertStringNotContainsString( 'ads_data_redaction', $js );
        $this->assertStringStartsWith( 'window.wcctConsentConfig = {"api":true,"bridge":false,', $js );
    }

    public function test_an_empty_defaults_filter_prints_no_default() {
        $this->turn_on();
        ConsentMode::use_api( new WCCT_Test_Fake_Consent_Api( false ) );
        $GLOBALS['wcct_test_filters']['wcct_consent_defaults'] = function () {
            return array();
        };

        $this->assertStringStartsWith( 'window.wcctConsentConfig', ConsentMode::head_script() );
    }

    public function test_js_helpers_are_empty_with_the_switch_off() {
        $this->assertSame( '', ConsentMode::sync_js( 'marketing', 'x();' ) );
        $this->assertSame( '', ConsentMode::when_js( 'marketing', 'x();' ) );
        $this->assertSame( '', ConsentMode::script_js( 'marketing', '//example.com/a.js' ) );
        $this->assertSame( '', ConsentMode::wrap_js( 'marketing', 'wcfbq' ) );
    }

    public function test_js_helpers_call_the_runtime_without_ampersands() {
        $this->turn_on();

        $this->assertSame( 'if (window.wcctConsent) { window.wcctConsent.sync("marketing", function (granted, first) { x(); }); }', ConsentMode::sync_js( 'marketing', 'x();' ) );
        $this->assertSame( 'if (window.wcctConsent) { window.wcctConsent.when("statistics", function () { x(); }); }', ConsentMode::when_js( 'statistics', 'x();' ) );
        $this->assertSame( 'if (window.wcctConsent) { window.wcctConsent.script("marketing", "//example.com/a.js"); }', ConsentMode::script_js( 'marketing', '//example.com/a.js' ) );
        $this->assertSame( 'if (window.wcctConsent) { window.wcctConsent.script("marketing", "//example.com/a.js", function () { x(); }); }', ConsentMode::script_js( 'marketing', '//example.com/a.js', 'x();' ) );
        $this->assertSame( 'if (window.wcctConsent) { window.wcctConsent.wrap("wcfbq", "marketing"); }', ConsentMode::wrap_js( 'marketing', 'wcfbq' ) );
    }
}
