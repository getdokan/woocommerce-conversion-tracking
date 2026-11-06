<?php

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Events\Event;
use StorePulse\ConversionTracking\Events\EventFactory;
use StorePulse\ConversionTracking\Events\ValueMode;
use StorePulse\ConversionTracking\Install;

/**
 * Value maths over the three value modes and GA4's item total, against orders shaped the way WooCommerce stores them
 */
class OrderValueTest extends TestCase {

    const MODE_PLATFORMS = array( 'facebook', 'adwords', 'twitter', 'bing_ads' );

    protected function tearDown(): void {
        unset( $GLOBALS['wcct_test_options'], $GLOBALS['wcct_test_filters'], $GLOBALS['wcct_test_decimals'] );
    }

    /**
     * Fixture orders: totals and product lines, then the value for items, shipping, all and GA4
     *
     * @return array
     */
    public function orders() {
        // WooCommerce keeps line, fee and shipping totals without tax, also when prices are entered with tax; every tax is in total_tax.
        return array(
            // 2 x 25.00 with 10.00 off, 20% tax added: lines 40.00 + tax 8.00, shipping 10.00 + tax 2.00.
            'tax-exclusive prices'               => array( array( 'total' => '60.00', 'tax' => '10.00', 'shipping' => '10.00' ), array( '40.00' ), 40.0, 50.0, 60.0, 40.0 ),
            // Same, plus a 5.00 gift-wrap fee with 1.00 tax: the fee counts as items, but not for GA4.
            'tax-exclusive prices with a fee'    => array( array( 'total' => '66.00', 'tax' => '11.00', 'shipping' => '10.00' ), array( '40.00' ), 45.0, 55.0, 66.0, 40.0 ),
            // 2 x 30.00 entered with 20% tax: lines stored as 50.00 + tax 10.00, shipping 10.00 + tax 2.00.
            'tax-inclusive prices'               => array( array( 'total' => '72.00', 'tax' => '12.00', 'shipping' => '10.00' ), array( '25.00', '25.00' ), 50.0, 60.0, 72.0, 50.0 ),
            // Same, plus a 5.00 fee with 1.00 tax.
            'tax-inclusive prices with a fee'    => array( array( 'total' => '78.00', 'tax' => '13.00', 'shipping' => '10.00' ), array( '25.00', '25.00' ), 55.0, 65.0, 78.0, 50.0 ),
            // No tax: lines 73.00 after a 5.00 coupon, shipping 5.00 (the characterisation store's order 3001).
            'no tax'                             => array( array( 'total' => '78.00', 'tax' => '0', 'shipping' => '5.00' ), array( '33.69', '39.31' ), 73.0, 78.0, 78.0, 73.0 ),
            // A -10.00 fee used as a discount lowers the items value; GA4 keeps the line total.
            'negative fee'                       => array( array( 'total' => '45.00', 'tax' => '0', 'shipping' => '5.00' ), array( '50.00' ), 40.0, 45.0, 45.0, 50.0 ),
            // Amounts are rounded first (tax 5.56, shipping 4.17), so the three parts still add up to the total.
            'tax-inclusive prices with rounding' => array( array( 'total' => '33.33', 'tax' => '5.555', 'shipping' => '4.165' ), array( '23.604' ), 23.6, 27.77, 33.33, 23.6 ),
        );
    }

    private function purchase( array $totals, array $lines ) {
        $items = array();

        foreach ( $lines as $index => $total ) {
            $items[] = new WC_Order_Item_Product( 100 + $index, $total );
        }

        return EventFactory::purchase( new WCCT_Test_Fake_Order( array_merge( $totals, array( 'items' => $items ) ) ) );
    }

    /**
     * @dataProvider orders
     */
    public function test_value_per_mode_on_meta_ads_x_and_microsoft( array $totals, array $lines, $items, $shipping, $all ) {
        $event = $this->purchase( $totals, $lines );

        foreach ( self::MODE_PLATFORMS as $platform ) {
            $this->assertSame( $items, $event->get_value_for( ValueMode::ITEMS, $platform ), "$platform items" );
            $this->assertSame( $shipping, $event->get_value_for( ValueMode::SHIPPING, $platform ), "$platform shipping" );
            $this->assertSame( $all, $event->get_value_for( ValueMode::ALL, $platform ), "$platform all" );
        }
    }

    /**
     * @dataProvider orders
     */
    public function test_ga4_sends_the_product_line_total_without_fees( array $totals, array $lines, $items, $shipping, $all, $ga4 ) {
        $GLOBALS['wcct_test_options']['wcct_install_type'] = Install::UPGRADE;

        $this->assertSame( $ga4, $this->purchase( $totals, $lines )->get_items_total_value( 'ga4' ) );
    }

    /**
     * @dataProvider orders
     */
    public function test_new_install_sends_items_only( array $totals, array $lines, $items ) {
        $event = $this->purchase( $totals, $lines );

        foreach ( self::MODE_PLATFORMS as $platform ) {
            $this->assertSame( $items, $event->get_value_for( ValueMode::for_platform( $platform ), $platform ), $platform );
        }
    }

    /**
     * @dataProvider orders
     */
    public function test_upgrader_keeps_the_order_total( array $totals, array $lines, $items, $shipping, $all ) {
        $GLOBALS['wcct_test_options']['wcct_install_type'] = Install::UPGRADE;

        $event = $this->purchase( $totals, $lines );

        foreach ( self::MODE_PLATFORMS as $platform ) {
            $this->assertSame( $all, $event->get_value_for( ValueMode::for_platform( $platform ), $platform ), $platform );
        }
    }

    public function test_value_is_never_negative() {
        $this->assertSame( 0.0, ValueMode::value( '5.00', '1.00', '10.00', ValueMode::ITEMS ) );
    }

    public function test_zero_decimal_currency() {
        $GLOBALS['wcct_test_decimals'] = 0;

        $event = $this->purchase( array( 'total' => '1100', 'tax' => '100', 'shipping' => '300', 'currency' => 'JPY' ), array( '700' ) );

        $this->assertSame( 700.0, $event->get_value_for( ValueMode::ITEMS ) );
        $this->assertSame( 700.0, $event->get_items_total_value( 'ga4' ) );
        $this->assertSame( 'JPY', $event->get_currency() );
    }

    public function test_purchase_uses_the_order_currency() {
        $GLOBALS['wcct_test_options']['woocommerce_currency'] = 'USD';

        $this->assertSame( 'EUR', EventFactory::purchase( new WCCT_Test_Fake_Order( array( 'currency' => 'eur' ) ) )->get_currency() );
    }

    public function test_unknown_mode_falls_back_to_items() {
        $event = new Event( Event::PURCHASE, array( 'value' => 60, 'tax' => 10, 'shipping' => 10 ) );

        $this->assertSame( 40.0, $event->get_value_for( 'everything' ) );
    }

    public function test_value_filter_gets_value_mode_event_order_and_platform() {
        $order = new WCCT_Test_Fake_Order( array( 'id' => 7, 'total' => '60.00', 'tax' => '10.00', 'shipping' => '10.00' ) );
        $event = EventFactory::purchase( $order );
        $seen  = array();

        $GLOBALS['wcct_test_filters']['wcct_event_value'] = function ( $value, $mode, $filtered_event, $filtered_order, $platform ) use ( &$seen ) {
            $seen = array( $value, $mode, $filtered_event, $filtered_order, $platform );

            return $value + 0.555;
        };

        $this->assertSame( 50.56, $event->get_value_for( ValueMode::SHIPPING, 'twitter' ) );
        $this->assertSame( array( 50.0, 'shipping', $event, $order, 'twitter' ), $seen );
    }

    public function test_ga4_value_goes_through_the_filter_as_items() {
        $event = $this->purchase( array( 'total' => '66.00', 'tax' => '11.00', 'shipping' => '10.00' ), array( '40.00' ) );
        $seen  = array();

        $GLOBALS['wcct_test_filters']['wcct_event_value'] = function ( $value, $mode, $filtered_event, $order, $platform ) use ( &$seen ) {
            $seen = array( $value, $mode, $platform );

            return $value * 2;
        };

        $this->assertSame( 80.0, $event->get_items_total_value( 'ga4' ) );
        $this->assertSame( array( 40.0, 'items', 'ga4' ), $seen );
    }

    public function test_bad_filter_value_sends_zero() {
        $GLOBALS['wcct_test_filters']['wcct_event_value'] = function () {
            return 'n/a';
        };

        $this->assertSame( 0.0, ( new Event( Event::PURCHASE, array( 'value' => 60 ) ) )->get_value_for( ValueMode::ALL ) );
    }
}
