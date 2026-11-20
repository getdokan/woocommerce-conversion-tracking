<?php
// phpcs:disable

/**
 * Free 3.0 with a Pro older than 3.0 (#125): old Pro stays dormant, Free's storefront output is the same as Free alone, and the update notice shows
 *
 * Uses the 1.1.4 stand-in in tests/integration/plugins/old-pro, loaded before Free (WordPress's order for the two folders) and after it.
 */
class OldProTest extends WCCT_Test_Page_Case {

    /**
     * Pages compared with Free's own fixtures
     *
     * @var string[]
     */
    const PAGES = array( 'home', 'product-simple', 'cart-after-add-to-cart', 'checkout-block', 'order-received-classic', 'order-received-block' );

    protected function setUp(): void {
        parent::setUp();

        if ( count( WCCT_Test_WP::$env['plugins'] ) > 2 ) {
            $this->markTestSkipped( 'Runs on Free alone; Pro is loaded in this run.' );
        }
    }

    protected function fixtures_dir() {
        return dirname( __DIR__, 2 ) . '/fixtures';
    }

    /**
     * WooCommerce, Free and the stand-in, in the given order
     *
     * @param bool $pro_first Load the stand-in before Free.
     *
     * @return string[]
     */
    private function plugins( $pro_first ) {
        list( $woocommerce, $free ) = WCCT_Test_WP::$env['plugins'];
        $old_pro                   = dirname( __DIR__ ) . '/plugins/old-pro/woocommerce-conversion-tracking-pro.php';

        return $pro_first ? array( $woocommerce, $old_pro, $free ) : array( $woocommerce, $free, $old_pro );
    }

    private function run_job( array $job, $pro_first ) {
        $plugins = $this->plugins( $pro_first );

        return WCCT_Test_WP::run(
            array_merge(
                array(
                    'plugins'  => $plugins,
                    'roots'    => array_map( 'dirname', array_slice( $plugins, 1 ) ),
                    'rollback' => true,
                ),
                $job
            )
        );
    }

    public function load_orders() {
        return array(
            'old Pro loaded first' => array( true ),
            'old Pro loaded last'  => array( false ),
        );
    }

    public function pages_and_orders() {
        $cases = array();

        foreach ( self::PAGES as $page ) {
            foreach ( $this->load_orders() as $label => $order ) {
                $cases[ "$page, $label" ] = array( $page, $order[0] );
            }
        }

        return $cases;
    }

    /**
     * @dataProvider pages_and_orders
     */
    public function test_storefront_output_is_free_alone( $page, $pro_first ) {
        $request = WCCT_Test_Pages::get( $page );
        $job     = array(
            'type'      => 'request',
            'request'   => array( 'path' => $request['path'] ),
            'user_id'   => isset( $request['user_id'] ) ? $request['user_id'] : 0,
            'user_meta' => isset( $request['user_meta'] ) ? $request['user_meta'] : array(),
            'options'   => array_merge( isset( $request['options'] ) ? $request['options'] : array(), array( 'wcct_settings' => WCCT_Test_Settings::lite_all() ) ),
        );

        if ( array_key_exists( 'cart', $request ) ) {
            $job['cart'] = $request['cart'];
        }

        $result = $this->run_job( $job, $pro_first );

        $this->assertNull( $result['error'], "Requesting $page failed" );
        $this->assertSame( array(), $result['not_on_page'] );
        $this->assertMatchesFixture( "all/$page", $result );
    }

    /**
     * @dataProvider load_orders
     */
    public function test_old_pro_is_paused_with_a_notice( $pro_first ) {
        $result = $this->run_job(
            array(
                'type'    => 'file',
                'file'    => dirname( __DIR__ ) . '/jobs/pro-guard-state.php',
                'user_id' => 1,
            ),
            $pro_first
        );

        $this->assertNull( $result['error'] );
        $this->assertSame( 'outdated', $result['value']['state'] );
        $this->assertSame( '1.1.4', $result['value']['pro_version'] );
        $this->assertFalse( $result['value']['legacy_hooks'], 'Old Pro is still on wcct_integrations' );
        $this->assertSame(
            array(
                'facebook' => 'WCCT_Integration_Facebook',
                'adwords'  => 'WCCT_Integration_Google',
                'twitter'  => 'WCCT_Integration_Twitter',
                'ga4'      => 'WCCT_Integration_GA4',
                'custom'   => 'WCCT_Integration_Custom',
            ),
            $result['value']['platforms']
        );
        $this->assertStringContainsString( 'Conversion Tracking Pro needs an update to work with 3.0. Pro features are paused until you update.', $result['value']['notice'] );
        $this->assertStringContainsString( 'plugins.php?plugin_status=upgrade', $result['value']['notice'] );
        $this->assertStringContainsString( '>Update Pro</a>', $result['value']['notice'] );
        $this->assertSame( 1, $result['value']['notices'] );
        $this->assertTrue( $result['value']['network'], 'No notice in the network admin' );
        // 1.1.4 asks for Free only when Free is missing, so its notice doesn't print next to Free's.
        $this->assertTrue( $result['value']['old_hooked'] );
        $this->assertSame( '', $result['value']['old_notice'] );
    }

    public function test_free_alone_has_no_notice() {
        $result = WCCT_Test_WP::run(
            array(
                'type'     => 'file',
                'file'     => dirname( __DIR__ ) . '/jobs/pro-guard-state.php',
                'user_id'  => 1,
                'rollback' => true,
            )
        );

        $this->assertNull( $result['error'] );
        $this->assertSame( 'none', $result['value']['state'] );
        $this->assertSame( '', $result['value']['notice'] );
    }

    public function test_old_pro_keeps_serving_its_catalog_feed() {
        $result = $this->run_job(
            array(
                'type'    => 'request',
                'request' => array( 'path' => '/?action=wcct-product-export' ),
            ),
            true
        );

        $this->assertSame( 'Old Pro served the catalog feed', $result['error'] );
    }
}
