<?php
// phpcs:disable

use PHPUnit\Framework\TestCase;

/**
 * Purchase from woocommerce_thankyou on thank-you pages that aren't WooCommerce's order received page
 */
class ThankyouFallbackTest extends TestCase {

    private function run_job( $order_id, $user_id, array $state = array() ) {
        $result = WCCT_Test_WP::run(
            array_merge(
                array(
                    'type'     => 'file',
                    'file'     => dirname( __DIR__ ) . '/jobs/thankyou-fallback.php',
                    'rollback' => true,
                    'order_id' => $order_id,
                    'user_id'  => $user_id,
                    'options'  => array( 'wcct_settings' => WCCT_Test_Settings::lite_all() ),
                ),
                $state
            )
        );

        $this->assertNull( $result['error'] );
        $this->assertFalse( $result['value']['order_received'] );

        return $result['value'];
    }

    public function test_guest_order_sends_one_purchase_per_platform() {
        $value = $this->run_job( 3001, 0 );

        $this->assertSame( 1, substr_count( $value['scripts']['facebook'], "fbq('track', 'Purchase'" ) );
        $this->assertSame( 1, substr_count( $value['scripts']['adwords'], "gtag('event', 'conversion'" ) );
        $this->assertSame( 1, substr_count( $value['scripts']['twitter'], "twq('track', 'Purchase'" ) );
        $this->assertSame( 1, substr_count( $value['scripts']['ga4'], '[["purchase",' ) );
        $this->assertSame( 1, substr_count( $value['markup']['custom'], "ctCustom('purchase'" ) );
    }

    public function test_registered_customer_order_needs_that_customer() {
        $value = $this->run_job( 3002, 0 );

        $this->assertArrayNotHasKey( 'facebook', $value['scripts'] );
        $this->assertArrayNotHasKey( 'custom', $value['markup'] );
        $this->assertArrayNotHasKey( 'ga4', $value['scripts'] );

        $value = $this->run_job( 3002, 501 );

        $this->assertSame( 1, substr_count( $value['scripts']['facebook'], "fbq('track', 'Purchase'" ) );
    }

    public function test_sent_or_failed_orders_send_nothing() {
        foreach ( array( array( 'sent' => array( 'facebook', 'adwords', 'twitter', 'ga4', 'custom' ) ), array( 'status' => 'failed' ) ) as $state ) {
            $value = $this->run_job( 3001, 0, $state );

            $this->assertArrayNotHasKey( 'facebook', $value['scripts'] );
            $this->assertArrayNotHasKey( 'adwords', $value['scripts'] );
            $this->assertArrayNotHasKey( 'twitter', $value['scripts'] );
            $this->assertArrayNotHasKey( 'custom', $value['markup'] );
            $this->assertStringNotContainsString( '"purchase"', isset( $value['scripts']['ga4'] ) ? $value['scripts']['ga4'] : '' );
        }
    }
}
