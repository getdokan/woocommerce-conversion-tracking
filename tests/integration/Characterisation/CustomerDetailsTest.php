<?php
// phpcs:disable

use PHPUnit\Framework\TestCase;

/**
 * The Custom script's customer placeholders and the "Include customer details in scripts" setting (#78)
 */
class CustomerDetailsTest extends TestCase {

    const GUEST_ORDER    = '/checkout/order-received/3001/?key=wc_order_ctguest0001';
    const CUSTOMER_ORDER = '/checkout/order-received/3002/?key=wc_order_ctcustomer01';

    // Customer 501's details and order 3002's values, as the test script prints them.
    const FILLED = "{ id: '501', email: 'customer@example.com', first: 'Sam', last: 'Buyer', number: '3002', total: '23.00', subtotal: '18', discount: '0', shipping: '5', currency: 'USD', payment: 'cod', coupons: '' }";
    const EMPTY  = "{ id: '', email: '', first: '', last: '', number: '3002', total: '23.00', subtotal: '18', discount: '0', shipping: '5', currency: 'USD', payment: 'cod', coupons: '' }";

    protected function setUp(): void {
        if ( count( WCCT_Test_WP::$env['plugins'] ) > 2 ) {
            $this->markTestSkipped( 'Recorded without Pro.' );
        }
    }

    /**
     * Options with only the Custom script on, and the setting when given
     *
     * @param bool|null $customer_details Setting value, null to leave the 3.0 settings unsaved.
     * @param array     $more             More options.
     *
     * @return array
     */
    private function options( $customer_details, array $more = array() ) {
        $options = array( 'wcct_settings' => WCCT_Test_Settings::only( WCCT_Test_Settings::lite_all(), 'custom' ) );

        if ( null !== $customer_details ) {
            $options['wcct_settings_v3'] = array( 'platforms' => array( 'custom' => array( 'customer_details' => $customer_details ) ) );
        }

        return array_merge( $options, $more );
    }

    /**
     * The Custom script's purchase call on a thank-you page, or an empty string
     *
     * @param string $path    Thank-you page.
     * @param int    $user_id Logged-in user.
     * @param array  $options Options.
     * @param array  $job     More job keys for jobs/customer-details-before.php.
     *
     * @return string
     */
    private function purchase_call( $path, $user_id, array $options, array $job = array() ) {
        $result = WCCT_Test_WP::run(
            array_merge(
                array(
                    'type'     => 'request',
                    'rollback' => true,
                    'request'  => array( 'path' => $path ),
                    'user_id'  => $user_id,
                    'options'  => $options,
                    'before'   => dirname( __DIR__ ) . '/jobs/customer-details-before.php',
                ),
                $job
            )
        );

        $this->assertNull( $result['error'] );

        return preg_match( "/ctCustom\('purchase', (\{[^}]*\})\);/", $result['html'], $match ) ? $match[1] : '';
    }

    public function test_off_the_placeholders_render_empty_and_order_values_still_fill() {
        $this->assertSame( self::EMPTY, $this->purchase_call( self::CUSTOMER_ORDER, 501, $this->options( false ) ) );
    }

    public function test_an_upgrader_whose_checkout_script_uses_them_keeps_getting_them() {
        // 2.x settings only: the 2.x checkout script holds customer placeholders, so the setting reads as on (#106).
        $this->assertSame( self::FILLED, $this->purchase_call( self::CUSTOMER_ORDER, 501, $this->options( null ) ) );
    }

    public function test_on_the_placeholders_fill_for_the_customer_on_the_verified_page() {
        $this->assertSame( self::FILLED, $this->purchase_call( self::CUSTOMER_ORDER, 501, $this->options( true ) ) );
    }

    public function test_a_guest_with_a_valid_key_for_a_customers_order_gets_empty_values() {
        // WooCommerce shows the order to anyone with the key here, so the purchase fires, without the customer's details.
        $this->assertSame( self::EMPTY, $this->purchase_call( self::CUSTOMER_ORDER, 0, $this->options( true ), array( 'trust_known_shoppers' => true ) ) );

        // By default WooCommerce asks to log in and nothing fires.
        $this->assertSame( '', $this->purchase_call( self::CUSTOMER_ORDER, 0, $this->options( true ) ) );
    }

    public function test_guest_orders_have_no_customer_details() {
        $this->assertStringStartsWith( "{ id: '', email: '', first: '', last: '', number: '3001',", $this->purchase_call( self::GUEST_ORDER, 0, $this->options( true ) ) );
    }

    public function test_with_cookie_choices_respected_the_values_wait_for_marketing_consent() {
        $options = $this->options( true, array( 'wcct_consent_mode' => 'on' ) );

        $this->assertSame( self::EMPTY, $this->purchase_call( self::CUSTOMER_ORDER, 501, $options, array( 'marketing_consent' => false ) ) );
        $this->assertSame( self::FILLED, $this->purchase_call( self::CUSTOMER_ORDER, 501, $options, array( 'marketing_consent' => true ) ) );
    }

    public function test_funnel_thank_you_pages_get_empty_values() {
        $result = WCCT_Test_WP::run(
            array(
                'type'     => 'file',
                'file'     => dirname( __DIR__ ) . '/jobs/thankyou-fallback.php',
                'rollback' => true,
                'order_id' => 3002,
                'user_id'  => 501,
                'options'  => $this->options( true ),
            )
        );

        $this->assertNull( $result['error'] );
        $this->assertStringContainsString( "ctCustom('purchase', " . self::EMPTY . ');', $result['value']['markup']['custom'] );
    }
}
