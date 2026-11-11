<?php

require_once dirname( __DIR__, 3 ) . '/includes/integrations/class-integration-google.php';

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Consent\ConsentMode;
use StorePulse\ConversionTracking\Events\EventFactory;
use StorePulse\ConversionTracking\Platforms\EnhancedConversions;

if ( ! function_exists( 'wc_get_order' ) ) {
    function wc_get_order( $order_id ) {
        return isset( $GLOBALS['wcct_test_orders'][ $order_id ] ) ? $GLOBALS['wcct_test_orders'][ $order_id ] : false;
    }
}

/**
 * Order with the billing details enhanced conversions read.
 */
class WCCT_Test_Billing_Order extends WCCT_Test_Fake_Order {

    public $email   = 'Casey.Tester@Gmail.com';
    public $phone   = '+1 415 555 0100';
    public $country = 'US';

    public function get_billing_email() {
        return $this->email;
    }

    public function get_billing_phone() {
        return $this->phone;
    }

    public function get_billing_country() {
        return $this->country;
    }

    public function get_customer_id() {
        return 0;
    }

    public function __call( $name, $args ) {
        // Other billing getters (name, city, state, postcode).
        return '';
    }
}

/**
 * Google Ads with the switch, the verified order and the settings set by the test.
 */
class WCCT_Test_Google_Integration extends WCCT_Integration_Google {

    public $on       = true;
    public $verified = false;

    public function enhanced_conversions_on() {
        return $this->on;
    }

    protected function verified_order() {
        return $this->verified;
    }

    public function get_ads_settings() {
        return array(
            'enabled'              => true,
            'account_id'           => 'AW-123456789',
            'purchase_label'       => 'AbCdEfGh12',
            'registration_label'   => '',
            'enhanced_conversions' => $this->on,
            'events'               => array( 'purchase' ),
        );
    }
}

/**
 * Tests for Google Ads enhanced conversions: hashed user_data of the verified order, before the conversion, under consent
 */
class EnhancedConversionsTest extends TestCase {

    protected function tearDown(): void {
        unset( $GLOBALS['wcct_test_options'], $GLOBALS['wcct_test_filters'], $GLOBALS['wcct_test_orders'] );
    }

    private function consent_mode( bool $on ) {
        $GLOBALS['wcct_test_options'][ ConsentMode::OPTION ] = $on ? 'on' : 'off';
    }

    private function order( array $props = array() ) {
        $order = new WCCT_Test_Billing_Order( array_merge( array( 'id' => 31, 'key' => 'wc_order_ec', 'total' => '20.00' ), $props ) );

        $GLOBALS['wcct_test_orders'][ $order->get_id() ] = $order;

        return $order;
    }

    private function script( WCCT_Test_Google_Integration $google, $order ) {
        return (string) $google->get_event_script( EventFactory::purchase( $order ) );
    }

    public function test_user_data_only_for_the_verified_order() {
        $order = $this->order();
        $other = new WCCT_Test_Billing_Order( array( 'id' => 32 ) );
        $hash  = array(
            'sha256_email_address' => hash( 'sha256', 'caseytester@gmail.com' ),
            'sha256_phone_number'  => hash( 'sha256', '+14155550100' ),
        );

        $this->assertSame( $hash, EnhancedConversions::user_data( $order, $order ) );
        $this->assertSame( array(), EnhancedConversions::user_data( $order, false ) );
        $this->assertSame( array(), EnhancedConversions::user_data( $order, $other ) );
        $this->assertSame( array(), EnhancedConversions::user_data( new WCCT_Test_Billing_Order( array( 'id' => 0 ) ), new WCCT_Test_Billing_Order( array( 'id' => 0 ) ) ) );
        $this->assertSame( array(), EnhancedConversions::user_data( null, $order ) );
    }

    public function test_gate_checks_marketing_consent_only_with_consent_mode_on() {
        $this->consent_mode( false );
        $this->assertSame( 'set();', EnhancedConversions::gate( 'set();' ) );

        $this->consent_mode( true );
        $this->assertSame( 'if (window.wcctConsent) { if (window.wcctConsent.allows("marketing")) { set(); } }', EnhancedConversions::gate( 'set();' ) );
        $this->assertSame( ' ', EnhancedConversions::gate( ' ' ) );
    }

    public function test_the_thank_you_page_sets_hashed_user_data_before_the_conversion() {
        $this->consent_mode( true );

        $order            = $this->order();
        $google           = new WCCT_Test_Google_Integration();
        $google->verified = $order;
        $code             = $this->script( $google, $order );

        $set        = strpos( $code, "('set', 'user_data', {" );
        $conversion = strpos( $code, "('event', 'conversion', {" );

        $this->assertNotFalse( $set );
        $this->assertNotFalse( $conversion );
        $this->assertLessThan( $conversion, $set );
        $this->assertStringContainsString( 'if (window.wcctConsent) { if (window.wcctConsent.allows("marketing")) { (window.wcctGtag || function () {', $code );
        $this->assertStringContainsString( '"sha256_email_address": "' . hash( 'sha256', 'caseytester@gmail.com' ) . '"', $code );
        $this->assertStringContainsString( '"sha256_phone_number": "' . hash( 'sha256', '+14155550100' ) . '"', $code );
        $this->assertStringNotContainsStringIgnoringCase( 'casey', $code );
        $this->assertStringNotContainsString( '555', $code );
        $this->assertTrue( defined( 'DONOTCACHEPAGE' ) );
    }

    public function test_nothing_is_set_without_the_switch_or_a_verified_order() {
        $order = $this->order();

        // The switch off (Free).
        $this->consent_mode( true );
        $google           = new WCCT_Test_Google_Integration();
        $google->on       = false;
        $google->verified = $order;
        $this->assertStringNotContainsString( 'user_data', $this->script( $google, $order ) );

        // A funnel page's Purchase: the key and owner weren't checked.
        $this->consent_mode( true );
        $google = new WCCT_Test_Google_Integration();
        $code   = $this->script( $google, $order );
        $this->assertStringContainsString( "('event', 'conversion', {", $code );
        $this->assertStringNotContainsString( 'user_data', $code );
    }

    public function test_without_consent_mode_user_data_is_set_unwrapped_like_meta_matching() {
        $this->consent_mode( false );

        $order            = $this->order();
        $google           = new WCCT_Test_Google_Integration();
        $google->verified = $order;
        $code             = $this->script( $google, $order );

        $this->assertStringNotContainsString( 'wcctConsent.allows', $code );
        $this->assertStringContainsString( "})('set', 'user_data', {", $code );
        $this->assertLessThan( strpos( $code, "('event', 'conversion', {" ), strpos( $code, "('set', 'user_data', {" ) );
    }

    public function test_the_conversion_id_config_allows_enhanced_conversions_while_on() {
        $google = new WCCT_Test_Google_Integration();

        $this->assertSame( array( 'AW-123456789' => array( 'allow_enhanced_conversions' => true ) ), $google->get_gtag_config() );

        $google->on = false;
        $this->assertSame( array(), $google->get_gtag_config() );
        $this->assertSame( array(), ( new WCCT_Integration_Google() )->get_gtag_config() );
    }

    public function test_lite_never_turns_it_on() {
        $this->assertFalse( ( new WCCT_Integration_Google() )->enhanced_conversions_on() );
    }

    public function test_the_conversion_value_is_a_number_not_a_string() {
        $this->consent_mode( false );

        $google     = new WCCT_Test_Google_Integration();
        $google->on = false;

        foreach ( array( '20.00' => '20', '12.50' => '12.5', '0' => '0' ) as $total => $json ) {
            $order = $this->order( array( 'id' => 40 + (int) $total, 'total' => $total ) );
            $code  = $this->script( $google, $order );

            $this->assertMatchesRegularExpression( '/"value": ' . preg_quote( $json, '/' ) . ',\s*"currency": "USD"/', $code, $total );
        }
    }

    public function test_a_sent_order_sets_nothing() {
        $this->consent_mode( true );

        $order            = $this->order( array( 'meta' => array( '_wcct_sent_adwords' => 1 ) ) );
        $google           = new WCCT_Test_Google_Integration();
        $google->verified = $order;

        $this->assertSame( '', $this->script( $google, $order ) );
    }
}
