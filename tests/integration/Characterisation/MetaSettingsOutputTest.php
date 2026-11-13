<?php
// phpcs:disable

/**
 * Meta reads the 3.0 settings; Free sends one pixel and no custom parameters
 */
class MetaSettingsOutputTest extends WCCT_Test_Page_Case {

    protected function fixtures_dir() {
        return dirname( __DIR__, 2 ) . '/fixtures';
    }

    protected function setUp(): void {
        if ( count( WCCT_Test_WP::$env['plugins'] ) > 2 ) {
            $this->markTestSkipped( 'Pro is loaded.' );
        }
    }

    public function test_free_sends_the_3_0_pixel_only_without_custom_parameters() {
        $v3 = array(
            'platforms' => array(
                'facebook' => array(
                    'pixel_id'          => '444444444444444',
                    'events'            => array( 'view_product' ),
                    // Saved while Pro was active.
                    'pixels'            => array( array( 'pixel_id' => '555555555555555', 'events' => array( 'view_product' ) ) ),
                    'custom_parameters' => array( array( 'event' => 'view_product', 'name' => 'sku', 'token' => '{product_sku}' ) ),
                ),
            ),
        );

        $result = $this->request_page( 'product-simple', WCCT_Test_Settings::only( WCCT_Test_Settings::lite_all(), 'facebook' ), array( 'wcct_settings_v3' => $v3 ) );
        $output = $this->output_of( $result );
        $events = $this->event_js( $result );

        $this->assertSame( 1, substr_count( $output, "fbq('init', '444444444444444'" ) );
        $this->assertStringNotContainsString( '111111111111111', $output );
        $this->assertStringNotContainsString( '555555555555555', $output );
        $this->assertSame( 1, substr_count( $events, 'wcfbq("444444444444444", "ViewContent"' ) );
        $this->assertStringNotContainsString( '"sku"', $events );
        $this->assertStringNotContainsString( 'CT-BEANIE', $events );
    }
}
