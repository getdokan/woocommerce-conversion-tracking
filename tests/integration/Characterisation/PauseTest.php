<?php
// phpcs:disable

/**
 * A paused platform prints nothing on any page, and its queued events are dropped (#103)
 */
class PauseTest extends WCCT_Test_Page_Case {

    const GOOGLE = array(
        'mode'           => 'both',
        'measurement_id' => 'G-CTTEST1234',
        'ads_id'         => 'AW-123456789',
        'purchase_label' => 'CtPurchaseLabel',
        'events'         => array( 'view_product', 'add_to_cart', 'begin_checkout', 'purchase', 'search', 'sign_up' ),
    );

    /**
     * Pages a shopper goes through, from browsing to the thank-you page
     */
    const PAGES = array( 'home', 'product-simple', 'checkout-block', 'order-received-block', 'order-received-classic', 'registration' );

    protected function fixtures_dir() {
        return dirname( __DIR__, 2 ) . '/fixtures';
    }

    /**
     * Every page's output and event code
     *
     * @param array $settings The wcct_settings option.
     * @param array $options  Other options.
     *
     * @return string
     */
    private function all_pages( array $settings, array $options ) {
        $output = '';

        foreach ( self::PAGES as $page ) {
            $result  = $this->request_page( $page, $settings, $options );
            $output .= $this->output_of( $result ) . $this->event_js( $result );
        }

        return $output;
    }

    public function test_a_paused_google_prints_nothing_for_analytics_or_ads() {
        $v3 = array( 'wcct_settings_v3' => array( 'platforms' => array( 'google' => self::GOOGLE ) ) );

        $on = $this->all_pages( array(), $v3 );

        $this->assertStringContainsString( 'G-CTTEST1234', $on );
        $this->assertStringContainsString( 'AW-123456789', $on );

        $paused = $this->all_pages( array(), $v3 + array( 'wcct_paused_platforms' => array( 'google' ) ) );

        foreach ( array( 'gtag', 'G-CTTEST1234', 'AW-123456789', 'CtPurchaseLabel', 'wcctPurchaseSent' ) as $needle ) {
            $this->assertStringNotContainsString( $needle, $paused );
        }
    }

    public function test_a_paused_google_prints_nothing_from_the_2x_settings_either() {
        $paused = $this->all_pages( WCCT_Test_Settings::lite_all(), array( 'wcct_paused_platforms' => array( 'google' ) ) );

        foreach ( array( 'gtag', 'G-CTTEST1234', 'AW-123456789', 'CtPurchaseLabel', '"ga4"', '"adwords"' ) as $needle ) {
            $this->assertStringNotContainsString( $needle, $paused );
        }

        // The other platforms keep sending.
        $this->assertStringContainsString( "fbq('track', 'Purchase'", $paused );
        $this->assertStringContainsString( 'twq(', $paused );
    }

    public function test_a_paused_meta_prints_nothing_including_the_purchase_confirmation() {
        $settings = WCCT_Test_Settings::lite_all();
        $on       = $this->all_pages( $settings, array() );

        $this->assertStringContainsString( "fbq('track', 'Purchase'", $on );
        $this->assertStringContainsString( '"facebook"', $on );

        $paused = $this->all_pages( $settings, array( 'wcct_paused_platforms' => array( 'facebook' ) ) );

        foreach ( array( 'fbq', 'fbevents.js', '111111111111111', '"facebook"', 'wcfbq' ) as $needle ) {
            $this->assertStringNotContainsString( $needle, $paused );
        }

        $this->assertStringContainsString( 'G-CTTEST1234', $paused );
        $this->assertStringContainsString( 'twq(', $paused );
    }

    public function test_a_paused_custom_script_prints_no_placement() {
        $paused = $this->all_pages( WCCT_Test_Settings::lite_all(), array( 'wcct_paused_platforms' => array( 'custom' ) ) );

        $this->assertStringNotContainsString( 'ctCustom', $paused );
        $this->assertStringContainsString( 'ctCustom', $this->all_pages( WCCT_Test_Settings::lite_all(), array() ) );
    }

    public function test_resuming_a_2x_meta_that_was_switched_off_prints_meta_again() {
        $v1                        = WCCT_Test_Settings::lite_all();
        $v1['facebook']['enabled'] = false;

        $before = $this->all_pages( $v1, array() );

        $this->assertStringNotContainsString( 'fbq', $before );

        $result = WCCT_Test_WP::run(
            array(
                'type'     => 'file',
                'file'     => dirname( __DIR__ ) . '/jobs/pause-resume-v1.php',
                'rollback' => true,
                'options'  => array( 'wcct_settings' => $v1 ),
                'args'     => array( 'platform' => 'facebook' ),
            )
        );

        $this->assertNull( $result['error'] );
        $this->assertSame( array(), $result['value']['wcct_paused_platforms'] );

        $after = $this->all_pages( $result['value']['wcct_settings'], array( 'wcct_paused_platforms' => array() ) );

        $this->assertStringContainsString( "fbq('init', '111111111111111'", $after );
        $this->assertStringContainsString( "fbq('track', 'Purchase'", $after );
    }

    public function test_queued_add_to_cart_of_a_platform_paused_since_is_dropped() {
        $result = WCCT_Test_WP::run(
            array(
                'type'     => 'file',
                'file'     => dirname( __DIR__ ) . '/jobs/pause-pending-add-to-cart.php',
                'rollback' => true,
                'options'  => array( 'wcct_settings' => WCCT_Test_Settings::lite_all() ),
            )
        );

        $this->assertNull( $result['error'] );

        $value = $result['value'];

        $this->assertSame( array( 'facebook', 'ga4' ), $value['queued'] );
        $this->assertSame( array( 'ga4' ), $value['after_meta_paused'] );
        $this->assertSame( array(), $value['after_google_paused'] );
        // The entry stays, so the browser's seen cookie still moves on.
        $this->assertSame( 1, $value['entries'] );
        $this->assertStringNotContainsString( 'fbq', $value['fragment'] );
        $this->assertSame( array( 'facebook', 'ga4' ), $value['after_resume'] );
    }
}
