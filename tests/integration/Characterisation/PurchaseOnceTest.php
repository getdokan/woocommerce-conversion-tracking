<?php
// phpcs:disable

use PHPUnit\Framework\TestCase;

/**
 * Purchase is sent once per order and platform, never for failed or cancelled orders (#83)
 */
class PurchaseOnceTest extends TestCase {

    /**
     * What each platform's Purchase looks like on the page
     *
     * @var string[]
     */
    const MARKERS = array(
        'facebook' => "fbq('track', 'Purchase'",
        'adwords'  => "})('event', 'conversion'",
        'ga4'      => '"purchase",{"send_to":"G-CTTEST1234"',
        'twitter'  => "twq('event', \"tw-ct0x1-ctpur\"",
        'custom'   => "ctCustom('purchase'",
    );

    protected function setUp(): void {
        parent::setUp();

        if ( count( WCCT_Test_WP::$env['plugins'] ) > 2 ) {
            $this->markTestSkipped( 'Runs on Lite alone.' );
        }
    }

    /**
     * Platforms whose Purchase is on the thank-you page of order 3001
     *
     * @param array $state   Order state, see jobs/purchase-order-state.php.
     * @param array $options Options for the request.
     *
     * @return string[]
     */
    private function purchases( array $state = array(), array $options = array() ) {
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

        return array_keys(
            array_filter(
                self::MARKERS,
                function ( $marker ) use ( $result ) {
                    return false !== strpos( $result['html'], $marker );
                }
            )
        );
    }

    public function test_every_platform_sends_the_purchase_with_its_confirmation() {
        $result = WCCT_Test_WP::run(
            array(
                'type'     => 'request',
                'rollback' => true,
                'request'  => array( 'path' => '/checkout/order-received/3001/?key=wc_order_ctguest0001' ),
                'options'  => array( 'wcct_settings' => WCCT_Test_Settings::lite_all() ),
            )
        );

        $this->assertNull( $result['error'] );

        foreach ( array( 'facebook', 'adwords', 'twitter', 'custom' ) as $platform ) {
            $this->assertStringContainsString( sprintf( '"%s", 3001, "wc_order_ctguest0001")', $platform ), $result['html'], $platform );
        }

        $this->assertStringContainsString( 'wcct_ga4_purchase_sent', $result['html'] );
    }

    public function test_paid_order_sends_on_every_platform() {
        $this->assertSame( array_keys( self::MARKERS ), $this->purchases() );
    }

    public function test_sent_platforms_do_not_send_again() {
        $this->assertSame( array(), $this->purchases( array( 'sent' => array_keys( self::MARKERS ) ) ) );
        $this->assertSame( array( 'adwords', 'custom' ), $this->purchases( array( 'sent' => array( 'facebook', 'ga4', 'twitter' ) ) ) );
    }

    public function test_failed_and_cancelled_orders_send_nothing() {
        $this->assertSame( array(), $this->purchases( array( 'status' => 'failed' ) ) );
        $this->assertSame( array(), $this->purchases( array( 'status' => 'cancelled' ) ) );
    }

    public function test_pending_and_on_hold_orders_send() {
        $this->assertSame( array_keys( self::MARKERS ), $this->purchases( array( 'status' => 'pending' ) ) );
        $this->assertSame( array_keys( self::MARKERS ), $this->purchases( array( 'status' => 'on-hold' ) ) );
    }

    public function test_orders_created_before_3_0_first_ran_count_as_sent() {
        $this->assertSame( array(), $this->purchases( array(), array( 'wcct_count_once_since' => time() + 86400 ) ) );
    }

    public function test_confirmation_with_the_order_key_sets_that_platform_flag_only() {
        $result = $this->confirm( 'twitter', 'wc_order_ctguest0001' );

        $this->assertStringContainsString( '"success":true', $result['response'] );
        $this->assertSame( array( 'twitter' ), array_keys( array_filter( $result['flags'] ) ) );
    }

    public function test_confirmation_with_a_wrong_key_or_platform_sets_nothing() {
        $this->assertSame( array(), array_keys( array_filter( $this->confirm( 'twitter', 'wc_order_wrong' )['flags'] ) ) );
        $this->assertSame( array(), array_keys( array_filter( $this->confirm( 'bing_ads', 'wc_order_ctguest0001' )['flags'] ) ) );
    }

    private function confirm( $platform, $key ) {
        $result = WCCT_Test_WP::run(
            array(
                'type'        => 'file',
                'file'        => dirname( __DIR__ ) . '/jobs/purchase-confirm.php',
                'rollback'    => true,
                'options'     => array( 'wcct_settings' => WCCT_Test_Settings::lite_all() ),
                'before_args' => array(
                    'platform' => $platform,
                    'key'      => $key,
                ),
            )
        );

        $this->assertNull( $result['error'] );

        return $result['value'];
    }
}
