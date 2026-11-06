<?php

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Events\ProductId;

/**
 * A product with the WC_Product getters ProductId reads; get_sku( 'view' ) falls back to the parent like a variation.
 */
class WCCT_Test_Fake_Product {

    private $id;
    private $sku;
    private $parent_sku;

    public function __construct( $id, $sku = '', $parent_sku = '' ) {
        $this->id         = $id;
        $this->sku        = $sku;
        $this->parent_sku = $parent_sku;
    }

    public function get_id() {
        return $this->id;
    }

    public function get_sku( $context = 'view' ) {
        return 'view' === $context && '' === $this->sku ? $this->parent_sku : $this->sku;
    }
}

/**
 * An order line with the WC_Order_Item_Product getters ProductId reads.
 */
class WCCT_Test_Fake_Order_Item {

    private $product;
    private $product_id;
    private $variation_id;

    public function __construct( $product, $product_id, $variation_id = 0 ) {
        $this->product      = $product;
        $this->product_id   = $product_id;
        $this->variation_id = $variation_id;
    }

    public function get_product() {
        return $this->product;
    }

    public function get_product_id() {
        return $this->product_id;
    }

    public function get_variation_id() {
        return $this->variation_id;
    }
}

/**
 * Tests for the Product ID sent rule
 */
class ProductIdTest extends TestCase {

    protected function tearDown(): void {
        unset( $GLOBALS['wcct_test_options'], $GLOBALS['wcct_test_filters'] );
    }

    private function set_mode( $platform, $mode ) {
        $GLOBALS['wcct_test_options']['wcct_settings_v3']['platforms'][ $platform ][ ProductId::FIELD ] = $mode;
    }

    public function test_simple_product_sends_its_id_or_its_sku() {
        $beanie = new WCCT_Test_Fake_Product( 2001, 'CT-BEANIE' );

        $this->assertSame( 2001, ProductId::value( $beanie, ProductId::ID ) );
        $this->assertSame( 'CT-BEANIE', ProductId::value( $beanie, ProductId::SKU ) );
    }

    public function test_variation_with_a_sku_sends_its_own_id_or_sku() {
        $blue = new WCCT_Test_Fake_Product( 2003, 'CT-HOODIE-BLUE', 'CT-HOODIE' );

        $this->assertSame( 2003, ProductId::value( $blue, ProductId::ID ) );
        $this->assertSame( 'CT-HOODIE-BLUE', ProductId::value( $blue, ProductId::SKU ) );
    }

    public function test_variation_without_a_sku_falls_back_to_its_own_id_not_the_parent_sku() {
        $red = new WCCT_Test_Fake_Product( 2004, '', 'CT-HOODIE' );

        $this->assertSame( 2004, ProductId::value( $red, ProductId::ID ) );
        $this->assertSame( '2004', ProductId::value( $red, ProductId::SKU ) );
    }

    public function test_blank_sku_falls_back_to_the_id() {
        $this->assertSame( '7', ProductId::value( new WCCT_Test_Fake_Product( 7, '   ' ), ProductId::SKU ) );
    }

    public function test_no_product_is_zero() {
        $this->assertSame( 0, ProductId::value( null, ProductId::SKU ) );
        $this->assertSame( 0, ProductId::value( 'nope', ProductId::ID ) );
    }

    public function test_mode_defaults_to_id() {
        $this->assertSame( ProductId::ID, ProductId::mode_for( 'facebook' ) );
        $this->assertSame( ProductId::ID, ProductId::feed_mode() );
    }

    public function test_mode_is_read_per_platform_and_ga4_and_adwords_read_google() {
        $this->set_mode( 'facebook', ProductId::SKU );
        $this->set_mode( 'google', ProductId::SKU );

        $this->assertSame( ProductId::SKU, ProductId::mode_for( 'facebook' ) );
        $this->assertSame( ProductId::SKU, ProductId::mode_for( 'ga4' ) );
        $this->assertSame( ProductId::SKU, ProductId::mode_for( 'adwords' ) );
        $this->assertSame( ProductId::ID, ProductId::mode_for( 'twitter' ) );
    }

    public function test_unknown_stored_or_filtered_mode_reads_as_id() {
        $this->set_mode( 'facebook', 'gtin' );
        $this->assertSame( ProductId::ID, ProductId::mode_for( 'facebook' ) );

        $GLOBALS['wcct_test_filters']['wcct_product_id_mode'] = function () {
            return array( 'sku' );
        };
        $this->assertSame( ProductId::ID, ProductId::mode_for( 'facebook' ) );
    }

    public function test_filter_gets_the_settings_platform() {
        $seen = null;

        $GLOBALS['wcct_test_filters']['wcct_product_id_mode'] = function ( $mode, $platform ) use ( &$seen ) {
            $seen = $platform;

            return ProductId::SKU;
        };

        $this->assertSame( ProductId::SKU, ProductId::mode_for( 'ga4' ) );
        $this->assertSame( 'google', $seen );
    }

    public function test_feed_mode_is_its_own_setting_and_the_doctor_compares_both() {
        $GLOBALS['wcct_test_options']['wcct_data_feed'] = array(
            'types'          => array(),
            ProductId::FIELD => ProductId::SKU,
        );

        $this->assertSame( ProductId::SKU, ProductId::feed_mode() );
        $this->assertFalse( ProductId::feed_matches( 'facebook' ) );

        $this->set_mode( 'facebook', ProductId::SKU );
        $this->assertTrue( ProductId::feed_matches() );

        $hoodie_blue = new WCCT_Test_Fake_Product( 2003, 'CT-HOODIE-BLUE', 'CT-HOODIE' );
        $this->assertSame( ProductId::for_feed( $hoodie_blue ), ProductId::for_product( $hoodie_blue, 'facebook' ) );
    }

    public function test_cart_sends_each_line_product_in_the_platform_mode() {
        $cart = array(
            array( 'data' => new WCCT_Test_Fake_Product( 2001, 'CT-BEANIE' ) ),
            array( 'data' => new WCCT_Test_Fake_Product( 2004, '', 'CT-HOODIE' ) ),
            array( 'quantity' => 1 ),
        );

        $this->assertSame( array( 2001, 2004 ), ProductId::for_cart( $cart, 'facebook' ) );

        $this->set_mode( 'facebook', ProductId::SKU );
        $this->assertSame( array( 'CT-BEANIE', '2004' ), ProductId::for_cart( $cart, 'facebook' ) );
    }

    public function test_order_sends_variation_ids_and_keeps_deleted_products() {
        $order = new class() {
            public function get_items() {
                return array(
                    new WCCT_Test_Fake_Order_Item( new WCCT_Test_Fake_Product( 2001, 'CT-BEANIE' ), 2001 ),
                    new WCCT_Test_Fake_Order_Item( new WCCT_Test_Fake_Product( 2003, 'CT-HOODIE-BLUE', 'CT-HOODIE' ), 2002, 2003 ),
                    new WCCT_Test_Fake_Order_Item( false, 2002, 2009 ),
                    new WCCT_Test_Fake_Order_Item( false, 0 ),
                );
            }
        };

        $this->assertSame( array( 2001, 2003, 2009 ), ProductId::for_order( $order, 'twitter' ) );

        $this->set_mode( 'twitter', ProductId::SKU );
        $this->assertSame( array( 'CT-BEANIE', 'CT-HOODIE-BLUE', '2009' ), ProductId::for_order( $order, 'twitter' ) );
        $this->assertSame( array(), ProductId::for_order( null, 'twitter' ) );
    }

    public function test_event_sends_its_product_or_its_product_ids() {
        $product_event = new class() {
            public function get_product() {
                return new WCCT_Test_Fake_Product( 2003, 'CT-HOODIE-BLUE', 'CT-HOODIE' );
            }

            public function get_product_ids() {
                return array( 2003 );
            }
        };
        $cart_event    = new class() {
            public function get_product() {
                return null;
            }

            public function get_product_ids() {
                return array( 2001, 2003 );
            }
        };

        $this->assertSame( array( 2003 ), ProductId::for_event( $product_event, 'twitter' ) );
        $this->assertSame( array( 2001, 2003 ), ProductId::for_event( $cart_event, 'twitter' ) );
        $this->assertSame( array(), ProductId::for_event( null, 'twitter' ) );

        $this->set_mode( 'twitter', ProductId::SKU );
        $this->assertSame( array( 'CT-HOODIE-BLUE' ), ProductId::for_event( $product_event, 'twitter' ) );
        // No WooCommerce in unit tests: the IDs stay, as strings.
        $this->assertSame( array( '2001', '2003' ), ProductId::for_event( $cart_event, 'twitter' ) );
    }
}
