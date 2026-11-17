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

    public function test_a_gift_the_shopper_picks_is_sent_at_its_cart_price() {
        $queued = $this->result()['steps']['picked_gift'];

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
    }

    public function test_a_gift_add_that_throws_does_not_drop_the_shoppers_add_or_later_ones() {
        $steps = $this->result()['steps'];

        $this->assertCount( 1, $steps['after_throw'] );
        $this->assertMatchesRegularExpression( '/"content_ids": \[\s*"2005"\s*\],\s*"content_type": "product",\s*"value": "25.00"/', $steps['after_throw'][0]['scripts']['facebook'] );
        $this->assertCount( 1, $steps['more_beanies'] );
    }

    public function test_old_line_totals_fall_back_to_the_shop_price() {
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

        // Product, quantity, line subtotal; the job's result comes back as JSON, so 54.0 reads as 54.
        $this->assertEquals(
            array(
                array( 2001, 5, 90.0 ),
                array( 2005, 4, 0.0 ),
                array( 2001, 3, 0.0 ),
                array( 2005, 1, 25.0 ),
                array( 2003, 1, 42.0 ),
                array( 2005, 1, 25.0 ),
                array( 2005, 1, 25.0 ),
                array( 2001, 1, 18.0 ),
            ),
            $value['lines']
        );
        $this->assertSame( array( 2001, 2005, 2003 ), $value['cart_id'] );
    }
}
