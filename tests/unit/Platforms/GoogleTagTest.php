<?php

require_once dirname( __DIR__ ) . '/Fakes/settings-functions.php';

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Platforms\GoogleTag;
use StorePulse\ConversionTracking\Platforms\Pause;

/**
 * Tests for the one Google tag shared by GA4 and Google Ads
 */
class GoogleTagTest extends TestCase {

    const V1 = array(
        'ga4'     => array(
            'enabled' => true,
            0         => array( 'measurement_id' => 'G-ABC123', 'events' => array( 'view_item' => 'on', 'search' => 'on' ) ),
        ),
        'adwords' => array(
            'enabled' => true,
            0         => array( 'account_id' => 'AW-123456789', 'events' => array( 'Purchase' => 'on', 'Purchase-label' => 'PurLabel' ) ),
        ),
    );

    protected function setUp(): void {
        $GLOBALS['wcct_test_options'] = array();
        GoogleTag::reset();
        Pause::reset();
    }

    protected function tearDown(): void {
        unset( $GLOBALS['wcct_test_options'], $GLOBALS['wcct_test_filters'] );
        Pause::reset();
    }

    public function test_2x_settings_are_read_as_one_platform() {
        $GLOBALS['wcct_test_options']['wcct_settings'] = self::V1;

        $ga4 = GoogleTag::settings( 'ga4' );
        $ads = GoogleTag::settings( 'adwords' );

        $this->assertTrue( $ga4['enabled'] );
        $this->assertSame( 'G-ABC123', $ga4['measurement_id'] );
        $this->assertSame( array( 'view_product', 'search', 'purchase' ), $ga4['events'] );
        $this->assertTrue( $ads['enabled'] );
        $this->assertSame( 'PurLabel', $ads['purchase_label'] );
        $this->assertSame( array( 'purchase' ), $ads['events'] );
    }

    public function test_settings_are_read_once_per_request_until_reset() {
        $GLOBALS['wcct_test_options']['wcct_settings'] = self::V1;

        $this->assertTrue( GoogleTag::settings( 'ga4' )['enabled'] );

        $GLOBALS['wcct_test_options']['wcct_settings'] = array();

        $this->assertTrue( GoogleTag::settings( 'ga4' )['enabled'] );

        GoogleTag::reset();

        $this->assertFalse( GoogleTag::settings( 'ga4' )['enabled'] );
    }

    public function test_3x_settings_win_once_written() {
        $GLOBALS['wcct_test_options']['wcct_settings']    = self::V1;
        $GLOBALS['wcct_test_options']['wcct_settings_v3'] = array(
            'platforms' => array(
                'google' => array( 'mode' => 'analytics', 'measurement_id' => 'G-NEW123', 'ads_id' => 'AW-1', 'events' => array( 'purchase' ) ),
            ),
        );

        $this->assertSame( 'G-NEW123', GoogleTag::settings( 'ga4' )['measurement_id'] );
        $this->assertFalse( GoogleTag::settings( 'adwords' )['enabled'] );
    }

    public function test_a_switched_off_2x_google_is_paused() {
        $v1                       = self::V1;
        $v1['ga4']['enabled']     = false;
        $v1['adwords']['enabled'] = false;

        $GLOBALS['wcct_test_options']['wcct_settings'] = $v1;

        $this->assertFalse( GoogleTag::settings( 'ga4' )['enabled'] );
        $this->assertFalse( GoogleTag::settings( 'adwords' )['enabled'] );
    }

    public function test_only_the_switched_on_2x_integration_is_enabled() {
        $v1                   = self::V1;
        $v1['ga4']['enabled'] = false;

        $GLOBALS['wcct_test_options']['wcct_settings'] = $v1;

        $this->assertFalse( GoogleTag::settings( 'ga4' )['enabled'] );
        $this->assertTrue( GoogleTag::settings( 'adwords' )['enabled'] );
    }

    public function test_no_settings_enable_nothing() {
        $this->assertFalse( GoogleTag::settings( 'ga4' )['enabled'] );
        $this->assertSame( array(), GoogleTag::settings( 'adwords' )['events'] );
    }

    public function test_tag_ids_must_start_with_gt() {
        $this->assertSame( 'GT-ABC123', GoogleTag::tag_id( ' gt-abc123 ' ) );
        $this->assertSame( '', GoogleTag::tag_id( 'G-ABC123' ) );
        $this->assertSame( '', GoogleTag::tag_id( 'GT-ABC"' ) );
        $this->assertSame( '', GoogleTag::tag_id( null ) );
    }

    public function test_analytics_ids_come_first() {
        $this->assertSame( array( 'GT-ABC123', 'AW-1' ), GoogleTag::ordered( array( 'AW-1', 'GT-ABC123', 'AW-1' ) ) );
        $this->assertSame( array( 'G-ABC123', 'AW-1' ), GoogleTag::ordered( array( 'AW-1', 'G-ABC123' ) ) );
    }

    public function test_head_html_has_one_loader_and_one_config_per_id() {
        $html = GoogleTag::head_html( array( 'AW-123456789', 'G-ABC123' ) );

        $this->assertSame( 1, substr_count( $html, 'googletagmanager.com/gtag/js' ) );
        $this->assertStringContainsString( '<script async data-no-optimize="1" data-cfasync="false" src="https://www.googletagmanager.com/gtag/js?id=G-ABC123"></script>', $html );
        $this->assertStringContainsString( '<script data-no-optimize="1" data-cfasync="false">', $html );
        $this->assertSame( 1, substr_count( $html, "wcctGtag('js'" ) );
        $this->assertSame( 1, substr_count( $html, "wcctGtag('config', \"G-ABC123\"" ) );
        $this->assertSame( 1, substr_count( $html, "wcctGtag('config', \"AW-123456789\"" ) );
        $this->assertLessThan( strpos( $html, '"AW-123456789"' ), strpos( $html, '"G-ABC123"' ) );
    }

    public function test_head_html_skips_the_loader_when_one_is_on_the_page() {
        $html = GoogleTag::head_html( array( 'G-ABC123' ), false );

        $this->assertStringNotContainsString( 'gtag/js', $html );
        $this->assertStringNotContainsString( "wcctGtag('js'", $html );
        $this->assertStringContainsString( "wcctGtag('config', \"G-ABC123\"", $html );
    }

    public function test_head_html_is_empty_without_ids() {
        $this->assertSame( '', GoogleTag::head_html( array( '', ' ' ) ) );
    }
}
