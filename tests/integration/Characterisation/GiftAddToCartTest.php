<?php
// phpcs:disable

use PHPUnit\Framework\TestCase;

/**
 * Items other plugins add send no AddToCart, and an add is priced from its cart line (#134)
 */
class GiftAddToCartTest extends TestCase {

    private static $result;

    private function result() {
        if ( null === self::$result ) {
            self::$result = WCCT_Test_WP::run(
                array(
                    'type'     => 'file',
                    'file'     => dirname( __DIR__ ) . '/jobs/gift-add-to-cart.php',
                    'rollback' => true,
                    'options'  => array( 'wcct_settings' => WCCT_Test_Settings::lite_all() ),
                )
            );
        }

        $this->assertNull( self::$result['error'] );

        return self::$result['value'];
    }

    public function test_the_shoppers_add_sends_one_add_to_cart_and_the_gifts_none() {
        $queued = $this->result()['steps']['beanie'];

        $this->assertCount( 1, $queued );
        $this->assertMatchesRegularExpression( '/"content_ids": \[\s*"2001"\s*\],\s*"content_type": "product",\s*"value": "18.00"/', $queued[0]['scripts']['facebook'] );
        $this->assertStringContainsString( '"value":18,"items":[{"item_id":"2001"', $queued[0]['scripts']['ga4'] );
    }

    public function test_a_0_item_added_outside_an_add_to_cart_request_is_a_gift() {
        // Like a gift plugin adding on a page load, a coupon or a quantity change, or StoreGrowth's gift picker.
        $this->assertSame( array(), $this->result()['steps']['picked_gift'] );
        $this->assertSame( array(), $this->result()['steps']['gift_on_store_api_update'] );
    }

    public function test_a_free_product_the_shopper_adds_is_sent_at_its_cart_price() {
        $steps  = $this->result()['steps'];
        $queued = $steps['free_by_form'];

        $this->assertSame( array( 201, 207, 207 ), $this->result()['status'] );
        $this->assertCount( 1, $steps['free_by_store_api'] );
        $this->assertCount( 1, $steps['free_by_store_api_batch'] );
        $this->assertStringContainsString( '"value": "0.00"', $steps['free_by_store_api_batch'][0]['scripts']['facebook'] );
        $this->assertCount( 1, $queued );
        $this->assertMatchesRegularExpression( '/"content_ids": \[\s*"2005"\s*\],\s*"content_type": "product",\s*"value": "0.00"/', $queued[0]['scripts']['facebook'] );
        $this->assertStringContainsString( '"value":0,', $queued[0]['scripts']['ga4'] );
        $this->assertStringContainsString( '"price":0', $queued[0]['scripts']['ga4'] );
    }

    public function test_plugins_can_flag_an_item_or_filter_it_out() {
        $steps = $this->result()['steps'];

        $this->assertSame( array(), $steps['flagged'] );
        $this->assertSame( array(), $steps['filtered'] );
        $this->assertSame( array(), $steps['storegrowth_gift'] );
        $this->assertCount( 1, $steps['other_bogo_offer'] );
        $this->assertStringContainsString( '"value": "25.00"', $steps['other_bogo_offer'][0]['scripts']['facebook'] );
    }

    public function test_items_another_plugin_adds_with_a_price_join_the_shoppers_add_to_cart() {
        $queued = $this->result()['steps']['bundle'];

        $this->assertCount( 1, $queued );
        // The bundle's own line is $0; its items carry the price.
        $this->assertMatchesRegularExpression( '/"content_ids": \[\s*"2005",\s*"2001"\s*\],\s*"content_type": "product",\s*"value": "36.00"/', $queued[0]['scripts']['facebook'] );
        $this->assertStringContainsString( '"value":36,"items":[{"item_id":"2005"', $queued[0]['scripts']['ga4'] );
        $this->assertStringContainsString( '{"item_id":"2001","item_name":"Beanie"', $queued[0]['scripts']['ga4'] );
        $this->assertStringContainsString( '"price":18,"quantity":2', $queued[0]['scripts']['ga4'] );
    }

    public function test_a_line_removed_and_added_again_counts_once() {
        $steps = $this->result()['steps'];

        $this->assertCount( 1, $steps['separately'] );
        $this->assertMatchesRegularExpression( '/"content_ids": \[\s*"2003",\s*"2005"\s*\],\s*"content_type": "product",\s*"value": "67.00"/', $steps['separately'][0]['scripts']['facebook'] );

        $this->assertCount( 1, $steps['readd'] );
        $this->assertMatchesRegularExpression( '/"content_ids": \[\s*"2005"\s*\],\s*"content_type": "product",\s*"value": "25.00"/', $steps['readd'][0]['scripts']['facebook'] );
        $this->assertSame( 1, substr_count( $steps['readd'][0]['scripts']['ga4'], '"item_id"' ) );
    }

    public function test_an_item_added_by_a_late_hook_joins_the_shoppers_add_to_cart() {
        $queued = $this->result()['steps']['late_join'];

        $this->assertCount( 1, $queued );
        $this->assertMatchesRegularExpression( '/"content_ids": \[\s*"2005",\s*"2001"\s*\],\s*"content_type": "product",\s*"value": "43.00"/', $queued[0]['scripts']['facebook'] );
    }

    public function test_an_item_added_after_the_event_is_not_carried_into_the_next_add() {
        $steps = $this->result()['steps'];

        foreach ( array( 'late', 'late_again' ) as $name ) {
            $this->assertCount( 1, $steps[ $name ] );
            $this->assertMatchesRegularExpression( '/"content_ids": \[\s*"2005"\s*\],\s*"content_type": "product",\s*"value": "25.00"/', $steps[ $name ][0]['scripts']['facebook'] );
        }
    }

    public function test_a_gift_added_during_a_grouped_add_is_left_out() {
        $queued = $this->result()['steps']['grouped_gift'];

        $this->assertCount( 1, $queued );
        $this->assertMatchesRegularExpression( '/"content_ids": \[\s*"2003"\s*\],\s*"content_type": "product",\s*"value": "42.00"/', $queued[0]['scripts']['facebook'] );
    }

    public function test_a_discounted_bogo_item_joins_at_its_cart_price() {
        $queued = $this->result()['steps']['half_price_bogo'];

        $this->assertCount( 1, $queued );
        $this->assertMatchesRegularExpression( '/"content_ids": \[\s*"2003",\s*"2001"\s*\],\s*"content_type": "product",\s*"value": "51.00"/', $queued[0]['scripts']['facebook'] );
    }

    public function test_a_gift_add_that_throws_does_not_drop_the_shoppers_add_or_later_ones() {
        $steps = $this->result()['steps'];

        $this->assertCount( 1, $steps['after_throw'] );
        $this->assertMatchesRegularExpression( '/"content_ids": \[\s*"2005"\s*\],\s*"content_type": "product",\s*"value": "25.00"/', $steps['after_throw'][0]['scripts']['facebook'] );
        $this->assertCount( 1, $steps['more_beanies'] );
    }

    public function test_a_grouped_add_is_priced_from_the_cart_line_product() {
        $queued = $this->result()['steps']['grouped'];

        $this->assertCount( 1, $queued );
        $this->assertStringContainsString( '"value": "36.00"', $queued[0]['scripts']['facebook'] );
        $this->assertStringContainsString( '"price":18,', $queued[0]['scripts']['ga4'] );
    }

    public function test_more_of_a_line_in_the_cart_is_priced_for_the_quantity_added() {
        $queued = $this->result()['steps']['more_beanies'];

        $this->assertCount( 1, $queued );
        $this->assertStringContainsString( '"value": "36.00"', $queued[0]['scripts']['facebook'] );
    }

    public function test_the_cart_keeps_every_gift_line_and_lists_each_product_once() {
        $value = $this->result();
        $ids   = array_values( array_unique( array_column( $value['lines'], 0 ) ) );

        // Gift lines stay in the cart (two lines for the Beanie alone); InitiateCheckout lists each product once.
        $this->assertGreaterThan( count( $ids ), count( $value['lines'] ) );
        $this->assertSame( $ids, $value['cart_id'] );
        $this->assertSame( array( 2001, 2005, 2003 ), $value['cart_id'] );
    }
}
