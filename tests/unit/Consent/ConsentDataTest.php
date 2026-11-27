<?php

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Consent\ConsentData;

/**
 * Tests for the Google default, consent tool detection and the allow rule
 */
class ConsentDataTest extends TestCase {

    public function test_regions_are_the_eea_the_uk_and_switzerland() {
        $this->assertCount( 32, ConsentData::REGIONS );
        $this->assertSame( ConsentData::REGIONS, array_unique( ConsentData::REGIONS ) );

        foreach ( array( 'DE', 'FR', 'IE', 'IS', 'LI', 'NO', 'GB', 'CH' ) as $code ) {
            $this->assertContains( $code, ConsentData::REGIONS );
        }

        $this->assertNotContains( 'US', ConsentData::REGIONS );
        $this->assertNotContains( 'UK', ConsentData::REGIONS );
    }

    public function test_google_default_denies_everything_in_the_regions_and_waits_for_an_update() {
        $this->assertSame(
            array(
                'ad_storage'         => 'denied',
                'analytics_storage'  => 'denied',
                'ad_user_data'       => 'denied',
                'ad_personalization' => 'denied',
                'region'             => ConsentData::REGIONS,
                'wait_for_update'    => 500,
            ),
            ConsentData::google_default()
        );
    }

    public function test_no_tool_detected_without_signals() {
        $this->assertFalse( ConsentData::external_source( array() ) );
        $this->assertFalse( ConsentData::external_source( array( 'complianz' => null, 'site-kit' => array( 'status' => 'disabled' ) ) ) );
    }

    /**
     * @dataProvider tools
     */
    public function test_detection_per_tool( $slug, array $signal, $owns ) {
        $this->assertSame( $owns ? $slug : false, ConsentData::external_source( array( $slug => $signal ) ) );
    }

    public function tools() {
        return array(
            'Complianz free'                    => array( 'complianz', array( 'consent_mode' => false, 'statistics' => 'google-analytics' ), false ),
            'Complianz consent mode'            => array( 'complianz', array( 'consent_mode' => true, 'statistics' => 'google-analytics' ), true ),
            'Complianz Google Tag Gateway'      => array( 'complianz', array( 'consent_mode' => false, 'statistics' => 'gtg' ), true ),
            'Complianz consent mode, GA added'  => array( 'complianz', array( 'consent_mode' => true, 'statistics' => 'google-analytics', 'configured' => 'yes' ), true ),
            'Complianz consent mode, GA not added' => array( 'complianz', array( 'consent_mode' => true, 'statistics' => 'google-analytics', 'configured' => 'no' ), false ),
            'Complianz leftover consent mode, Matomo' => array( 'complianz', array( 'consent_mode' => true, 'statistics' => 'matomo', 'configured' => 'yes' ), false ),
            'Complianz leftover consent mode, no statistics' => array( 'complianz', array( 'consent_mode' => true, 'statistics' => '', 'configured' => 'yes' ), false ),
            'Complianz consent mode, GTM added' => array( 'complianz', array( 'consent_mode' => true, 'statistics' => 'google-tag-manager', 'configured' => 'yes' ), true ),
            'Complianz consent mode, GTM not added' => array( 'complianz', array( 'consent_mode' => true, 'statistics' => 'google-tag-manager', 'configured' => 'no' ), false ),
            'Complianz GTG not added still prints' => array( 'complianz', array( 'consent_mode' => false, 'statistics' => 'gtg', 'configured' => 'no' ), true ),
            'CookieYes standalone'              => array( 'cookieyes', array( 'gcm_status' => true, 'connected' => false ), false ),
            'CookieYes connected without GCM'   => array( 'cookieyes', array( 'gcm_status' => false, 'connected' => true ), false ),
            'CookieYes connected with GCM'      => array( 'cookieyes', array( 'gcm_status' => true, 'connected' => true ), true ),
            'CookieYes GCM unset'               => array( 'cookieyes', array( 'gcm_status' => null, 'connected' => null ), false ),
            'Cookiebot without a CBID'          => array( 'cookiebot', array( 'cbid' => '', 'gcm' => '1' ), false ),
            'Cookiebot with GCM on'             => array( 'cookiebot', array( 'cbid' => 'abc-123', 'gcm' => '1' ), true ),
            'Cookiebot toggled off still prints' => array( 'cookiebot', array( 'cbid' => 'abc-123', 'gcm' => '0' ), true ),
            'Cookiebot GCM option missing'      => array( 'cookiebot', array( 'cbid' => 'abc-123', 'gcm' => false ), false ),
            'Cookiebot GCM option empty'        => array( 'cookiebot', array( 'cbid' => 'abc-123', 'gcm' => '' ), false ),
            'Cookiebot network CBID'            => array( 'cookiebot', array( 'cbid' => '', 'network_cbid' => 'net-123', 'gcm' => '1' ), true ),
            'Cookiebot site CBID over network'  => array( 'cookiebot', array( 'cbid' => 'abc-123', 'network_cbid' => '', 'gcm' => '1' ), true ),
            'Cookiebot no site or network CBID' => array( 'cookiebot', array( 'cbid' => '', 'network_cbid' => '', 'gcm' => '1' ), false ),
            'Cookie Notice app default'         => array( 'cookie-notice', array( 'gcm' => array( 'ad_storage' => 'denied' ) ), true ),
            'Cookie Notice without app'         => array( 'cookie-notice', array( 'gcm' => null ), false ),
            'CookieAdmin Pro GCM on'            => array( 'cookieadmin', array( 'gcm' => '1' ), true ),
            'CookieAdmin Pro GCM off'           => array( 'cookieadmin', array( 'gcm' => '' ), false ),
            'Real Cookie Banner GCM on'         => array( 'real-cookie-banner', array( 'gcm' => '1' ), true ),
            'Real Cookie Banner GCM off'        => array( 'real-cookie-banner', array( 'gcm' => false ), false ),
            'Site Kit consent mode on'          => array( 'site-kit', array( 'status' => 'enabled' ), true ),
            'Site Kit consent mode off'         => array( 'site-kit', array( 'status' => 'disabled' ), false ),
            'GA for WooCommerce with a GA ID'   => array( 'ga-for-woocommerce', array( 'ga_id' => 'G-ABC', 'modes' => array( array( 'analytics_storage' => 'denied' ) ) ), true ),
            'GA for WooCommerce without an ID'  => array( 'ga-for-woocommerce', array( 'ga_id' => '', 'modes' => array( array() ) ), false ),
            'GA for WooCommerce defaults off'   => array( 'ga-for-woocommerce', array( 'ga_id' => 'G-ABC', 'modes' => array() ), false ),
        );
    }

    public function test_detected_tools_are_the_active_banners_only() {
        $signals = array(
            'complianz'          => array(),
            'cookieyes'          => null,
            'real-cookie-banner' => array( 'gcm' => false ),
            'site-kit'           => array( 'status' => 'enabled' ),
            'ga-for-woocommerce' => array( 'ga_id' => 'G-ABC' ),
        );

        $this->assertSame( array( 'complianz', 'real-cookie-banner' ), ConsentData::detected_tools( $signals ) );
        $this->assertSame( array( 'complianz' ), ConsentData::type_tools( $signals ) );
    }

    /**
     * @dataProvider type_signals
     */
    public function test_type_tools_are_the_banners_that_set_a_consent_type( array $signals, array $tools ) {
        $this->assertSame( $tools, ConsentData::type_tools( $signals ) );
    }

    public function type_signals() {
        return array(
            'Complianz'          => array( array( 'complianz' => array() ), array( 'complianz' ) ),
            'Cookiebot'          => array( array( 'cookiebot' => array( 'cbid' => '' ) ), array( 'cookiebot' ) ),
            'CookieYes current'  => array( array( 'cookieyes' => array( 'legacy' => false ) ), array( 'cookieyes' ) ),
            'CookieYes legacy'   => array( array( 'cookieyes' => array( 'legacy' => true ) ), array() ),
            'CookieYes unknown'  => array( array( 'cookieyes' => array( 'gcm_status' => true ) ), array() ),
            'Cookie Notice'      => array( array( 'cookie-notice' => array( 'gcm' => array( 'ad_storage' => 'denied' ) ) ), array() ),
            'CookieAdmin'        => array( array( 'cookieadmin' => array( 'gcm' => '1' ) ), array() ),
            'Real Cookie Banner' => array( array( 'real-cookie-banner' => array( 'gcm' => '1' ) ), array() ),
            'Site Kit'           => array( array( 'site-kit' => array( 'status' => 'enabled' ) ), array() ),
        );
    }

    public function test_type_expected() {
        $none = array( 'real-cookie-banner' => array(), 'cookieadmin' => array() );

        $this->assertTrue( ConsentData::type_expected( true, 'optin', array() ), 'A server-side type' );
        $this->assertTrue( ConsentData::type_expected( true, '', array( 'cookiebot' => array() ) ), 'A tool that sets one' );
        $this->assertFalse( ConsentData::type_expected( true, '', $none ), 'Only tools that never set one' );
        $this->assertFalse( ConsentData::type_expected( false, 'optin', array( 'complianz' => array() ) ), 'No WP Consent API' );
    }

    public function test_the_first_tool_in_detection_order_wins() {
        $signals = array(
            'site-kit'  => array( 'status' => 'enabled' ),
            'cookiebot' => array( 'cbid' => 'abc', 'gcm' => '1' ),
        );

        $this->assertSame( 'cookiebot', ConsentData::external_source( $signals ) );
    }

    public function test_source_names() {
        $this->assertSame( 'Complianz', ConsentData::source_name( 'complianz' ) );
        $this->assertSame( 'Site Kit by Google', ConsentData::source_name( 'site-kit' ) );
        $this->assertSame( 'my-cmp', ConsentData::source_name( 'my-cmp' ) );
    }

    /**
     * @dataProvider markup
     */
    public function test_hold_scripts( $html, $held ) {
        $this->assertSame( $held, ConsentData::hold_scripts( $html, 'marketing' ) );
    }

    public function markup() {
        $held = 'type="text/plain" data-wcct-consent="marketing"';

        return array(
            'inline'               => array( "<script>a();</script>", "<script $held>a();</script>" ),
            'javascript type'      => array( '<script type="text/javascript" src="https://x.test/a.js" async></script>', "<script $held src=\"https://x.test/a.js\" async></script>" ),
            'single-quoted type'   => array( "<script type='text/javascript'>a();</script>", "<script $held>a();</script>" ),
            'module'               => array( '<SCRIPT type=module>m();</SCRIPT>', "<script $held data-wcct-type=\"module\">m();</SCRIPT>" ),
            'several'              => array( "<script>a();</script>\n<p>x</p><script>b();</script>", "<script $held>a();</script>\n<p>x</p><script $held>b();</script>" ),
            'json is not code'     => array( '<script type="application/ld+json">{}</script>', '<script type="application/ld+json">{}</script>' ),
            'no script'            => array( '<img src="https://x.test/p.gif">', '<img src="https://x.test/p.gif">' ),
            'not a script tag'     => array( '<scripts>', '<scripts>' ),
        );
    }

    public function test_allows_everything_without_a_consent_type() {
        $this->assertTrue( ConsentData::allows( '', false ) );
        $this->assertTrue( ConsentData::allows( null, false ) );
    }

    public function test_with_a_consent_type_the_api_decides() {
        $this->assertTrue( ConsentData::allows( 'optin', true ) );
        $this->assertFalse( ConsentData::allows( 'optin', false ) );
        $this->assertFalse( ConsentData::allows( 'optout', false ) );
    }
}
