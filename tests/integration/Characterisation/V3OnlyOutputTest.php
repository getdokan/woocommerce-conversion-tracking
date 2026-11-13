<?php
// phpcs:disable

/**
 * A store set up only through the 3.0 settings (no wcct_settings, as the wizard and the Platforms pages leave it) prints what the migrated store prints for the same IDs
 *
 * Each request seeds wcct_settings_v3 with what the migration writes for the 2.x test settings and deletes wcct_settings, then compares with the fixtures recorded from the migrated store.
 */
class V3OnlyOutputTest extends WCCT_Test_Page_Case {

    /**
     * Integrations, each set up alone, then all together
     *
     * @var string[]
     */
    const INTEGRATIONS = array( 'facebook', 'twitter', 'adwords', 'ga4', 'custom', 'all' );

    protected function setUp(): void {
        parent::setUp();

        if ( count( WCCT_Test_WP::$env['plugins'] ) > 2 ) {
            $this->markTestSkipped( 'The Lite fixtures are recorded without Pro.' );
        }
    }

    protected function fixtures_dir() {
        return dirname( __DIR__, 2 ) . '/fixtures';
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

    /**
     * @dataProvider pages_and_integrations
     */
    public function test_a_3_0_only_store_prints_the_migrated_output( $integration, $page ) {
        $this->assertMatchesFixture( "$integration/$page", $this->request_v3_page( $page, WCCT_Test_Settings::only( WCCT_Test_Settings::lite_all(), $integration ) ) );
    }

    public function test_meta_and_x_print_from_the_3_0_settings_alone() {
        $output = $this->output_of( $this->request_v3_page( 'order-received-block', WCCT_Test_Settings::lite_all() ) );

        $this->assertStringContainsString( "fbq('init', '111111111111111'", $output );
        $this->assertMatchesRegularExpression( '/wcfbq\(\\\\?"111111111111111\\\\?", \\\\?"Purchase/', $output );
        $this->assertStringContainsString( "twq('config', \"ct0x1\")", $output );
        $this->assertStringContainsString( 'tw-ct0x1-ctpur', $output );
    }

    public function test_a_store_saved_with_only_its_ids_sends_the_recommended_events() {
        // What the Platforms pages store when a merchant types the IDs and leaves the recommended events alone.
        $v3 = array(
            'platforms' => array(
                'facebook' => array( 'pixel_id' => '111111111111111' ),
                // No mode: the schema default (Analytics and Ads) with only the Analytics ID.
                'google'   => array( 'measurement_id' => 'G-CTTEST1234' ),
                'twitter'  => array( 'pixel_id' => 'ct0x1', 'purchase_event_id' => 'tw-ct0x1-ctpur' ),
            ),
        );

        $product = $this->output_of( $this->request_page( 'product-simple', null, array( 'wcct_settings_v3' => $v3 ) ) );

        $this->assertMatchesRegularExpression( '/wcfbq\(\\\\?"111111111111111\\\\?", \\\\?"ViewContent/', $product );
        $this->assertMatchesRegularExpression( '/\\\\?"view_item\\\\?",\{\\\\?"send_to\\\\?":\\\\?"G-CTTEST1234/', $product );

        $thankyou = $this->output_of( $this->request_page( 'order-received-block', null, array( 'wcct_settings_v3' => $v3 ) ) );

        $this->assertMatchesRegularExpression( '/wcfbq\(\\\\?"111111111111111\\\\?", \\\\?"Purchase/', $thankyou );
        $this->assertMatchesRegularExpression( '/\\\\?"purchase\\\\?",\{\\\\?"send_to\\\\?":\\\\?"G-CTTEST1234/', $thankyou );
        $this->assertStringContainsString( 'tw-ct0x1-ctpur', $thankyou );
    }

    public function test_a_platform_without_its_id_in_the_3_0_settings_prints_nothing() {
        $settings = WCCT_Test_Settings::only( WCCT_Test_Settings::lite_all(), 'facebook' );
        $migrated = $this->migrated( $settings );

        $migrated['v3']['platforms']['facebook']['pixel_id'] = '';

        $output = $this->output_of( $this->request_page( 'product-simple', null, array( 'wcct_settings_v3' => $migrated['v3'] ) ) );

        $this->assertStringNotContainsString( 'fbq(', $output );
        $this->assertStringNotContainsString( 'wcfbq(', $output );
    }

    public function test_a_paused_platform_in_the_3_0_settings_prints_nothing() {
        $migrated = $this->migrated( WCCT_Test_Settings::lite_all() );

        $output = $this->output_of(
            $this->request_page(
                'order-received-block',
                null,
                array(
                    'wcct_settings_v3'      => $migrated['v3'],
                    'wcct_paused_platforms' => array( 'facebook', 'twitter' ),
                )
            )
        );

        $this->assertStringNotContainsString( "fbq('init'", $output );
        $this->assertStringNotContainsString( 'twq(', $output );
        $this->assertStringContainsString( 'G-CTTEST1234', $output );
    }
}
