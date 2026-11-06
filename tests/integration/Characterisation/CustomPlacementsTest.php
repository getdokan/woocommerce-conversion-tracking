<?php
// phpcs:disable

/**
 * The Custom script's three placements saved in the 3.0 settings: thank-you, every page header and sign-up (#94)
 *
 * Fixtures are in tests/fixtures/custom-placements/<page>.html.
 */
class CustomPlacementsTest extends WCCT_Test_Page_Case {

    const THANKYOU = "<script>ctCustom('purchase', { number: '{order_number}', id: '{order_id}', total: '{order_total}', subtotal: '{order_subtotal}', discount: '{order_discount}', shipping: '{order_shipping}', currency: '{currency}', payment: '{payment_method}', coupons: '{used_coupons}', customer: '{customer_id}', email: '{customer_email}', first: '{customer_first_name}', last: '{customer_last_name}' });</script>";

    const HEADER = "<script>ctCustom('header', { order: '{order_number}', customer: '{customer_id}', email: '{customer_email}' });</script>";

    const SIGNUP = "<script>ctCustom('signup', { order: '{order_number}', customer: '{customer_id}', email: '{customer_email}' });</script>";

    protected function setUp(): void {
        parent::setUp();

        if ( count( WCCT_Test_WP::$env['plugins'] ) > 2 ) {
            $this->markTestSkipped( 'Recorded without Pro.' );
        }
    }

    protected function fixtures_dir() {
        return dirname( __DIR__, 2 ) . '/fixtures';
    }

    /**
     * 3.0 settings with every placement on and customer details allowed
     *
     * @param array $custom Values that replace the defaults.
     *
     * @return array
     */
    public static function settings( array $custom = array() ) {
        return array(
            'platforms' => array(
                'custom' => array_merge(
                    array(
                        'thankyou_enabled' => true,
                        'thankyou_script'  => self::THANKYOU,
                        'header_enabled'   => true,
                        'header_script'    => self::HEADER,
                        'signup_enabled'   => true,
                        'signup_script'    => self::SIGNUP,
                        'customer_details' => true,
                    ),
                    $custom
                ),
            ),
        );
    }

    private function request( $page, array $custom = array(), array $options = array() ) {
        return $this->request_page( $page, array(), array_merge( array( 'wcct_settings_v3' => self::settings( $custom ) ), $options ) );
    }

    public function pages() {
        $cases = array();

        foreach ( array_keys( WCCT_Test_Pages::all() ) as $page ) {
            $cases[ $page ] = array( $page );
        }

        return $cases;
    }

    /**
     * @dataProvider pages
     */
    public function test_page_output( $page ) {
        $this->assertMatchesFixture( "custom-placements/$page", $this->request( $page ) );
    }

    /**
     * @dataProvider pages
     */
    public function test_the_header_script_prints_once_in_the_head_of_every_page( $page ) {
        $result = $this->request( $page );
        $head   = '';

        foreach ( $result['chunks'] as $chunk ) {
            $head .= 'head' === $chunk[0] ? $chunk[1] : '';
        }

        // Its placeholders stay empty, also on the customer's own verified thank-you page.
        $this->assertSame( 1, substr_count( $head, "ctCustom('header', { order: '', customer: '', email: '' });" ) );
        $this->assertSame( 1, substr_count( $result['html'], "ctCustom('header'" ) );
    }

    public function test_every_placement_waits_for_marketing_consent_with_consent_mode_on() {
        $head = '';
        $home = $this->request( 'home', array(), array( 'wcct_consent_mode' => 'on' ) );

        foreach ( $home['chunks'] as $chunk ) {
            $head .= 'head' === $chunk[0] ? $chunk[1] : '';
        }

        $this->assertMatchesRegularExpression( '/<script type="text\/plain" data-wcct-consent="marketing"[^>]*>ctCustom\(\'header\'/', $head );

        $thankyou = $this->request( 'order-received-customer', array(), array( 'wcct_consent_mode' => 'on' ) )['html'];

        $this->assertMatchesRegularExpression( '/<script type="text\/plain" data-wcct-consent="marketing"[^>]*>ctCustom\(\'purchase\'/', $thankyou );
        // The unheld check-in (#111) only arms the "not handed over" check-in; the confirming call waits with the merchant script.
        $this->assertDoesNotMatchRegularExpression( '/<script(?![^>]*text\/plain)[^>]*>(?:(?!<\/script>).)*wcctPurchaseSent\([^)]*"custom"[^)]*\)\(\)/s', $thankyou );
    }

    public function test_the_header_script_is_off_with_its_switch_or_while_paused() {
        $this->assertStringNotContainsString( "ctCustom('header'", $this->request( 'home', array( 'header_enabled' => false ) )['html'] );
        $this->assertStringNotContainsString( 'ctCustom(', $this->request( 'home', array(), array( 'wcct_paused_platforms' => array( 'custom' ) ) )['html'] );
    }

    public function test_the_thank_you_script_fills_every_placeholder_after_the_key_and_ownership_checks() {
        $filled = "ctCustom('purchase', { number: '3002', id: '3002', total: '23.00', subtotal: '18', discount: '0', shipping: '5', currency: 'USD', payment: 'cod', coupons: '', customer: '501', email: 'customer@example.com', first: 'Sam', last: 'Buyer' });";

        $this->assertSame( 1, substr_count( $this->request( 'order-received-customer' )['html'], $filled ) );
        $this->assertStringContainsString( "ctCustom('purchase', { number: '3001', id: '3001', total: '78.00', subtotal: '78', discount: '5', shipping: '5', currency: 'USD', payment: 'cod', coupons: 'ctsave5', customer: '', email: '', first: '', last: '' });", $this->request( 'order-received-block' )['html'] );

        foreach ( array( 'order-received-no-key', 'order-received-customer-logged-out', 'checkout-block', 'home' ) as $page ) {
            $this->assertStringNotContainsString( "ctCustom('purchase'", $this->request( $page )['html'], $page );
        }
    }

    public function test_the_thank_you_script_prints_once_per_order() {
        $request = function ( array $state ) {
            $result = WCCT_Test_WP::run(
                array(
                    'type'        => 'request',
                    'rollback'    => true,
                    'request'     => array( 'path' => '/checkout/order-received/3001/?key=wc_order_ctguest0001' ),
                    'options'     => array( 'wcct_settings' => array(), 'wcct_settings_v3' => self::settings() ),
                    'before'      => dirname( __DIR__ ) . '/jobs/purchase-order-state.php',
                    'before_args' => $state,
                )
            );

            $this->assertNull( $result['error'] );

            return $result['html'];
        };

        $first = $request( array() );

        $this->assertSame( 1, substr_count( $first, "ctCustom('purchase'" ) );
        $this->assertStringContainsString( '"custom", 3001, "wc_order_ctguest0001", "wcct_3001_purchase", ""', $first );

        // Once the browser confirmed it, a reload prints nothing; the header still prints.
        $reload = $request( array( 'sent' => array( 'custom' ) ) );

        $this->assertStringNotContainsString( "ctCustom('purchase'", $reload );
        $this->assertStringContainsString( "ctCustom('header'", $reload );
        $this->assertStringNotContainsString( "ctCustom('purchase'", $request( array( 'status' => 'failed' ) ) );
    }

    public function test_the_sign_up_script_prints_once_per_new_account() {
        $result = WCCT_Test_WP::run(
            array(
                'type'     => 'file',
                'file'     => dirname( __DIR__ ) . '/jobs/custom-sign-up.php',
                'rollback' => true,
                'options'  => array( 'wcct_settings' => array(), 'wcct_settings_v3' => self::settings() ),
            )
        );

        $this->assertNull( $result['error'] );
        $this->assertSame( array( 'custom' => "<script>ctCustom('signup', { order: '', customer: '', email: '' });</script>" ), $result['value']['first_view'] );
        $this->assertSame( array(), $result['value']['second_view'] );

        $this->assertStringNotContainsString( "ctCustom('signup'", $this->request( 'my-account' )['html'] );
    }

    public function test_the_2_x_scripts_still_print_when_3_0_settings_hold_none() {
        $v1     = WCCT_Test_Settings::only( WCCT_Test_Settings::lite_all(), 'custom' );
        $result = $this->request_page( 'order-received-block', $v1, array( 'wcct_settings_v3' => array( 'platforms' => array( 'custom' => array( 'customer_details' => true ) ) ) ) );

        $this->assertStringContainsString( "ctCustom('purchase', { id: '', email: '', first: '', last: '', number: '3001',", $result['html'] );
    }

    public function test_a_2_x_script_prints_next_to_a_3_0_header_script() {
        $v1     = WCCT_Test_Settings::only( WCCT_Test_Settings::lite_all(), 'custom' );
        $result = $this->request_page( 'order-received-block', $v1, array( 'wcct_settings_v3' => array( 'platforms' => array( 'custom' => array( 'header_enabled' => true, 'header_script' => self::HEADER ) ) ) ) );

        $this->assertStringContainsString( "ctCustom('header'", $result['html'] );
        $this->assertStringContainsString( "ctCustom('purchase', { id: '', email: '', first: '', last: '', number: '3001',", $result['html'] );
    }

    /**
     * Order 3001's thank-you page after a 3.0 save and then a 2.x settings page save
     *
     * @param array $classic The Custom integration the 2.x page posts.
     *
     * @return string
     */
    private function after_rest_and_classic_save( array $classic ) {
        $result = WCCT_Test_WP::run(
            array(
                'type'        => 'request',
                'rollback'    => true,
                'request'     => array( 'path' => '/checkout/order-received/3001/?key=wc_order_ctguest0001' ),
                'options'     => array( 'wcct_settings' => WCCT_Test_Settings::only( WCCT_Test_Settings::lite_all(), 'custom' ) ),
                'before'      => dirname( __DIR__ ) . '/jobs/custom-classic-save.php',
                'before_args' => array(
                    'rest'    => array( 'platforms' => array( 'custom' => array( 'header_enabled' => true, 'header_script' => self::HEADER ) ) ),
                    'classic' => array( 'custom' => $classic ),
                ),
            )
        );

        $this->assertNull( $result['error'] );

        return $result['html'];
    }

    public function test_switching_custom_off_on_the_2_x_page_after_a_3_0_save_stops_its_scripts() {
        $html = $this->after_rest_and_classic_save( array( 'checkout' => WCCT_Test_Settings::CUSTOM_CHECKOUT, 'registration' => '' ) );

        $this->assertStringNotContainsString( "ctCustom('purchase'", $html );
        $this->assertStringContainsString( "ctCustom('header'", $html );
    }

    public function test_editing_the_script_on_the_2_x_page_after_a_3_0_save_prints_the_new_one() {
        $html = $this->after_rest_and_classic_save( array( 'enabled' => '1', 'checkout' => "<script>ctCustom('classic', '{order_number}');</script>", 'registration' => '' ) );

        $this->assertStringContainsString( "ctCustom('classic', '3001');", $html );
        $this->assertStringNotContainsString( "ctCustom('purchase'", $html );
    }
}
