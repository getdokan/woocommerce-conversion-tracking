<?php

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Events\Event;

/**
 * Tests for the Event value object
 */
class EventTest extends TestCase {

    public function test_value_fields_are_normalised_floats() {
        $event = new Event( Event::PURCHASE, array( 'value' => '50.00', 'tax' => '4.5', 'shipping' => 5, 'currency' => ' eur ' ) );

        $this->assertSame( 50.0, $event->get_value() );
        $this->assertSame( 4.5, $event->get_tax() );
        $this->assertSame( 5.0, $event->get_shipping() );
        $this->assertSame( 'EUR', $event->get_currency() );
    }

    public function test_value_fields_default_to_zero_and_no_currency() {
        $event = new Event( Event::SIGN_UP );

        $this->assertSame( 0.0, $event->get_value() );
        $this->assertSame( 0.0, $event->get_tax() );
        $this->assertSame( 0.0, $event->get_shipping() );
        $this->assertSame( '', $event->get_currency() );
    }

    public function test_values_are_rounded_to_the_price_decimals() {
        $this->assertSame( 10.13, ( new Event( Event::ADD_TO_CART, array( 'value' => 10.125001 ) ) )->get_value() );
        $this->assertSame( 1000.0, ( new Event( Event::PURCHASE, array( 'value' => '999.6', 'decimals' => 0 ) ) )->get_value() );
        $this->assertSame( 1.235, ( new Event( Event::PURCHASE, array( 'value' => '1.2346', 'decimals' => 3 ) ) )->get_value() );
    }

    /**
     * @dataProvider bad_amounts
     */
    public function test_bad_amounts_become_zero( $amount ) {
        $this->assertSame( 0.0, Event::amount( $amount ) );
    }

    public function bad_amounts() {
        return array(
            'text'     => array( 'abc' ),
            'empty'    => array( '' ),
            'null'     => array( null ),
            'array'    => array( array( 5 ) ),
            'negative' => array( -3 ),
            'infinite' => array( INF ),
            'nan'      => array( NAN ),
            'object'   => array( new stdClass() ),
        );
    }

    /**
     * @dataProvider bad_currencies
     */
    public function test_bad_currencies_are_dropped( $currency ) {
        $this->assertSame( '', Event::currency_code( $currency ) );
    }

    public function bad_currencies() {
        return array(
            'too long' => array( 'EURO' ),
            'digits'   => array( '123' ),
            'markup'   => array( '<b>' ),
            'array'    => array( array( 'USD' ) ),
        );
    }

    public function test_purchase_event_id_comes_from_the_order() {
        $event = new Event( Event::PURCHASE, array( 'order_id' => 123 ) );

        $this->assertSame( 'wcct_123_purchase', $event->get_event_id() );
        $this->assertSame( 123, $event->get_order_id() );
    }

    public function test_order_id_is_read_from_the_order_object() {
        $order = new class() {
            public function get_id() {
                return 77;
            }
        };

        $event = new Event( Event::PURCHASE, array( 'order' => $order ) );

        $this->assertSame( 77, $event->get_order_id() );
        $this->assertSame( 'wcct_77_purchase', $event->get_event_id() );
        $this->assertSame( $order, $event->get_order() );
    }

    public function test_repeatable_events_get_a_unique_id_each() {
        $first  = new Event( Event::ADD_TO_CART );
        $second = new Event( Event::ADD_TO_CART );

        $this->assertMatchesRegularExpression( '/^wcct_[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $first->get_event_id() );
        $this->assertNotSame( $first->get_event_id(), $second->get_event_id() );
    }

    public function test_purchase_without_an_order_gets_a_random_id() {
        $this->assertStringStartsNotWith( 'wcct_0_', ( new Event( Event::PURCHASE ) )->get_event_id() );
    }

    public function test_a_given_event_id_is_kept() {
        $this->assertSame( 'abc', ( new Event( Event::PURCHASE, array( 'order_id' => 5, 'event_id' => 'abc' ) ) )->get_event_id() );
    }

    public function test_product_ids_are_positive_unique_integers() {
        $event = new Event( Event::VIEW_CATEGORY, array( 'product_ids' => array( '3', 3, 0, -1, 'x', 7 ) ) );

        $this->assertSame( array( 3, 7 ), $event->get_product_ids() );
    }

    public function test_extra_data_and_user_data_slot() {
        $event = new Event( Event::SEARCH, array( 'data' => array( 'search_string' => 'shoes' ) ) );

        $this->assertSame( 'shoes', $event->get( 'search_string' ) );
        $this->assertSame( 'none', $event->get( 'missing', 'none' ) );
        $this->assertSame( array(), $event->get_user_data() );
    }

    public function test_bad_args_do_not_break_the_event() {
        $event = new Event( Event::PURCHASE, array( 'order' => 'not an order', 'order_id' => 'x', 'data' => 'x', 'product_ids' => 'x', 'decimals' => 'x' ) );

        $this->assertNull( $event->get_order() );
        $this->assertSame( 0, $event->get_order_id() );
        $this->assertSame( array(), $event->get_product_ids() );
    }
}
