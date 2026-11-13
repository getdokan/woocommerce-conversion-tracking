<?php

require_once dirname( __DIR__ ) . '/Fakes/settings-functions.php';

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Dashboard\ConnectedAt;
use StorePulse\ConversionTracking\Settings\Schema;
use StorePulse\ConversionTracking\Settings\Values;

/**
 * Tests for when platforms were connected, behind "Connected 5 minutes ago" (#118)
 */
class ConnectedAtTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['wcct_test_options'] = array();
    }

    protected function tearDown(): void {
        unset( $GLOBALS['wcct_test_options'], $GLOBALS['wcct_test_filters'] );
    }

    private static function connected( array $doc ) {
        $schema = Schema::get();

        return ConnectedAt::connected( $schema, Values::get( $schema, $doc ) );
    }

    public function test_connected_platforms_follow_values_get() {
        $this->assertSame( array(), self::connected( array() ) );

        $doc = array(
            'platforms' => array(
                'facebook' => array( 'pixel_id' => '123456789012345' ),
                'twitter'  => array( 'pixel_id' => '   ' ),
                'custom'   => array( 'thankyou_enabled' => true, 'thankyou_script' => '<script>a()</script>' ),
            ),
        );

        $this->assertSame( array( 'facebook', 'custom' ), self::connected( $doc ) );

        // A custom placement switched on without a script, or a script switched off, isn't connected.
        $doc['platforms']['custom'] = array( 'thankyou_enabled' => true, 'thankyou_script' => '' );
        $this->assertSame( array( 'facebook' ), self::connected( $doc ) );

        $doc['platforms']['custom'] = array( 'thankyou_enabled' => false, 'thankyou_script' => '<script>a()</script>' );
        $this->assertSame( array( 'facebook' ), self::connected( $doc ) );
    }

    public function test_it_reads_the_connected_value_the_settings_route_gives() {
        $schema = array( 'platforms' => array( 'facebook' => array(), 'google' => array(), 'twitter' => array() ) );
        $values = array(
            'platforms' => array(
                'facebook' => array( 'connected' => true, 'pixel_id' => '' ),
                'google'   => array( 'connected' => false, 'measurement_id' => 'G-ABC123' ),
            ),
        );

        $this->assertSame( array( 'facebook' ), ConnectedAt::connected( $schema, $values ) );
    }

    public function test_a_save_stamps_newly_connected_platforms_and_forgets_disconnected_ones() {
        $times = ConnectedAt::update( array( 'facebook' => 0, 'google' => 100 ), array( 'facebook', 'google' ), array( 'facebook', 'twitter' ), 500 );

        $this->assertSame( array( 'facebook' => 0, 'twitter' => 500 ), $times );

        // Connected before we kept times: unknown (0), never "just now".
        $this->assertSame( array( 'google' => 0 ), ConnectedAt::update( array(), array( 'google' ), array( 'google' ), 500 ) );
    }

    public function test_reading_fills_unknown_times_and_drops_stale_ones() {
        $this->assertSame( array( 'facebook' => 0 ), ConnectedAt::get( array( 'facebook' ) ) );
        $this->assertSame( array( 'facebook' => 0 ), $GLOBALS['wcct_test_options']['wcct_connected_at'] );

        $GLOBALS['wcct_test_options']['wcct_connected_at'] = array( 'facebook' => 0, 'google' => 300, '' => 5 );

        $this->assertSame( array( 'google' => 300 ), ConnectedAt::get( array( 'google' ) ) );
        $this->assertSame( array( 'google' => 300 ), $GLOBALS['wcct_test_options']['wcct_connected_at'] );
    }
}
