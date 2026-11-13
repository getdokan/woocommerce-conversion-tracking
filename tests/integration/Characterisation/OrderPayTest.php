<?php
// phpcs:disable

use PHPUnit\Framework\TestCase;

/**
 * Start checkout is not sent on the order-pay page (#85)
 */
class OrderPayTest extends TestCase {

    public function test_the_order_pay_page_sends_no_start_checkout() {
        $result = WCCT_Test_WP::run(
            array(
                'type'          => 'request',
                'rollback'      => true,
                'request'       => array( 'path' => '/checkout/order-pay/3001/?pay_for_order=true&key=wc_order_ctguest0001' ),
                'cart'          => WCCT_Test_Pages::CART,
                'options'       => array( 'wcct_settings' => WCCT_Test_Settings::lite_all() ),
                'before'        => dirname( __DIR__ ) . '/jobs/order-pending-before.php',
                'pending_order' => 3001,
            )
        );

        $this->assertNull( $result['error'] );
        // The pay form is shown, so this is the page a shopper pays from.
        $this->assertStringContainsString( 'id="order_review"', $result['html'] );

        $js = implode( '', $result['queued_js'] );

        foreach ( $result['scripts'] as $script ) {
            $js .= isset( $script['extra']['after'] ) && is_array( $script['extra']['after'] ) ? implode( '', array_filter( $script['extra']['after'], 'is_string' ) ) : '';
        }

        $this->assertStringContainsString( 'G-CTTEST1234', $result['html'], 'the tags are on the page' );
        $this->assertStringNotContainsString( 'begin_checkout', $js );
        $this->assertStringNotContainsString( 'InitiateCheckout', $js );
    }
}
