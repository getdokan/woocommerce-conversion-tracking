<?php

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Privacy\CustomerDetails;
use StorePulse\ConversionTracking\Settings\Schema;
use StorePulse\ConversionTracking\Settings\Store;
use StorePulse\ConversionTracking\Settings\Values;

if ( ! function_exists( 'get_current_user_id' ) ) {
    function get_current_user_id() {
        return isset( $GLOBALS['wcct_test_user_id'] ) ? (int) $GLOBALS['wcct_test_user_id'] : 0;
    }
}

if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $data, $options = 0, $depth = 512 ) {
        return json_encode( $data, $options, $depth );
    }
}

// WP Consent API: true when no consent plugin set a type, else the visitor's choice.
if ( ! function_exists( 'wp_has_consent' ) ) {
    function wp_has_consent( $category ) {
        return isset( $GLOBALS['wcct_test_consent'][ $category ] ) ? (bool) $GLOBALS['wcct_test_consent'][ $category ] : true;
    }
}

if ( ! function_exists( 'wp_get_consent_type' ) ) {
    function wp_get_consent_type() {
        return isset( $GLOBALS['wcct_test_consent'] ) ? 'optin' : '';
    }
}

/**
 * Order with the getters CustomerDetails reads.
 */
class WCCT_Test_Customer_Order {

    private $id;
    private $customer_id;
    private $user;

    public function __construct( $id, $customer_id, array $user = array() ) {
        $this->id          = $id;
        $this->customer_id = $customer_id;
        $this->user        = $user;
    }

    public function get_id() {
        return $this->id;
    }

    public function get_customer_id() {
        return $this->customer_id;
    }

    public function get_user() {
        if ( ! $this->customer_id ) {
            return false;
        }

        return (object) array_merge(
            array(
                'ID'         => $this->customer_id,
                'user_email' => 'customer@example.com',
                'first_name' => 'Sam',
                'last_name'  => 'Buyer',
            ),
            $this->user
        );
    }
}

/**
 * Tests for the "Include customer details in scripts" gate
 */
class CustomerDetailsTest extends TestCase {

    const EMPTY_VALUES = array(
        '{customer_id}'         => '',
        '{customer_email}'      => '',
        '{customer_first_name}' => '',
        '{customer_last_name}'  => '',
    );

    const FILLED_VALUES = array(
        '{customer_id}'         => '501',
        '{customer_email}'      => 'customer@example.com',
        '{customer_first_name}' => 'Sam',
        '{customer_last_name}'  => 'Buyer',
    );

    protected function setUp(): void {
        $GLOBALS['wcct_test_options'] = array();
        $GLOBALS['wcct_test_user_id'] = 501;
    }

    protected function tearDown(): void {
        unset( $GLOBALS['wcct_test_options'], $GLOBALS['wcct_test_filters'], $GLOBALS['wcct_test_user_id'], $GLOBALS['wcct_test_consent'] );
    }

    private function turn_on() {
        $GLOBALS['wcct_test_options']['wcct_settings_v3'] = array( 'platforms' => array( 'custom' => array( 'customer_details' => true ) ) );
    }

    public function test_the_setting_is_off_by_default() {
        $this->assertFalse( CustomerDetails::is_enabled() );

        // An upgrader without a customer placeholder in the 2.x checkout script starts off too.
        $GLOBALS['wcct_test_options']['wcct_settings'] = array( 'custom' => array( 'enabled' => true, 'checkout' => '{order_total}' ) );

        $this->assertFalse( CustomerDetails::is_enabled() );
    }

    public function test_an_upgrader_whose_checkout_script_uses_a_customer_placeholder_starts_on() {
        $GLOBALS['wcct_test_options']['wcct_settings'] = array( 'custom' => array( 'enabled' => true, 'checkout' => '{customer_email}' ) );

        $this->assertTrue( CustomerDetails::is_enabled() );
    }

    public function test_a_3_0_doc_without_the_setting_keeps_the_upgrade_default() {
        $GLOBALS['wcct_test_options']['wcct_settings']    = array( 'custom' => array( 'enabled' => true, 'checkout' => '{customer_email}' ) );
        $GLOBALS['wcct_test_options']['wcct_settings_v3'] = array( 'platforms' => array( 'facebook' => array( 'pixel_id' => '123456789012345' ), 'custom' => array( 'thankyou_enabled' => true ) ) );

        $this->assertTrue( CustomerDetails::is_enabled() );
        $this->assertTrue( Values::get( Schema::get(), Store::load() )['platforms']['custom']['customer_details'], 'the page shows what the storefront reads' );

        // A stored value wins.
        $GLOBALS['wcct_test_options']['wcct_settings_v3']['platforms']['custom']['customer_details'] = false;

        $this->assertFalse( CustomerDetails::is_enabled() );
        $this->assertFalse( Values::get( Schema::get(), Store::load() )['platforms']['custom']['customer_details'] );
    }

    public function test_the_setting_is_read_from_the_custom_platform() {
        $this->turn_on();

        $this->assertTrue( CustomerDetails::is_enabled() );
    }

    public function test_values_are_empty_with_the_setting_off() {
        $order = new WCCT_Test_Customer_Order( 3002, 501 );

        $this->assertSame( self::EMPTY_VALUES, CustomerDetails::values( $order, $order ) );
    }

    public function test_values_fill_on_the_verified_page_for_the_logged_in_customer() {
        $this->turn_on();
        $order = new WCCT_Test_Customer_Order( 3002, 501 );

        $this->assertSame( self::FILLED_VALUES, CustomerDetails::values( $order, $order ) );
    }

    public function test_values_are_empty_without_the_key_and_ownership_checks() {
        $this->turn_on();
        $order = new WCCT_Test_Customer_Order( 3002, 501 );

        $this->assertSame( self::EMPTY_VALUES, CustomerDetails::values( $order, false ), 'not the verified order received page' );
        $this->assertSame( self::EMPTY_VALUES, CustomerDetails::values( $order, new WCCT_Test_Customer_Order( 3001, 0 ) ), 'another order verified' );
    }

    public function test_a_guest_with_a_valid_key_for_a_customers_order_gets_empty_values() {
        $this->turn_on();
        $order = new WCCT_Test_Customer_Order( 3002, 501 );

        $GLOBALS['wcct_test_user_id'] = 0;
        $this->assertSame( self::EMPTY_VALUES, CustomerDetails::values( $order, $order ) );

        $GLOBALS['wcct_test_user_id'] = 777;
        $this->assertSame( self::EMPTY_VALUES, CustomerDetails::values( $order, $order ) );
    }

    public function test_guest_orders_have_no_customer_details() {
        $this->turn_on();
        $GLOBALS['wcct_test_user_id'] = 0;
        $order                        = new WCCT_Test_Customer_Order( 3001, 0 );

        $this->assertSame( self::EMPTY_VALUES, CustomerDetails::values( $order, $order ) );
    }

    public function test_consent_mode_on_waits_for_marketing_consent() {
        $this->turn_on();
        $GLOBALS['wcct_test_options']['wcct_consent_mode'] = 'on';
        $order = new WCCT_Test_Customer_Order( 3002, 501 );

        $GLOBALS['wcct_test_consent'] = array( 'marketing' => false );
        $this->assertSame( self::EMPTY_VALUES, CustomerDetails::values( $order, $order ), 'denied' );

        $GLOBALS['wcct_test_consent'] = array( 'marketing' => true );
        $this->assertSame( self::FILLED_VALUES, CustomerDetails::values( $order, $order ), 'granted' );

        unset( $GLOBALS['wcct_test_consent'] );
        $this->assertSame( self::FILLED_VALUES, CustomerDetails::values( $order, $order ), 'no consent plugin' );
    }

    public function test_consent_is_not_asked_with_consent_mode_off() {
        $this->turn_on();
        $GLOBALS['wcct_test_consent'] = array( 'marketing' => false );
        $order = new WCCT_Test_Customer_Order( 3002, 501 );

        $this->assertSame( self::FILLED_VALUES, CustomerDetails::values( $order, $order ) );
    }

    public function test_the_consent_switch_filter_is_respected() {
        $this->turn_on();
        $GLOBALS['wcct_test_consent']                              = array( 'marketing' => false );
        $GLOBALS['wcct_test_filters']['wcct_consent_mode_enabled'] = function () {
            return true;
        };
        $order = new WCCT_Test_Customer_Order( 3002, 501 );

        $this->assertSame( self::EMPTY_VALUES, CustomerDetails::values( $order, $order ) );
    }

    public function test_the_final_decision_is_filterable() {
        $this->turn_on();
        $order = new WCCT_Test_Customer_Order( 3002, 501 );

        $GLOBALS['wcct_test_filters']['wcct_customer_details_allowed'] = function () {
            return false;
        };
        $this->assertSame( self::EMPTY_VALUES, CustomerDetails::values( $order, $order ) );
    }

    public function test_values_are_escaped_for_a_javascript_string() {
        $this->turn_on();
        $order = new WCCT_Test_Customer_Order(
            3002,
            501,
            array(
                'user_email' => 'o\'brien+"x"@example.com',
                'first_name' => "O'Brien",
                'last_name'  => '</script><script>alert(1)</script> & Ünal',
            )
        );

        $values = CustomerDetails::values( $order, $order );

        $this->assertSame( 'O\u0027Brien', $values['{customer_first_name}'] );
        $this->assertSame( 'o\u0027brien+\u0022x\u0022@example.com', $values['{customer_email}'] );
        $this->assertSame( '\u003C/script\u003E\u003Cscript\u003Ealert(1)\u003C/script\u003E \u0026 Ünal', $values['{customer_last_name}'] );

        foreach ( $values as $value ) {
            $this->assertStringNotContainsString( "'", $value );
            $this->assertStringNotContainsString( '"', $value );
            $this->assertStringNotContainsString( '<', $value );
        }
    }

    public function test_values_cannot_end_or_interpolate_a_template_literal() {
        $this->assertSame( 'a\x60b\x24{alert(1)}', CustomerDetails::js_string( 'a`b${alert(1)}' ) );
        $this->assertSame( '\\\\\x60', CustomerDetails::js_string( '\`' ), 'a backslash before it stays escaped' );
    }

    public function test_a_bad_order_never_fatals() {
        $this->turn_on();

        $this->assertSame( self::EMPTY_VALUES, CustomerDetails::values( null, false ) );
        $this->assertSame( self::EMPTY_VALUES, CustomerDetails::values( 'nope', new WCCT_Test_Customer_Order( 3002, 501 ) ) );
        $this->assertSame( self::EMPTY_VALUES, CustomerDetails::values( new WCCT_Test_Customer_Order( 3002, 501 ), new stdClass() ) );
    }
}
