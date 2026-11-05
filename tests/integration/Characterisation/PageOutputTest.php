<?php
// phpcs:disable

/**
 * Characterisation tests: what Lite prints on each store page, per integration
 *
 * Fixtures are in tests/fixtures/<integration>/<page>.html, captured pages in tests/fixtures/html/. See tests/integration/README.md.
 */
class PageOutputTest extends WCCT_Test_Page_Case {

    /**
     * Integrations, each enabled alone, then all together
     *
     * @var string[]
     */
    const INTEGRATIONS = array( 'facebook', 'adwords', 'ga4', 'twitter', 'custom', 'all' );

    protected function setUp(): void {
        parent::setUp();

        if ( count( WCCT_Test_WP::$env['plugins'] ) > 2 ) {
            $this->markTestSkipped( 'The Lite fixtures are recorded without Pro.' );
        }
    }

    protected function fixtures_dir() {
        return dirname( __DIR__, 2 ) . '/fixtures';
    }

    private function request( $integration, $page ) {
        return $this->request_page( $page, WCCT_Test_Settings::only( WCCT_Test_Settings::lite_all(), $integration ) );
    }

    /**
     * @dataProvider pages_and_integrations
     */
    public function test_page_output( $integration, $page ) {
        $this->assertMatchesFixture( "$integration/$page", $this->request( $integration, $page ) );
    }

    /**
     * @dataProvider pages
     */
    public function test_output_is_in_the_page_captured_from_2_1_6( $page ) {
        $this->assertInCapturedPage( $page, $this->request( 'all', $page ) );
    }

    public function pages() {
        $cases = array();

        foreach ( array_keys( WCCT_Test_Pages::all() ) as $page ) {
            $cases[ $page ] = array( $page );
        }

        return $cases;
    }

    public function pages_and_integrations() {
        $cases = array();

        foreach ( self::INTEGRATIONS as $integration ) {
            foreach ( array_keys( WCCT_Test_Pages::all() ) as $page ) {
                $cases[ "$integration / $page" ] = array( $integration, $page );
            }
        }

        return $cases;
    }

    public function test_each_page_is_the_page_it_names() {
        $expected = array(
            'home'                               => array( 'is_front_page', 'is_home' ),
            'shop'                               => array( 'is_shop' ),
            'category'                           => array( 'is_product_category' ),
            'search'                             => array( 'is_shop', 'is_search' ),
            'product-simple'                     => array( 'is_product' ),
            'product-variable'                   => array( 'is_product' ),
            'cart-after-add-to-cart'             => array( 'is_cart' ),
            'checkout-classic'                   => array( 'is_checkout' ),
            'checkout-block'                     => array( 'is_checkout' ),
            'order-received-classic'             => array( 'is_checkout', 'is_order_received_page' ),
            'order-received-block'               => array( 'is_checkout', 'is_order_received_page' ),
            'order-received-no-key'              => array( 'is_checkout', 'is_order_received_page' ),
            'order-received-customer-logged-out' => array( 'is_checkout', 'is_order_received_page' ),
            'order-received-customer'            => array( 'is_checkout', 'is_order_received_page', 'is_user_logged_in' ),
            'my-account'                         => array( 'is_account_page' ),
            'registration'                       => array( 'is_account_page', 'is_user_logged_in' ),
        );

        foreach ( $expected as $page => $conditionals ) {
            $this->assertSame( $conditionals, $this->request( 'all', $page )['page'], $page );
        }
    }

    public function test_head_output_is_wrapped_in_the_plugin_comments() {
        $head = $this->request( 'all', 'home' )['chunks'][0];

        $this->assertSame( 'head', $head[0] );
        $this->assertStringStartsWith( '<!-- Starting: Conversion Tracking for WooCommerce', $head[1] );
        $this->assertStringEndsWith( "<!-- End: Conversion Tracking for WooCommerce Codes -->\n", $head[1] );
    }

    public function test_ga4_view_item_is_sent_on_the_product_page() {
        $output = $this->event_js( $this->request( 'ga4', 'product-simple' ) );

        $this->assertMatchesRegularExpression( '/var events = \[\["view_item",\{"send_to":"G-CTTEST1234","currency":"USD","value":18,"items":\[\{"item_id":"2001","item_name":"Beanie"/', $output );
    }

    public function test_ga4_begin_checkout_is_sent_on_both_checkouts() {
        foreach ( array( 'checkout-classic', 'checkout-block' ) as $page ) {
            $this->assertStringContainsString( '[["begin_checkout",{"send_to":"G-CTTEST1234","currency":"USD","value":78', $this->event_js( $this->request( 'ga4', $page ) ), $page );
        }
    }

    public function test_ga4_purchase_needs_the_order_key() {
        $this->assertStringContainsString( '[["purchase",{"send_to":"G-CTTEST1234","currency":"USD","value":73', $this->event_js( $this->request( 'ga4', 'order-received-block' ) ) );
        $this->assertStringContainsString( 'var events = [];', $this->event_js( $this->request( 'ga4', 'order-received-no-key' ) ) );
    }

    public function test_meta_purchase_is_queued_on_the_thank_you_page() {
        $this->assertStringContainsString( "fbq('track', 'Purchase', {", $this->output_of( $this->request( 'facebook', 'order-received-classic' ) ) );
        $this->assertStringNotContainsString( "fbq('track', 'Purchase'", $this->output_of( $this->request( 'facebook', 'order-received-no-key' ) ) );
    }
}
