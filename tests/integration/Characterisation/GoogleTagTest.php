<?php
// phpcs:disable

/**
 * Google as one platform: Analytics, Ads or Both on one gtag loader, GT- IDs, search and sign_up (#92, #87)
 */
class GoogleTagTest extends WCCT_Test_Page_Case {

    const GOOGLE = array(
        'measurement_id'     => 'G-CTTEST1234',
        'ads_id'             => 'AW-123456789',
        'purchase_label'     => 'CtPurchaseLabel',
        'registration_label' => 'CtRegistrationLabel',
        'events'             => array( 'view_product', 'add_to_cart', 'begin_checkout', 'purchase', 'search', 'sign_up' ),
    );

    protected function setUp(): void {
        parent::setUp();

        if ( count( WCCT_Test_WP::$env['plugins'] ) > 2 ) {
            $this->markTestSkipped( 'Runs on Lite alone.' );
        }
    }

    protected function fixtures_dir() {
        return dirname( __DIR__, 2 ) . '/fixtures';
    }

    /**
     * A page with the google platform saved in wcct_settings_v3
     *
     * @param string $page   Page name.
     * @param array  $google Google platform values over GOOGLE.
     *
     * @return array
     */
    private function request( $page, array $google ) {
        return $this->request_page( $page, array(), array( 'wcct_settings_v3' => array( 'platforms' => array( 'google' => array_merge( self::GOOGLE, $google ) ) ) ) );
    }

    private function head( array $result ) {
        $output = '';

        foreach ( $result['chunks'] as $chunk ) {
            $output .= 'head' === $chunk[0] ? $chunk[1] : '';
        }

        return $output;
    }

    public function test_both_prints_one_loader_and_one_config_per_id() {
        $head = $this->head( $this->request( 'home', array( 'mode' => 'both' ) ) );

        $this->assertStringContainsString( '<script async data-no-optimize="1" data-cfasync="false" src="https://www.googletagmanager.com/gtag/js?id=G-CTTEST1234"></script>', $head );
        $this->assertStringContainsString( '<script data-no-optimize="1" data-cfasync="false">', $head );

        $this->assertSame( 1, substr_count( $head, 'googletagmanager.com/gtag/js' ) );
        $this->assertStringContainsString( 'gtag/js?id=G-CTTEST1234"', $head );
        $this->assertSame( 1, substr_count( $head, "wcctGtag('js'" ) );
        $this->assertSame( 1, substr_count( $head, "wcctGtag('config', \"G-CTTEST1234\"" ) );
        $this->assertSame( 1, substr_count( $head, "wcctGtag('config', \"AW-123456789\"" ) );
        $this->assertSame( 2, substr_count( $head, "wcctGtag('config'" ) );
    }

    public function test_each_mode_configures_only_its_id() {
        $analytics = $this->request( 'product-simple', array( 'mode' => 'analytics' ) );
        $ads       = $this->request( 'product-simple', array( 'mode' => 'ads' ) );

        $this->assertStringContainsString( "wcctGtag('config', \"G-CTTEST1234\"", $this->head( $analytics ) );
        $this->assertStringNotContainsString( 'AW-123456789', $this->output_of( $analytics ) );
        $this->assertStringContainsString( '"view_item"', $this->event_js( $analytics ) );

        $this->assertStringContainsString( 'gtag/js?id=AW-123456789"', $this->head( $ads ) );
        $this->assertStringContainsString( "wcctGtag('config', \"AW-123456789\"", $this->head( $ads ) );
        $this->assertStringNotContainsString( 'G-CTTEST1234', $this->output_of( $ads ) );
    }

    public function test_a_paused_or_empty_google_prints_nothing() {
        $this->assertStringNotContainsString( 'gtag', $this->output_of( $this->request( 'home', array( 'paused' => true ) ) ) );
        $this->assertStringNotContainsString( 'gtag', $this->output_of( $this->request( 'home', array( 'measurement_id' => '', 'ads_id' => '' ) ) ) );
    }

    public function test_a_google_tag_id_loads_and_configures_while_events_go_to_the_measurement_id() {
        $result = $this->request( 'product-simple', array( 'mode' => 'analytics', 'tag_id' => 'GT-CTTAG123' ) );
        $head   = $this->head( $result );

        $this->assertStringContainsString( 'gtag/js?id=GT-CTTAG123"', $head );
        $this->assertStringContainsString( "wcctGtag('config', \"GT-CTTAG123\"", $head );
        $this->assertStringNotContainsString( "wcctGtag('config', \"G-CTTEST1234\"", $head );
        $this->assertStringContainsString( '"view_item",{"send_to":"G-CTTEST1234"', $this->event_js( $result ) );
    }

    public function test_a_gt_measurement_id_is_accepted_and_ua_is_not() {
        $gt = $this->request( 'product-simple', array( 'mode' => 'analytics', 'measurement_id' => 'GT-CTTAG123' ) );

        $this->assertStringContainsString( "wcctGtag('config', \"GT-CTTAG123\"", $this->head( $gt ) );
        $this->assertStringContainsString( '"view_item",{"send_to":"GT-CTTAG123"', $this->event_js( $gt ) );

        $this->assertStringNotContainsString( 'gtag', $this->output_of( $this->request( 'product-simple', array( 'mode' => 'analytics', 'measurement_id' => 'UA-12345-1' ) ) ) );
    }

    public function test_store_search_sends_one_search_with_the_term() {
        $js = $this->event_js( $this->request( 'search', array( 'mode' => 'both' ) ) );

        $this->assertSame( 1, substr_count( $js, '"search",' ) );
        $this->assertStringContainsString( '["search",{"send_to":"G-CTTEST1234","search_term":"cotton"}]', $js );

        $off = $this->request( 'search', array( 'mode' => 'both', 'events' => array( 'purchase' ) ) );

        $this->assertStringNotContainsString( '"search"', $this->event_js( $off ) );
    }

    public function test_a_product_search_with_a_post_type_list_sends_search() {
        $result = WCCT_Test_WP::run(
            array(
                'type'     => 'request',
                'rollback' => true,
                'request'  => array( 'path' => '/?s=cotton&post_type%5B%5D=product' ),
                'options'  => array( 'wcct_settings_v3' => array( 'platforms' => array( 'google' => self::GOOGLE ) ) ),
            )
        );

        $this->assertNull( $result['error'] );
        $this->assertSame( 1, substr_count( $result['html'], '["search",{"send_to":"G-CTTEST1234","search_term":"cotton"}]' ) );
    }

    public function test_a_blog_search_sends_no_search() {
        $result = WCCT_Test_WP::run(
            array(
                'type'     => 'request',
                'rollback' => true,
                'request'  => array( 'path' => '/?s=cotton' ),
                'options'  => array( 'wcct_settings_v3' => array( 'platforms' => array( 'google' => self::GOOGLE ) ) ),
            )
        );

        $this->assertNull( $result['error'] );
        $this->assertStringNotContainsString( '"search_term"', $result['html'] );
    }

    public function test_sign_up_goes_to_analytics_and_never_to_ads_on_lite() {
        $js = $this->event_js( $this->request( 'registration', array( 'mode' => 'both' ) ) );

        $this->assertSame( 1, substr_count( $js, '["sign_up",{"send_to":"G-CTTEST1234","method":"WooCommerce"}]' ) );
        $this->assertStringNotContainsString( 'CtRegistrationLabel', $js );
        $this->assertStringNotContainsString( '"sign_up"', $this->event_js( $this->request( 'my-account', array( 'mode' => 'both' ) ) ) );
    }

    public function test_the_ads_conversion_carries_the_label_and_waits_for_its_confirmation() {
        $js = $this->event_js( $this->request( 'order-received-block', array( 'mode' => 'ads' ) ) );

        $this->assertSame( 1, substr_count( $js, "})('event', 'conversion'" ) );
        $this->assertStringContainsString( '"send_to": "AW-123456789/CtPurchaseLabel"', $js );
        $this->assertStringContainsString( '"event_callback": ', $js );
    }
}
