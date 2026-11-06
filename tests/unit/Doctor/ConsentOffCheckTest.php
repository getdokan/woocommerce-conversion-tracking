<?php

require_once dirname( __DIR__ ) . '/Fakes/doctor-functions.php';

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Consent\ConsentMode;
use StorePulse\ConversionTracking\Doctor\Checks\ConsentOffCheck;
use StorePulse\ConversionTracking\Doctor\Context;

/**
 * Tests for the informational "Consent mode is off" check
 */
class ConsentOffCheckTest extends TestCase {

    protected function tearDown(): void {
        unset( $GLOBALS['wcct_test_options'], $GLOBALS['wcct_test_filters'] );
    }

    public function test_switch_off_gives_a_note_linking_to_the_switch() {
        $findings = ( new ConsentOffCheck() )->run( new Context( 0 ) );

        $this->assertCount( 1, $findings );

        $finding = $findings[0]->to_array();

        $this->assertSame( 'consent-off', $finding['check'] );
        $this->assertSame( 'Consent mode is off', $finding['found'] );
        $this->assertSame( 'info', $finding['severity'] );
        $this->assertSame( 'sure', $finding['confidence'] );
        $this->assertSame( array( 'link', '#/platforms', '' ), array( $finding['action']['type'], $finding['action']['url'], $finding['action']['confirm'] ) );
        $this->assertFalse( $findings[0]->is_warning() );
    }

    public function test_switch_on_gives_nothing() {
        $GLOBALS['wcct_test_options'][ ConsentMode::OPTION ] = 'on';

        $this->assertSame( array(), ( new ConsentOffCheck() )->run( new Context( 0 ) ) );
        $this->assertSame( 'Respect cookie choices is on', ( new ConsentOffCheck() )->label() );
    }

    public function test_the_check_never_turns_the_switch_on() {
        ( new ConsentOffCheck() )->run( new Context( 0 ) );

        $this->assertArrayNotHasKey( ConsentMode::OPTION, isset( $GLOBALS['wcct_test_options'] ) ? $GLOBALS['wcct_test_options'] : array() );
    }
}
