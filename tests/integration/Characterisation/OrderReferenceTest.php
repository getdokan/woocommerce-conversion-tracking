<?php
// phpcs:disable

use PHPUnit\Framework\TestCase;

/**
 * The order reference Google Ads, GA4 and X print with the purchase of order 3001: the order number the merchant sees, the same on each (#139)
 */
class OrderReferenceTest extends TestCase {

    /**
     * Where each platform prints the reference
     *
     * @var string[]
     */
    const PATTERNS = array(
        'adwords' => '~\}\)\(\'event\', \'conversion\', \{[^}]*?"transaction_id": (?<reference>"[^"]*"|[0-9]+),~',
        'ga4'     => '~"purchase",\{"send_to":"G-CTTEST1234".*?"transaction_id":(?<reference>"[^"]*"|[0-9]+),~',
        'twitter' => '~twq\(\'event\', "tw-ct0x1-ctpur", \{[^}]*?"conversion_id":(?<reference>"[^"]*"|[0-9]+),~',
    );

    protected function setUp(): void {
        parent::setUp();

        if ( count( WCCT_Test_WP::$env['plugins'] ) > 2 ) {
            $this->markTestSkipped( 'Runs on Lite alone.' );
        }
    }

    /**
     * Order numbers: state for jobs/purchase-order-state.php, then the reference every platform prints
     *
     * @return array
     */
    public function numbers() {
        return array(
            'no custom order number plugin' => array( array(), '"3001"' ),
            'a custom order number'         => array( array( 'order_number' => 'WC-2025-0042' ), '"WC-2025-0042"' ),
            'a sequential number'           => array( array( 'order_number' => '57' ), '"57"' ),
            'a plugin that empties it'      => array( array( 'order_number' => '' ), '"3001"' ),
        );
    }

    /**
     * @dataProvider numbers
     */
    public function test_ads_ga4_and_x_print_the_same_order_reference( array $state, $expected ) {
        $html = $this->page( $state );

        foreach ( self::PATTERNS as $platform => $pattern ) {
            $this->assertSame( 1, preg_match( $pattern, $html, $match ), "$platform reference on the page" );
            $this->assertSame( $expected, $match['reference'], $platform );
        }
    }

    public function test_the_reload_guard_still_keys_on_the_order_and_skips_a_sent_ads_conversion() {
        $html = $this->page( array( 'order_number' => 'WC-2025-0042', 'sent' => array( 'adwords' ) ) );

        $this->assertSame( 0, preg_match( self::PATTERNS['adwords'], $html ), 'No second Ads conversion' );
        $this->assertSame( 1, preg_match( self::PATTERNS['ga4'], $html ) );

        // The check-in of a first view names the internal order ID, not the number.
        $this->assertStringContainsString( '"adwords", 3001,', $this->page( array( 'order_number' => 'WC-2025-0042' ) ) );
    }

    /**
     * The thank-you page of order 3001 in the given state
     *
     * @param array $state Order state.
     *
     * @return string
     */
    private function page( array $state ) {
        $result = WCCT_Test_WP::run(
            array(
                'type'        => 'request',
                'rollback'    => true,
                'request'     => array( 'path' => '/checkout/order-received/3001/?key=wc_order_ctguest0001' ),
                'options'     => array( 'wcct_settings' => WCCT_Test_Settings::lite_all() ),
                'before'      => dirname( __DIR__ ) . '/jobs/purchase-order-state.php',
                'before_args' => $state,
            )
        );

        $this->assertNull( $result['error'] );

        return $result['html'];
    }
}
