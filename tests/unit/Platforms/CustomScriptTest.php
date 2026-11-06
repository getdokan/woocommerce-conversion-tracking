<?php

require_once dirname( __DIR__ ) . '/Fakes/settings-functions.php';
require_once dirname( __DIR__, 3 ) . '/includes/integrations/class-integration-custom.php';

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Platforms\CustomScript;
use StorePulse\ConversionTracking\Settings\LegacySync;
use StorePulse\ConversionTracking\Settings\Schema;
use StorePulse\ConversionTracking\Settings\Store;
use StorePulse\ConversionTracking\Settings\Values;

/**
 * Order with the getters the Custom script's placeholders read, like order 3001 of the test store.
 */
class WCCT_Test_Placeholder_Order {

    public $coupons = array( 'ctsave5' );

    public function get_order_number() {
        return '3001';
    }

    public function get_total() {
        return '78.00';
    }

    public function get_subtotal() {
        return 78.0;
    }

    public function get_total_discount() {
        return 5.0;
    }

    public function get_shipping_total() {
        return '5';
    }

    public function get_currency() {
        return 'USD';
    }

    public function get_payment_method() {
        return 'cod';
    }

    public function get_coupon_codes() {
        return $this->coupons;
    }
}

/**
 * Tests for the Custom script's placements and placeholders
 */
class CustomScriptTest extends TestCase {

    const EVERY_PLACEHOLDER = '{order_number}|{order_total}|{order_subtotal}|{order_discount}|{order_shipping}|{currency}|{payment_method}|{customer_id}|{customer_email}|{customer_first_name}|{customer_last_name}|{order_id}|{used_coupons}';

    protected function setUp(): void {
        $GLOBALS['wcct_test_options'] = array();
    }

    protected function tearDown(): void {
        unset( $GLOBALS['wcct_test_options'], $GLOBALS['wcct_test_filters'] );
    }

    private function v3( array $custom ) {
        return array( 'platforms' => array( 'custom' => $custom ) );
    }

    public function test_reads_the_three_placements_from_the_3_0_settings() {
        $settings = CustomScript::settings(
            $this->v3(
                array(
                    'thankyou_enabled' => true,
                    'thankyou_script'  => '<script>ty()</script>',
                    'header_enabled'   => true,
                    'header_script'    => '<script>hd()</script>',
                    'signup_enabled'   => false,
                    'signup_script'    => '<script>su()</script>',
                )
            )
        );

        $this->assertSame(
            array(
                'thankyou' => array( 'enabled' => true, 'script' => '<script>ty()</script>' ),
                'header'   => array( 'enabled' => true, 'script' => '<script>hd()</script>' ),
                'signup'   => array( 'enabled' => false, 'script' => '<script>su()</script>' ),
            ),
            $settings
        );
        $this->assertSame( '<script>hd()</script>', CustomScript::script( $settings, CustomScript::HEADER ) );
        $this->assertSame( '', CustomScript::script( $settings, CustomScript::SIGNUP ), 'switched off' );
        $this->assertSame( '', CustomScript::script( $settings, 'footer' ), 'unknown placement' );
    }

    public function test_the_2_x_checkout_and_registration_scripts_read_as_thank_you_and_sign_up() {
        $GLOBALS['wcct_test_options']['wcct_settings'] = array(
            'custom' => array(
                'enabled'      => 'on',
                'checkout'     => '<script>ty()</script>',
                'registration' => '<script>su()</script>',
            ),
        );

        $settings = CustomScript::current();

        $this->assertSame( '<script>ty()</script>', CustomScript::script( $settings, CustomScript::THANKYOU ) );
        $this->assertSame( '<script>su()</script>', CustomScript::script( $settings, CustomScript::SIGNUP ) );
        $this->assertSame( '', CustomScript::script( $settings, CustomScript::HEADER ) );
    }

    public function test_the_2_x_scripts_stay_off_while_the_integration_was_off() {
        $GLOBALS['wcct_test_options']['wcct_settings'] = array( 'custom' => array( 'checkout' => '<script>ty()</script>' ) );

        $this->assertFalse( CustomScript::has_script( CustomScript::current() ) );
    }

    public function test_3_0_settings_without_scripts_still_read_the_2_x_scripts() {
        // A 3.0 save of another setting doesn't drop the scripts the 2.x settings page saved.
        $GLOBALS['wcct_test_options']['wcct_settings']    = array( 'custom' => array( 'enabled' => 'on', 'checkout' => '<script>ty()</script>' ) );
        $GLOBALS['wcct_test_options']['wcct_settings_v3'] = $this->v3( array( 'customer_details' => true ) );

        $this->assertSame( '<script>ty()</script>', CustomScript::script( CustomScript::current(), CustomScript::THANKYOU ) );
    }

    public function test_saved_3_0_placements_win_over_the_2_x_scripts() {
        $read = false;
        $v1   = function () use ( &$read ) {
            $read = true;

            return array( 'custom' => array( 'enabled' => 'on', 'checkout' => '<script>old()</script>', 'registration' => '<script>old()</script>' ) );
        };

        $settings = CustomScript::settings( $this->v3( array( 'thankyou_enabled' => false, 'signup_script' => '' ) ), $v1 );

        $this->assertFalse( CustomScript::has_script( $settings ) );
        $this->assertFalse( $read, 'the 2.x option is not read' );
    }

    public function test_each_placement_falls_back_to_the_2_x_script_on_its_own() {
        $GLOBALS['wcct_test_options']['wcct_settings']    = array( 'custom' => array( 'enabled' => 'on', 'checkout' => '<script>ty()</script>', 'registration' => '<script>su()</script>' ) );
        $GLOBALS['wcct_test_options']['wcct_settings_v3'] = $this->v3( array( 'header_enabled' => true, 'header_script' => '<script>hd()</script>', 'signup_enabled' => false ) );

        $settings = CustomScript::current();

        $this->assertSame( '<script>hd()</script>', CustomScript::script( $settings, CustomScript::HEADER ) );
        $this->assertSame( '<script>ty()</script>', CustomScript::script( $settings, CustomScript::THANKYOU ), 'no thank-you keys in 3.0' );
        $this->assertSame( '', CustomScript::script( $settings, CustomScript::SIGNUP ), 'sign-up saved off in 3.0' );
    }

    public function test_the_header_never_reads_the_2_x_settings() {
        $GLOBALS['wcct_test_options']['wcct_settings'] = array( 'custom' => array( 'enabled' => 'on', 'header' => '<script>hd()</script>' ) );

        $this->assertSame( '', CustomScript::script( CustomScript::current(), CustomScript::HEADER ) );
    }

    /**
     * A 3.0 save of another setting, while the 2.x settings are read as 3.0, and then the 2.x settings page save.
     *
     * @param array $classic The custom integration the 2.x page saves.
     *
     * @return array CustomScript::current() after both saves.
     */
    private function rest_then_classic_save( array $classic ) {
        $GLOBALS['wcct_test_options']['wcct_settings'] = array( 'custom' => array( 'enabled' => true, 'checkout' => '<script>old()</script>', 'registration' => '<script>su()</script>' ) );

        $result = Values::apply( Schema::get(), Store::load(), array( 'platforms' => array( 'custom' => array( 'header_enabled' => true, 'header_script' => '<script>hd()</script>' ) ) ), function () {
            return true;
        } );

        $this->assertSame( array(), $result['errors'] );
        Store::save( $result['doc'] );
        $this->assertSame( '<script>old()</script>', $GLOBALS['wcct_test_options']['wcct_settings_v3']['platforms']['custom']['thankyou_script'], 'the first 3.0 save keeps the 2.x script' );

        $GLOBALS['wcct_test_options']['wcct_settings'] = array( 'custom' => $classic );
        LegacySync::sync( $GLOBALS['wcct_test_options']['wcct_settings'], array(), true );

        return CustomScript::current();
    }

    public function test_switching_custom_off_on_the_2_x_page_after_a_3_0_save_stops_the_scripts() {
        $settings = $this->rest_then_classic_save( array( 'enabled' => false, 'checkout' => '<script>old()</script>', 'registration' => '<script>su()</script>' ) );

        $this->assertSame( '', CustomScript::script( $settings, CustomScript::THANKYOU ) );
        $this->assertSame( '', CustomScript::script( $settings, CustomScript::SIGNUP ) );
        $this->assertSame( '<script>hd()</script>', CustomScript::script( $settings, CustomScript::HEADER ), 'the 3.0 header script stays' );
    }

    public function test_editing_the_script_on_the_2_x_page_after_a_3_0_save_prints_the_new_one() {
        $settings = $this->rest_then_classic_save( array( 'enabled' => true, 'checkout' => '<script>new()</script>', 'registration' => '' ) );

        $this->assertSame( '<script>new()</script>', CustomScript::script( $settings, CustomScript::THANKYOU ) );
        $this->assertSame( '', CustomScript::script( $settings, CustomScript::SIGNUP ) );
        $this->assertEquals(
            array(
                'header_enabled'   => true,
                'header_script'    => '<script>hd()</script>',
                'thankyou_enabled' => true,
                'thankyou_script'  => '<script>new()</script>',
                'signup_enabled'   => false,
                'signup_script'    => '',
            ),
            array_intersect_key(
                $GLOBALS['wcct_test_options']['wcct_settings_v3']['platforms']['custom'],
                array_flip( array( 'header_enabled', 'header_script', 'thankyou_enabled', 'thankyou_script', 'signup_enabled', 'signup_script' ) )
            )
        );
    }

    public function test_the_2_x_page_switch_shows_what_was_saved_even_without_a_script() {
        $integration = new WCCT_Integration_Custom();

        $GLOBALS['wcct_test_options']['wcct_settings'] = array( 'custom' => array( 'enabled' => true, 'checkout' => '', 'registration' => '' ) );
        $this->assertTrue( $integration->is_switched_on() );
        $this->assertFalse( $integration->is_enabled(), 'nothing to print' );

        $GLOBALS['wcct_test_options']['wcct_settings'] = array( 'custom' => array( 'enabled' => false, 'checkout' => '<script>ty()</script>' ) );
        $this->assertFalse( $integration->is_switched_on() );

        $GLOBALS['wcct_test_options']['wcct_settings'] = array();
        $this->assertFalse( $integration->is_switched_on() );
    }

    public function test_blank_or_malformed_scripts_print_nothing() {
        $settings = CustomScript::settings(
            $this->v3(
                array(
                    'thankyou_enabled' => true,
                    'thankyou_script'  => " \n ",
                    'header_enabled'   => true,
                    'header_script'    => array( 'x' ),
                )
            )
        );

        $this->assertSame( '', CustomScript::script( $settings, CustomScript::THANKYOU ) );
        $this->assertSame( '', CustomScript::script( $settings, CustomScript::HEADER ) );
        $this->assertFalse( CustomScript::has_script( $settings ) );
        $this->assertFalse( CustomScript::has_script( CustomScript::settings( array() ) ) );
    }

    public function test_all_placeholders_resolve_for_an_order() {
        $customer = array(
            '{customer_id}'         => '501',
            '{customer_email}'      => 'customer@example.com',
            '{customer_first_name}' => 'Sam',
            '{customer_last_name}'  => 'Buyer',
        );

        $filled = CustomScript::fill( self::EVERY_PLACEHOLDER, array_merge( CustomScript::order_values( new WCCT_Test_Placeholder_Order() ), $customer ) );

        $this->assertSame( '3001|78.00|78|5|5|USD|cod|501|customer@example.com|Sam|Buyer|3001|ctsave5', $filled );
    }

    public function test_order_id_is_the_order_number_and_used_coupons_lists_every_code() {
        $order          = new WCCT_Test_Placeholder_Order();
        $order->coupons = array( 'ctsave5', 'summer' );
        $values         = CustomScript::order_values( $order );

        $this->assertSame( $values['{order_number}'], $values['{order_id}'] );
        $this->assertSame( 'ctsave5,summer', $values['{used_coupons}'] );
    }

    public function test_without_values_every_placeholder_is_empty() {
        $this->assertSame( '||||||||||||', CustomScript::fill( self::EVERY_PLACEHOLDER ) );
        $this->assertSame( "track('');{unknown}", CustomScript::fill( "track('{customer_email}');{unknown}" ) );
    }

    public function test_values_are_escaped_for_a_javascript_string() {
        $order          = new WCCT_Test_Placeholder_Order();
        $order->coupons = array( "it's</script>" );

        $this->assertSame( "c('it\\u0027s\\u003C/script\\u003E')", CustomScript::fill( "c('{used_coupons}')", CustomScript::order_values( $order ) ) );
    }

    public function test_a_value_is_never_filled_again() {
        $order          = new WCCT_Test_Placeholder_Order();
        $order->coupons = array( '{customer_email}' );
        $values         = array_merge( CustomScript::order_values( $order ), array( '{customer_email}' => 'customer@example.com' ) );

        $this->assertSame( '{customer_email}', CustomScript::fill( '{used_coupons}', $values ) );
    }
}
