<?php

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Events\Event;
use StorePulse\ConversionTracking\Events\EventFactory;
use StorePulse\ConversionTracking\Events\PurchaseConfirmation;
use StorePulse\ConversionTracking\Events\SentFlags;

/**
 * Tests for the Purchase code a platform renders: gated once per order, followed by the confirmation that writes the flag
 */
class PurchaseScriptTest extends TestCase {

    protected function tearDown(): void {
        unset( $GLOBALS['wcct_test_options'], $GLOBALS['wcct_test_filters'] );
    }

    private function purchase( array $props = array() ) {
        return EventFactory::purchase( new WCCT_Test_Fake_Order( array_merge( array( 'id' => 9, 'key' => 'wc_order_abc' ), $props ) ) );
    }

    public function test_purchase_is_followed_by_the_confirmation() {
        $code = ( new WCCT_Test_Fake_Integration( 'twitter' ) )->get_event_script( $this->purchase() );

        $this->assertStringContainsString( 'window.wcctPurchaseSent = window.wcctPurchaseSent ||', $code );
        $this->assertStringContainsString( 'twitter.purchase(9);', $code );
        $this->assertStringEndsWith( 'window.wcctPurchaseSent("https://shop.test/wp-admin/admin-ajax.php", "twitter", 9, "wc_order_abc")();', $code );
    }

    public function test_sent_order_renders_nothing() {
        $event = $this->purchase( array( 'meta' => array( SentFlags::meta_key( 'twitter' ) => 1 ) ) );

        $this->assertSame( '', ( new WCCT_Test_Fake_Integration( 'twitter' ) )->get_event_script( $event ) );
        $this->assertStringContainsString( 'facebook.purchase(9);', ( new WCCT_Test_Fake_Integration( 'facebook' ) )->get_event_script( $event ) );
    }

    public function test_failed_order_renders_nothing() {
        $this->assertSame( '', ( new WCCT_Test_Fake_Integration( 'twitter' ) )->get_event_script( $this->purchase( array( 'status' => 'failed' ) ) ) );
    }

    public function test_same_order_renders_once_per_request() {
        $platform = new WCCT_Test_Fake_Integration( 'twitter' );

        $this->assertNotSame( '', $platform->get_event_script( $this->purchase() ) );
        $this->assertSame( '', $platform->get_event_script( $this->purchase() ) );
    }

    public function test_purchase_without_an_order_renders_nothing() {
        $this->assertSame( '', ( new WCCT_Test_Fake_Integration( 'twitter' ) )->get_event_script( new Event( Event::PURCHASE, array( 'order_id' => 9 ) ) ) );
    }

    public function test_other_events_get_no_confirmation() {
        $code = ( new WCCT_Test_Fake_Integration( 'twitter' ) )->get_event_script( new Event( Event::VIEW_PRODUCT, array( 'product_ids' => array( 4 ) ) ) );

        $this->assertSame( 'twitter.view(4);', $code );
    }

    public function test_confirmation_waits_for_the_library_and_the_consent_category() {
        $order = new WCCT_Test_Fake_Order( array( 'id' => 9, 'key' => 'wc_order_abc' ) );
        $call  = 'window.wcctPurchaseSent("https://shop.test/wp-admin/admin-ajax.php", "facebook", 9, "wc_order_abc")';

        $this->assertSame( $call . '.when(function () { return window.fbq; }, "marketing");', PurchaseConfirmation::confirm_script( 'facebook', $order, 'window.fbq', 'marketing' ) );
        $this->assertSame( $call . '.when(function () { return true; }, "marketing");', PurchaseConfirmation::confirm_script( 'facebook', $order, '', 'marketing' ) );
        $this->assertSame( $call . '.when(function () { return window.fbq; });', PurchaseConfirmation::confirm_script( 'facebook', $order, 'window.fbq' ) );
        $this->assertStringContainsString( 'consent.allows(category)', PurchaseConfirmation::helper() );
    }
}
