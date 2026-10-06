<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for WCCT_GA4_Data
 */
class GA4DataTest extends TestCase {

    public function test_measurement_id_is_trimmed_and_upper_cased() {
        $this->assertSame( 'G-ABC123XYZ', WCCT_GA4_Data::sanitize_measurement_id( '  g-abc123xyz ' ) );
    }

    /**
     * @dataProvider invalid_measurement_ids
     */
    public function test_invalid_measurement_ids_are_rejected( $measurement_id ) {
        $this->assertSame( '', WCCT_GA4_Data::sanitize_measurement_id( $measurement_id ) );
    }

    public function invalid_measurement_ids() {
        return array(
            'empty'        => array( '' ),
            'google ads'   => array( 'AW-123456789' ),
            'google tag'   => array( 'GT-ABCDEFG' ),
            'universal'    => array( 'UA-12345-1' ),
            'too short'    => array( 'G-ABC' ),
            'script'       => array( 'G-ABC123</script>' ),
            'quote'        => array( "G-ABC123'" ),
            'not a string' => array( array( 'G-ABC123' ) ),
        );
    }

    public function test_line_item_values_without_discount() {
        $this->assertSame( array( 'price' => 10.0, 'discount' => 0 ), WCCT_GA4_Data::line_item_values( 20, 20, 2 ) );
    }

    public function test_line_item_values_with_discount_are_per_unit() {
        // 3 x 10.00 = 30.00 before discount, 24.00 after: 8.00 each, 2.00 off each.
        $this->assertSame( array( 'price' => 8.0, 'discount' => 2.0 ), WCCT_GA4_Data::line_item_values( '30.00', '24.00', 3 ) );
    }

    public function test_line_item_values_with_zero_quantity_does_not_divide_by_zero() {
        $this->assertSame( array( 'price' => 5.0, 'discount' => 0 ), WCCT_GA4_Data::line_item_values( 5, 5, 0 ) );
    }

    public function test_line_item_values_round_to_store_decimals() {
        $this->assertSame( array( 'price' => 3.333, 'discount' => 0 ), WCCT_GA4_Data::line_item_values( 10, 10, 3, 3 ) );
    }

    public function test_build_item_with_all_fields() {
        $item = WCCT_GA4_Data::build_item(
            array(
                'id'         => 123,
                'name'       => 'T-Shirt',
                'variant'    => 'Blue, Large',
                'categories' => array( 'Clothing', 'Tops', 'Tops', '', 'Shirts' ),
            ),
            '2',
            '18.5',
            '1.5'
        );

        $this->assertSame(
            array(
                'item_id'        => '123',
                'item_name'      => 'T-Shirt',
                'item_variant'   => 'Blue, Large',
                'item_category'  => 'Clothing',
                'item_category2' => 'Tops',
                'item_category3' => 'Shirts',
                'price'          => 18.5,
                'discount'       => 1.5,
                'quantity'       => 2,
            ),
            $item
        );
    }

    public function test_build_item_keeps_at_most_five_categories() {
        $item = WCCT_GA4_Data::build_item( array( 'id' => 1, 'name' => 'A', 'categories' => array( 'a', 'b', 'c', 'd', 'e', 'f' ) ), 1, 1 );

        $this->assertSame( 'e', $item['item_category5'] );
        $this->assertArrayNotHasKey( 'item_category6', $item );
    }

    public function test_build_item_omits_empty_variant_and_zero_discount() {
        $item = WCCT_GA4_Data::build_item( array( 'id' => 'SKU-1', 'name' => 'A', 'variant' => '' ), 0, 4 );

        $this->assertArrayNotHasKey( 'item_variant', $item );
        $this->assertArrayNotHasKey( 'discount', $item );
        $this->assertSame( 1, $item['quantity'] );
        $this->assertSame( 'SKU-1', $item['item_id'] );
    }

    public function test_items_value_is_price_times_quantity() {
        $items = array(
            array( 'price' => 8.0, 'quantity' => 3 ),
            array( 'price' => 2.5, 'quantity' => 2 ),
        );

        $this->assertSame( 29.0, WCCT_GA4_Data::items_value( $items ) );
    }

    public function test_items_value_avoids_float_noise() {
        $this->assertSame( 0.3, WCCT_GA4_Data::items_value( array( array( 'price' => 0.1, 'quantity' => 1 ), array( 'price' => 0.2, 'quantity' => 1 ) ) ) );
    }

    public function test_event_params() {
        $items  = array( array( 'item_id' => '1', 'price' => 10.0, 'quantity' => 2 ) );
        $params = WCCT_GA4_Data::event_params( 'G-TEST1234', 'EUR', $items, 2, array( 'SUMMER', ' ', 'SUMMER', 'VIP' ) );

        $this->assertSame( 'G-TEST1234', $params['send_to'] );
        $this->assertSame( 'EUR', $params['currency'] );
        $this->assertSame( 20.0, $params['value'] );
        $this->assertSame( 'SUMMER,VIP', $params['coupon'] );
        $this->assertSame( $items, $params['items'] );
    }

    public function test_event_params_without_coupons_has_no_coupon_key() {
        $params = WCCT_GA4_Data::event_params( 'G-TEST1234', 'USD', array() );

        $this->assertArrayNotHasKey( 'coupon', $params );
        $this->assertSame( 0.0, $params['value'] );
    }

    public function test_event_params_value_uses_line_totals_when_given() {
        // One line of 10.00 for 3: the unit price rounds to 3.33, which would give 9.99.
        $items  = array( array( 'item_id' => '1', 'price' => 3.33, 'quantity' => 3 ) );
        $params = WCCT_GA4_Data::event_params( 'G-TEST1234', 'USD', $items, 2, array(), '10.00' );

        $this->assertSame( 10.0, $params['value'] );
    }

    public function test_purchase_value_uses_line_totals_when_given() {
        $items  = array( array( 'item_id' => '1', 'price' => 3.33, 'quantity' => 3 ) );
        $params = WCCT_GA4_Data::purchase_params( 'G-TEST1234', '1001', 'USD', $items, 0, 0, array(), 2, 10 );

        $this->assertSame( 10.0, $params['value'] );
    }

    public function test_purchase_value_excludes_tax_and_shipping() {
        // Two items at 8.00 after discount, 3.20 tax, 5.00 shipping: order total 24.20.
        $items  = array( array( 'item_id' => '1', 'price' => 8.0, 'quantity' => 2 ) );
        $params = WCCT_GA4_Data::purchase_params( 'G-TEST1234', '1001', 'USD', $items, '3.20', '5.00', array( 'SAVE20' ) );

        $this->assertSame( 16.0, $params['value'] );
        $this->assertSame( 3.2, $params['tax'] );
        $this->assertSame( 5.0, $params['shipping'] );
        $this->assertSame( '1001', $params['transaction_id'] );
        $this->assertSame( 'SAVE20', $params['coupon'] );
        $this->assertSame( 'G-TEST1234', $params['send_to'] );
    }

    public function test_transaction_id_uses_order_number() {
        $this->assertSame( 'WC-1001', WCCT_GA4_Data::transaction_id( 'WC-1001', 55 ) );
    }

    /**
     * @dataProvider empty_order_numbers
     */
    public function test_transaction_id_is_never_empty( $order_number ) {
        $this->assertSame( '55', WCCT_GA4_Data::transaction_id( $order_number, 55 ) );
    }

    public function empty_order_numbers() {
        return array(
            'empty string' => array( '' ),
            'spaces'       => array( '   ' ),
            'null'         => array( null ),
            'array'        => array( array() ),
        );
    }

    /**
     * @dataProvider order_statuses
     */
    public function test_trackable_statuses( $status, $expected ) {
        $this->assertSame( $expected, WCCT_GA4_Data::is_trackable_status( $status ) );
    }

    public function order_statuses() {
        return array(
            'processing' => array( 'processing', true ),
            'completed'  => array( 'completed', true ),
            'on-hold'    => array( 'on-hold', true ),
            'pending'    => array( 'pending', true ),
            'failed'     => array( 'failed', false ),
            'cancelled'  => array( 'cancelled', false ),
        );
    }
}
