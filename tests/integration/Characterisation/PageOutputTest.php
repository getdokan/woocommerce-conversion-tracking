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

    protected function captured_page_removal_patterns() {
        return array(
            '/ *<script type="text\/javascript">\n\s*jQuery\(function\(\$\) \{\n\s*\$\(document\)\.on\(\'added_to_cart\'.*?<\/script>\n/s' => '#70 removed Meta\'s added_to_cart jQuery listener from the head block',
        );
    }

    protected function fixtures_dir() {
        return dirname( __DIR__, 2 ) . '/fixtures';
    }

    protected function captured_page_additions() {
        return array(
            '~<script data-no-optimize="1">window\.wcctPurchaseSent = .*?</script>~s' => '#83: the Custom thank-you script is followed by the script that confirms the Purchase',
        );
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

    // #89 replaced the X base code and #79 added the order URL script and cleans Google and Meta URLs, all in the head.
    protected function captured_page_skipped_regions() {
        return array( 'head' );
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
        $this->assertStringNotContainsString( '"purchase"', $this->event_js( $this->request( 'ga4', 'order-received-no-key' ) ) );
    }

    public function test_add_to_cart_is_never_printed_into_the_page() {
        $output = $this->output_of( $this->request( 'all', 'cart-after-add-to-cart' ) );

        $this->assertStringNotContainsString( "fbq('track', 'AddToCart'", $output );
        $this->assertStringNotContainsString( "'event', 'add_to_cart'", $output );
        $this->assertStringNotContainsString( "$(document).on('added_to_cart'", $output );
        $this->assertStringContainsString( 'wcct-pending-events', $output );
    }

    public function test_sign_up_is_sent_on_the_first_page_view_after_registering() {
        $this->assertStringContainsString( "fbq('track', 'CompleteRegistration'", $this->output_of( $this->request( 'facebook', 'registration' ) ) );
        $this->assertStringNotContainsString( "fbq('track', 'CompleteRegistration'", $this->output_of( $this->request( 'facebook', 'my-account' ) ) );
    }

    public function test_meta_purchase_is_queued_on_the_thank_you_page() {
        $this->assertStringContainsString( "fbq('track', 'Purchase', {", $this->output_of( $this->request( 'facebook', 'order-received-classic' ) ) );
        $this->assertStringNotContainsString( "fbq('track', 'Purchase'", $this->output_of( $this->request( 'facebook', 'order-received-no-key' ) ) );
    }

    public function test_x_base_code_is_config_only() {
        $head = $this->output_of( $this->request( 'twitter', 'home' ) );

        $this->assertStringContainsString( "u.src='https://static.ads-twitter.com/uwt.js'", $head );
        $this->assertStringContainsString( "twq('config', \"ct0x1\");", $head );
        $this->assertStringNotContainsString( "twq('init'", $head );
        $this->assertStringNotContainsString( "twq('track'", $head );
        $this->assertStringNotContainsString( 'oct.js', $head );
    }

    public function test_x_purchase_uses_the_purchase_event_id() {
        $js = $this->event_js( $this->request( 'twitter', 'order-received-classic' ) );

        $this->assertSame( 1, substr_count( $js, "twq('event'" ) );
        $this->assertStringContainsString( "window.twq && twq('event', \"tw-ct0x1-ctpur\", {\"value\":78,\"currency\":\"USD\",\"conversion_id\":\"3001\",\"contents\":[", $js );
        $this->assertStringNotContainsString( "twq('event'", $this->event_js( $this->request( 'twitter', 'order-received-no-key' ) ) );
    }

    public function test_x_saved_by_2_x_sends_page_views_but_no_purchase() {
        $result = $this->request_page( 'order-received-classic', WCCT_Test_Settings::twitter_v2() );

        $this->assertStringContainsString( "twq('config', \"ct0x1\");", $this->output_of( $result ) );
        $this->assertStringNotContainsString( "twq('event'", $this->event_js( $result ) . $this->output_of( $result ) );
    }

    public function test_the_order_url_script_runs_first_on_the_order_received_page_only() {
        $result = $this->request( 'all', 'order-received-classic' );

        $this->assertSame( 'head', $result['chunks'][0][0] );
        $this->assertStringStartsWith( '<script data-cfasync="false" data-no-defer="1" data-no-optimize="1" id="wcct-order-url">', $result['chunks'][0][1] );
        $this->assertStringContainsString( 'window.wcctOrder = { id: 3001,', $result['chunks'][0][1] );
        $this->assertStringNotContainsString( 'wcct-order-url', $this->output_of( $this->request( 'all', 'checkout-classic' ) ) );
        $this->assertStringNotContainsString( 'wcct-order-url', $this->output_of( $this->request( 'all', 'home' ) ) );
    }

    public function test_google_configs_clean_the_page_location() {
        $head = $this->output_of( $this->request( 'all', 'home' ) );

        $this->assertStringContainsString( "gtag('config', 'AW-123456789', (function (cleanUrl) {", $head );
        $this->assertStringContainsString( "wcctGtag('config', \"G-CTTEST1234\", (function (cleanUrl) {", $head );
    }
}
