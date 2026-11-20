<?php

require_once dirname( __DIR__ ) . '/Fakes/settings-functions.php';

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Alerts\Alerts;
use StorePulse\ConversionTracking\Alerts\Types;
use StorePulse\ConversionTracking\Settings\Schema;
use StorePulse\ConversionTracking\Settings\Values;

/**
 * Tests for the alert types registry and the Settings > Alerts switches (#117)
 */
class AlertsTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['wcct_test_options'] = array();
    }

    protected function tearDown(): void {
        unset( $GLOBALS['wcct_test_options'], $GLOBALS['wcct_test_filters'] );
    }

    public function test_3_0_registers_not_confirmed() {
        $types = Types::all();

        $this->assertSame( array( 'not_confirmed' ), array_keys( $types ) );
        $this->assertSame( 'not_confirmed', $types['not_confirmed']['key'] );
        $this->assertSame( 'Not confirmed purchases, on the dashboard', $types['not_confirmed']['label'] );
        $this->assertStringContainsString( 'within 10 minutes', $types['not_confirmed']['description'] );
    }

    public function test_the_filter_registers_types_and_drops_malformed_ones() {
        $GLOBALS['wcct_test_filters']['wcct_alert_types'] = function ( $types ) {
            $types['send_failed'] = array( 'label' => 'Send failed', 'description' => 'A platform refused an event.' );
            $types[]              = array( 'key' => 'Token-Expired', 'label' => 'Token expired' );
            $types['no_label']    = array( 'description' => 'Missing its label.' );
            $types['doctor_new']  = array( 'label' => 'Taken by the Doctor switch' );
            $types['not_array']   = 'Nope';

            return $types;
        };

        $this->assertSame( array( 'not_confirmed', 'send_failed', 'tokenexpired' ), array_keys( Types::all() ) );
        $this->assertSame( '', Types::all()['tokenexpired']['description'] );
        $this->assertTrue( Types::exists( 'send_failed' ) );
        $this->assertFalse( Types::exists( 'no_label' ) );

        $GLOBALS['wcct_test_filters']['wcct_alert_types'] = function () {
            return 'broken';
        };

        $this->assertSame( array( 'not_confirmed' ), array_keys( Types::all() ) );
    }

    public function test_a_filter_cannot_drop_the_built_in_type() {
        $GLOBALS['wcct_test_filters']['wcct_alert_types'] = function () {
            return array( 'send_failed' => array( 'label' => 'Send failed' ) );
        };

        $this->assertSame( array( 'not_confirmed', 'send_failed' ), array_keys( Types::all() ) );
        $this->assertSame( 'Not confirmed purchases, on the dashboard', Types::all()['not_confirmed']['label'] );
        $this->assertSame( array( 'not_confirmed' => true, 'send_failed' => true, 'doctor_new' => true ), Alerts::states() );

        // A broken entry is replaced, a relabelled one kept.
        $GLOBALS['wcct_test_filters']['wcct_alert_types'] = function () {
            return array( 'not_confirmed' => array( 'label' => '' ) );
        };

        $this->assertSame( 'Not confirmed purchases, on the dashboard', Types::all()['not_confirmed']['label'] );

        $GLOBALS['wcct_test_filters']['wcct_alert_types'] = function () {
            return array( 'not_confirmed' => array( 'label' => 'Unconfirmed sales' ) );
        };

        $this->assertSame( 'Unconfirmed sales', Types::all()['not_confirmed']['label'] );
    }

    public function test_a_switch_per_type_then_the_doctor_in_the_global_schema() {
        $GLOBALS['wcct_test_filters']['wcct_alert_types'] = function ( $types ) {
            return array_merge( $types, array( 'send_failed' => array( 'label' => 'Send failed', 'description' => 'D' ) ) );
        };

        $fields = Schema::fields( Schema::get()['global'] );
        $alerts = array_values( array_filter( $fields, function ( $field ) {
            return Alerts::SECTION === $field['section_id'];
        } ) );

        $this->assertSame( array( 'alert_not_confirmed', 'alert_send_failed', 'alert_doctor_new' ), array_column( $alerts, 'id' ) );
        $this->assertSame( array( 'not_confirmed', 'send_failed', 'doctor_new' ), array_column( $alerts, 'alert' ) );
        $this->assertSame( array( 'switch', 'switch', 'switch' ), array_column( $alerts, 'variant' ) );
        $this->assertSame( array( true, true, true ), array_column( $alerts, 'default' ) );
        $this->assertSame( 'Doctor found something new', $alerts[2]['label'] );
        $this->assertSame( 'After a plugin is activated, a quiet re-check. Dashboard only, never a popup.', $alerts[2]['description'] );
    }

    public function test_switches_are_on_until_turned_off() {
        $this->assertSame( array( 'not_confirmed' => true, 'doctor_new' => true ), Alerts::states() );

        $GLOBALS['wcct_test_options']['wcct_settings_v3'] = array( 'alert_not_confirmed' => false, 'alert_doctor_new' => true );

        $this->assertSame( array( 'not_confirmed' => false, 'doctor_new' => true ), Alerts::states() );
        $this->assertFalse( Alerts::is_on( 'not_confirmed' ) );

        // Unreadable values read as on; a doc passed in wins over the stored one.
        $this->assertTrue( Alerts::is_on( 'not_confirmed', array( 'alert_not_confirmed' => 'maybe' ) ) );
        $this->assertTrue( Alerts::is_on( 'not_confirmed', array( 'alert_not_confirmed' => array( false ) ) ) );
        $this->assertFalse( Alerts::is_on( 'doctor_new', array( 'alert_doctor_new' => 'no' ) ) );
        $this->assertFalse( Alerts::is_on( 'doctor_new', array( 'alert_doctor_new' => 0 ) ) );
    }

    public function test_the_settings_route_saves_and_reads_the_switches() {
        $schema = Schema::get();
        $saved  = Values::apply( $schema, array(), array( 'alert_not_confirmed' => false, 'alert_doctor_new' => 'off' ), function () {
            return true;
        } );

        $this->assertSame( array(), $saved['errors'] );
        $this->assertFalse( $saved['doc']['alert_not_confirmed'] );
        $this->assertFalse( $saved['doc']['alert_doctor_new'] );

        $values = Values::get( $schema, array() );

        $this->assertTrue( $values['alert_not_confirmed'] );
        $this->assertTrue( $values['alert_doctor_new'] );
    }

    public function test_the_doctor_switch_stops_the_quiet_recheck() {
        $this->assertTrue( Alerts::filter_recheck( true ) );

        $GLOBALS['wcct_test_options']['wcct_settings_v3'] = array( 'alert_doctor_new' => false );

        $this->assertFalse( Alerts::filter_recheck( true ) );

        // The not confirmed switch has nothing to do with the re-check.
        $GLOBALS['wcct_test_options']['wcct_settings_v3'] = array( 'alert_not_confirmed' => false );

        $this->assertTrue( Alerts::filter_recheck( true ) );
        $this->assertFalse( Alerts::filter_recheck( false ) );
    }
}
