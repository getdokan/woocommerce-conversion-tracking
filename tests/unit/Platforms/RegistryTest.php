<?php

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Events\Event;
use StorePulse\ConversionTracking\Events\Pipeline;
use StorePulse\ConversionTracking\Platforms\Registry;

/**
 * Tests for platform registration, the paused flag and transports
 */
class RegistryTest extends TestCase {

    protected function tearDown(): void {
        unset( $GLOBALS['wcct_test_options'], $GLOBALS['wcct_test_filters'] );
    }

    public function test_normalise_keys_by_id_and_drops_invalid_entries() {
        $facebook = new WCCT_Test_Fake_Integration( 'facebook' );
        $twitter  = new WCCT_Test_Fake_Integration( 'twitter' );
        $no_id    = new WCCT_Test_Fake_Integration( '' );

        $platforms = Registry::normalise( array( 'google' => $facebook, 'x' => 'not a platform', $no_id, 5 => $twitter, null ) );

        $this->assertSame( array( 'facebook' => $facebook, 'twitter' => $twitter ), $platforms );
        $this->assertSame( array(), Registry::normalise( 'broken' ) );
    }

    public function test_a_later_platform_replaces_the_same_id_in_place() {
        $first  = new WCCT_Test_Fake_Integration( 'facebook' );
        $other  = new WCCT_Test_Fake_Integration( 'ga4' );
        $second = new WCCT_Test_Fake_Integration( 'facebook' );

        $platforms = Registry::normalise( array( $first, $other, $second ) );

        $this->assertSame( array( 'facebook', 'ga4' ), array_keys( $platforms ) );
        $this->assertSame( $second, $platforms['facebook'] );
    }

    public function test_transports_default_to_browser() {
        $this->assertSame( array( 'browser' ), ( new WCCT_Test_Fake_Integration( 'facebook' ) )->get_transports() );
    }

    public function test_platforms_without_the_browser_transport_are_left_out() {
        $browser = new WCCT_Test_Fake_Integration( 'facebook' );
        $server  = new WCCT_Test_Fake_Integration( 'capi', true, array( 'server' ) );

        $this->assertSame( array( 'facebook' => $browser ), Registry::browser( Registry::normalise( array( $browser, $server ) ) ) );
    }

    public function test_paused_platform_is_not_active() {
        $GLOBALS['wcct_test_options']['wcct_paused_platforms'] = array( 'facebook' );

        $facebook = new WCCT_Test_Fake_Integration( 'facebook' );
        $twitter  = new WCCT_Test_Fake_Integration( 'twitter' );
        $disabled = new WCCT_Test_Fake_Integration( 'adwords', false );

        $this->assertTrue( $facebook->is_enabled() );
        $this->assertTrue( $facebook->is_paused() );
        $this->assertFalse( $twitter->is_paused() );
        $this->assertSame( array( 'twitter' ), array_keys( Registry::active( Registry::normalise( array( $facebook, $twitter, $disabled ) ) ) ) );
    }

    public function test_paused_flag_is_filterable() {
        $GLOBALS['wcct_test_filters']['wcct_platform_paused'] = function ( $paused, $id ) {
            return 'twitter' === $id;
        };

        $this->assertTrue( ( new WCCT_Test_Fake_Integration( 'twitter' ) )->is_paused() );
        $this->assertFalse( ( new WCCT_Test_Fake_Integration( 'facebook' ) )->is_paused() );
    }

    public function test_paused_option_stored_serialized_is_read() {
        $GLOBALS['wcct_test_options']['wcct_paused_platforms'] = serialize( array( 'ga4' ) );

        $this->assertTrue( ( new WCCT_Test_Fake_Integration( 'ga4' ) )->is_paused() );
    }

    public function test_markup_is_kept_apart_from_scripts() {
        $platforms = array( new WCCT_Test_Fake_Integration( 'markup' ), new WCCT_Test_Fake_Integration( 'twitter' ) );
        $pipeline  = new Pipeline(
            function () use ( $platforms ) {
                return $platforms;
            }
        );
        $events    = array( new Event( Event::SIGN_UP ) );

        $this->assertSame( array( 'markup' => "<img src=\"x\">\n" ), $pipeline->build_footer_markup( $events ) );
    }

    public function test_malformed_paused_option_pauses_nothing() {
        $GLOBALS['wcct_test_options']['wcct_paused_platforms'] = 'facebook';

        $this->assertFalse( ( new WCCT_Test_Fake_Integration( 'facebook' ) )->is_paused() );
    }

    public function test_paused_platform_gets_no_footer_script() {
        $GLOBALS['wcct_test_options']['wcct_paused_platforms'] = array( 'facebook' );

        $platforms = array( new WCCT_Test_Fake_Integration( 'facebook' ), new WCCT_Test_Fake_Integration( 'twitter' ) );
        $pipeline  = new Pipeline(
            function () use ( $platforms ) {
                return Registry::active( Registry::normalise( $platforms ) );
            }
        );

        $pipeline->add_script( 'facebook', 'queued();' );
        $scripts = $pipeline->build_footer_scripts( array( new Event( Event::VIEW_PRODUCT, array( 'product_ids' => array( 9 ) ) ) ) );

        $this->assertSame( array( 'twitter' => "twitter.view(9);\n" ), $scripts );
    }

    public function test_footer_scripts_follow_platform_order_with_queued_code_first() {
        $platforms = array( new WCCT_Test_Fake_Integration( 'facebook' ), new WCCT_Test_Fake_Integration( 'twitter' ) );
        $pipeline  = new Pipeline(
            function () use ( $platforms ) {
                return $platforms;
            }
        );

        $pipeline->add_script( 'twitter', 'added();' );
        $pipeline->add_script( 'twitter', '' );
        $pipeline->add_script( 'twitter', null );

        $scripts = $pipeline->build_footer_scripts( array( new Event( Event::VIEW_PRODUCT, array( 'product_ids' => array( 4 ) ) ), new Event( Event::SEARCH ) ) );

        $this->assertSame( array( 'facebook', 'twitter' ), array_keys( $scripts ) );
        $this->assertSame( "facebook.view(4);\n", $scripts['facebook'] );
        $this->assertSame( "added();\ntwitter.view(4);\n", $scripts['twitter'] );
        $this->assertSame( array(), $pipeline->build_footer_scripts( array() ) );
    }

    public function test_unsupported_events_render_nothing() {
        $this->assertSame( '', ( new WCCT_Test_Fake_Integration( 'facebook' ) )->get_event_script( new Event( Event::ADD_TO_CART ) ) );
    }

    public function test_handle_names() {
        $this->assertSame( 'wcct-bing-ads-events', Pipeline::handle( 'bing_ads' ) );
        $this->assertSame( 'wcct-facebook-events', Pipeline::handle( 'facebook' ) );
    }
}
