<?php
// phpcs:disable

use PHPUnit\Framework\TestCase;

/**
 * The Purchase value each platform prints, per value mode, for orders with fees and tax-inclusive prices (#95)
 *
 * Order 3001: product lines 73.00 after the coupon, shipping 5.00.
 */
class OrderValueOutputTest extends TestCase {

    /**
     * Where each platform prints the Purchase value and currency
     *
     * @var string[]
     */
    const PATTERNS = array(
        'facebook' => '~wcfbq\("111111111111111", "Purchase", \{[^}]*?"value": "(?<value>[0-9.]+)",\s*"currency": "(?<currency>[A-Z]+)"~',
        'adwords'  => '~\}\)\(\'event\', \'conversion\', \{[^}]*?"value": "(?<value>[0-9.]+)",\s*"currency": "(?<currency>[A-Z]+)"~',
        'twitter'  => '~twq\(\'event\', "tw-ct0x1-ctpur", \{"value":(?<value>[0-9.]+),"currency":"(?<currency>[A-Z]+)"~',
        'ga4'      => '~"purchase",\{"send_to":"G-CTTEST1234","currency":"(?<currency>[A-Z]+)","value":(?<value>[0-9.]+)~',
    );

    protected function setUp(): void {
        parent::setUp();

        if ( count( WCCT_Test_WP::$env['plugins'] ) > 2 ) {
            $this->markTestSkipped( 'Runs on Lite alone; Pro has its own.' );
        }
    }

    /**
     * Orders: state for jobs/purchase-order-state.php, then the value for items, shipping and all
     *
     * @return array
     */
    public function orders() {
        return array(
            'a 5.00 fee'                              => array( array( 'fees' => array( array( 'Gift wrap', '5.00' ) ), 'totals' => array( 'cart_tax' => 0, 'shipping_tax' => 0, 'total' => '83.00' ) ), 78.0, 83.0, 83.0 ),
            'a -10.00 fee'                            => array( array( 'fees' => array( array( 'Loyalty discount', '-10.00' ) ), 'totals' => array( 'cart_tax' => 0, 'shipping_tax' => 0, 'total' => '68.00' ) ), 63.0, 68.0, 68.0 ),
            // Prices entered with 20% tax: lines keep 73.00 without tax, 14.60 tax; the fee 5.00 + 1.00 tax; shipping 5.00 + 1.00 tax.
            'tax-inclusive prices and a taxed fee'    => array( array( 'fees' => array( array( 'Gift wrap', '5.00', '1.00' ) ), 'totals' => array( 'prices_include_tax' => true, 'cart_tax' => '15.60', 'shipping_tax' => '1.00', 'total' => '99.60' ) ), 78.0, 83.0, 99.6 ),
        );
    }

    /**
     * @dataProvider orders
     */
    public function test_each_platform_prints_the_value_of_its_mode_and_ga4_the_line_total( array $state, $items, $shipping, $all ) {
        foreach ( array( 'items' => $items, 'shipping' => $shipping, 'all' => $all ) as $mode => $expected ) {
            $values = $this->values( array_merge( $state, array( 'value_mode' => $mode ) ) );

            $this->assertSame( $expected, $values['facebook'][0], "Meta, $mode" );
            $this->assertSame( $expected, $values['adwords'][0], "Ads, $mode" );
            $this->assertSame( $expected, $values['twitter'][0], "X, $mode" );
            $this->assertSame( 73.0, $values['ga4'][0], "GA4 ignores the mode and fees, $mode" );
        }
    }

    public function test_the_upgraded_store_keeps_the_order_total_and_ga4_the_line_total() {
        $values = $this->values( $this->orders()['a 5.00 fee'][0] );

        $this->assertSame( array( 83.0, 83.0, 83.0, 73.0 ), array( $values['facebook'][0], $values['adwords'][0], $values['twitter'][0], $values['ga4'][0] ) );
    }

    public function test_a_new_install_sends_items_only_except_ga4() {
        $values = $this->values( $this->orders()['a 5.00 fee'][0], array( 'wcct_install_type' => 'new' ) );

        $this->assertSame( array( 78.0, 78.0, 78.0, 73.0 ), array( $values['facebook'][0], $values['adwords'][0], $values['twitter'][0], $values['ga4'][0] ) );
    }

    public function test_every_platform_sends_the_order_currency() {
        $values = $this->values( array( 'currency' => 'EUR' ), array( 'woocommerce_currency' => 'USD' ) );

        foreach ( self::PATTERNS as $platform => $pattern ) {
            $this->assertSame( 'EUR', $values[ $platform ][1], $platform );
        }
    }

    /**
     * Each platform's printed Purchase value and currency for order 3001 in the given state
     *
     * @param array $state   Order state.
     * @param array $options Options for the request.
     *
     * @return array Platform => [value, currency].
     */
    private function values( array $state, array $options = array() ) {
        $result = WCCT_Test_WP::run(
            array(
                'type'        => 'request',
                'rollback'    => true,
                'request'     => array( 'path' => '/checkout/order-received/3001/?key=wc_order_ctguest0001' ),
                'options'     => array_merge( $options, array( 'wcct_settings' => WCCT_Test_Settings::lite_all() ) ),
                'before'      => dirname( __DIR__ ) . '/jobs/purchase-order-state.php',
                'before_args' => $state,
            )
        );

        $this->assertNull( $result['error'] );

        $values = array();

        foreach ( self::PATTERNS as $platform => $pattern ) {
            $this->assertSame( 1, preg_match( $pattern, $result['html'], $match ), "$platform Purchase value on the page" );
            $values[ $platform ] = array( (float) $match['value'], $match['currency'] );
        }

        return $values;
    }
}
