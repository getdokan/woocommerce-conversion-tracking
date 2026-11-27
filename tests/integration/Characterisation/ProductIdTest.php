<?php
// phpcs:disable

use PHPUnit\Framework\TestCase;

/**
 * The Product ID sent rule (#96) on the test store
 */
class ProductIdTest extends TestCase {

    private function sku_mode( array $platforms ) {
        $settings = array( 'platforms' => array() );

        foreach ( $platforms as $platform ) {
            $settings['platforms'][ $platform ] = array( 'product_id_mode' => 'sku' );
        }

        // Every platform reads its IDs from wcct_settings_v3 once it exists (#92, #84, #89): the lite_all() values.
        $ids = array(
            'facebook' => array(
                'pixel_id' => '111111111111111',
                'events'   => array( 'view_product', 'add_to_cart', 'begin_checkout', 'purchase', 'search', 'sign_up' ),
            ),
            'twitter'  => array(
                'pixel_id'          => 'ct0x1',
                'purchase_event_id' => 'tw-ct0x1-ctpur',
                'events'            => array( 'page_view', 'purchase' ),
            ),
        );

        foreach ( $ids as $platform => $values ) {
            if ( isset( $settings['platforms'][ $platform ] ) ) {
                $settings['platforms'][ $platform ] += $values;
            }
        }

        if ( isset( $settings['platforms']['google'] ) ) {
            $settings['platforms']['google'] += array(
                'mode'           => 'both',
                'measurement_id' => 'G-CTTEST1234',
                'ads_id'         => 'AW-123456789',
                'purchase_label' => 'CtPurchaseLabel',
                'events'         => array( 'view_product', 'add_to_cart', 'begin_checkout', 'purchase' ),
            );
        }

        return $settings;
    }

    public function test_product_page_reports_the_picked_variation_and_ids_follow_each_platform() {
        $result = WCCT_Test_WP::run(
            array(
                'type'     => 'file',
                'file'     => dirname( __DIR__ ) . '/jobs/product-ids.php',
                'rollback' => true,
                'options'  => array( 'wcct_settings_v3' => $this->sku_mode( array( 'facebook' ) ) ),
            )
        );

        $this->assertNull( $result['error'] );

        $value = $result['value'];

        $this->assertSame( 2002, $value['no_selection'] );
        $this->assertSame( 2004, $value['default'] );
        $this->assertSame( 2003, $value['url'], 'The URL selection wins over the default' );
        $this->assertSame( 2002, $value['unknown'] );
        $this->assertSame( array( 2004 ), $value['event'] );

        $cap = $value['cap'];
        $this->assertSame( $cap['parent'], $cap['partial'], 'Only some attributes picked: no variation' );
        $this->assertSame( $cap['variations']['M-Blue'], $cap['url_and_default'], 'The URL and the default together pick one' );
        $this->assertSame( $cap['parent'], $cap['array_in_url'], 'A non-string URL value is ignored, and the default alone picks no colour' );

        $this->assertSame( array( 2001 => 'CT-BEANIE', 2003 => 'CT-HOODIE-BLUE', 2004 => '2004' ), $value['ids']['facebook'] );
        $this->assertSame( array( 2001 => 2001, 2003 => 2003, 2004 => 2004 ), $value['ids']['ga4'] );
        $this->assertSame( array( 2001 => 2001, 2003 => 2003, 2004 => 2004 ), $value['ids']['twitter'] );
    }

    public function test_add_to_cart_sends_the_added_variation_sku_in_sku_mode() {
        $result = WCCT_Test_WP::run(
            array(
                'type'     => 'file',
                'file'     => dirname( __DIR__ ) . '/jobs/pending-add-to-cart.php',
                'rollback' => true,
                'options'  => array(
                    'wcct_settings'    => WCCT_Test_Settings::lite_all(),
                    'wcct_settings_v3' => $this->sku_mode( array( 'facebook', 'google' ) ),
                ),
            )
        );

        $this->assertNull( $result['error'] );

        $queued = $result['value']['queued'];

        $this->assertMatchesRegularExpression( '/"content_ids": \[\s*"CT-BEANIE"\s*\]/', $queued[0]['scripts']['facebook'] );
        $this->assertMatchesRegularExpression( '/"content_ids": \[\s*"CT-HOODIE-BLUE"\s*\]/', $queued[1]['scripts']['facebook'] );
        $this->assertStringContainsString( '"item_id":"CT-HOODIE-BLUE"', $queued[1]['scripts']['ga4'] );
    }

    public function test_purchase_sends_variation_skus_in_sku_mode() {
        $result = WCCT_Test_WP::run(
            array(
                'type'     => 'file',
                'file'     => dirname( __DIR__ ) . '/jobs/thankyou-fallback.php',
                'rollback' => true,
                'order_id' => 3001,
                'user_id'  => 0,
                'options'  => array(
                    'wcct_settings'    => WCCT_Test_Settings::lite_all(),
                    'wcct_settings_v3' => $this->sku_mode( array( 'facebook', 'google', 'twitter' ) ),
                ),
            )
        );

        $this->assertNull( $result['error'] );

        $scripts = $result['value']['scripts'];

        $this->assertMatchesRegularExpression( '/"content_ids": \[\s*"CT-BEANIE",\s*"CT-HOODIE-BLUE"\s*\],\s*"content_type": "product"/', $scripts['facebook'] );
        $this->assertStringContainsString( '"content_id":"CT-BEANIE"', $scripts['twitter'] );
        $this->assertStringContainsString( '"content_id":"CT-HOODIE-BLUE"', $scripts['twitter'] );
        $this->assertStringContainsString( '"item_id":"CT-BEANIE"', $scripts['ga4'] );
        $this->assertStringContainsString( '"item_id":"CT-HOODIE-BLUE"', $scripts['ga4'] );
    }
}
