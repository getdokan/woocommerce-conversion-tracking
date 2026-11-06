<?php
// phpcs:disable

/**
 * Characterisation tests with "Respect cookie choices" on (wcct_consent_mode = on), no consent plugin
 *
 * Fixtures are in tests/fixtures/consent-on/<integration>/<page>.html. With the switch off the output is
 * PageOutputTest's, unchanged.
 */
class ConsentPageOutputTest extends WCCT_Test_Page_Case {

    /**
     * Integrations, each enabled alone, then all together
     *
     * @var string[]
     */
    const INTEGRATIONS = array( 'facebook', 'adwords', 'ga4', 'twitter', 'custom', 'all' );

    /**
     * Pages that cover the head code, add to cart, checkout and the thank-you page
     *
     * @var string[]
     */
    const PAGES = array( 'home', 'product-simple', 'cart-after-add-to-cart', 'checkout-block', 'order-received-block' );

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
        return $this->request_page( $page, WCCT_Test_Settings::only( WCCT_Test_Settings::lite_all(), $integration ), array( 'wcct_consent_mode' => 'on' ) );
    }

    /**
     * @dataProvider pages_and_integrations
     */
    public function test_page_output_with_consent_mode_on( $integration, $page ) {
        $this->assertMatchesFixture( "consent-on/$integration/$page", $this->request( $integration, $page ) );
    }

    public function pages_and_integrations() {
        $cases = array();

        foreach ( self::INTEGRATIONS as $integration ) {
            foreach ( self::PAGES as $page ) {
                $cases[ "$integration / $page" ] = array( $integration, $page );
            }
        }

        return $cases;
    }

    public function test_the_consent_block_is_in_the_head_and_sets_the_default_before_any_config() {
        $result = $this->request( 'all', 'order-received-block' );
        $head   = null;

        // Only the order-key script (#79, wp_head -1000) may print before it; it sends nothing.
        foreach ( $result['chunks'] as $chunk ) {
            if ( false !== strpos( $chunk[1], 'id="wcct-consent-mode"' ) ) {
                $head = $chunk;
                break;
            }

            $this->assertStringContainsString( 'wcct-order-url', $chunk[1] );
        }

        $this->assertNotNull( $head );
        $this->assertSame( 'head', $head[0] );
        $this->assertMatchesRegularExpression( '/^<script (?=[^>]*id="wcct-consent-mode")(?=[^>]*data-no-optimize="1")[^>]*>\n/', $head[1] );
        $this->assertStringContainsString( "g('consent', 'default', {\"ad_storage\":\"denied\",\"analytics_storage\":\"denied\",\"ad_user_data\":\"denied\",\"ad_personalization\":\"denied\",\"region\":[", $head[1] );
        $this->assertStringContainsString( "g('set', 'ads_data_redaction', true);", $head[1] );

        $html = $result['html'];
        // One Google block (#92): both configs come after the default.
        $this->assertLessThan( strpos( $html, "wcctGtag('config', \"G-CTTEST1234\"" ), strpos( $html, "'consent', 'default'" ) );
        $this->assertLessThan( strpos( $html, "wcctGtag('config', \"AW-123456789\"" ), strpos( $html, "'consent', 'default'" ) );
        $this->assertLessThan( strpos( $html, 'googletagmanager.com/gtag/js' ), strpos( $html, "'consent', 'default'" ) );
    }

    public function test_meta_is_revoked_before_init_and_x_does_not_load_uwt_js_itself() {
        $output = $this->output_of( $this->request( 'all', 'home' ) );

        $this->assertMatchesRegularExpression( "/wcctConsent\\.sync\\(\"marketing\", function \\(granted, first\\) \\{ fbq\\('consent', granted \\? 'grant' : 'revoke'\\); \\}\\); \\}fbq\\('init'/", $output );
        $this->assertStringContainsString( 'window.wcctConsent.wrap("wcfbq", "marketing");', $output );
        $this->assertStringContainsString( 'window.wcctConsent.script("marketing", "https://static.ads-twitter.com/uwt.js");', $output );
    }

    public function test_custom_scripts_wait_for_marketing_consent() {
        $output = $this->output_of( $this->request( 'custom', 'order-received-block' ) );

        $this->assertStringContainsString( "<script type=\"text/plain\" data-wcct-consent=\"marketing\">ctCustom('purchase', {", $output );
        $this->assertStringNotContainsString( "<script>ctCustom(", $output );

        // The purchase confirmation after the merchant script waits too, so the order isn't marked sent before it ran.
        $this->assertStringContainsString( '<script type="text/plain" data-wcct-consent="marketing" data-no-optimize="1">window.wcctPurchaseSent', $output );
        $this->assertStringNotContainsString( '<script data-no-optimize="1">window.wcctPurchaseSent', $output );
    }

    public function test_the_pending_events_runner_holds_queued_code_per_platform() {
        $output = $this->output_of( $this->request( 'all', 'home' ) );

        $this->assertStringContainsString( 'window.wcctConsent.inline(({\"facebook\":\"marketing\",', $output );
        $this->assertStringContainsString( '\"ga4\":\"statistics\"', $output );
        $this->assertStringNotContainsString( 'document.body.appendChild(script)', $output );
    }
}
