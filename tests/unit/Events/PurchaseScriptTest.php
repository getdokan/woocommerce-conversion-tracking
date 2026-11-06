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
        $this->assertStringEndsWith( 'window.wcctPurchaseSent("https://shop.test/wp-json/wcct/v1/events", "twitter", 9, "wc_order_abc", "wcct_9_purchase", "")();', $code );
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
        $call  = 'window.wcctPurchaseSent("https://shop.test/wp-json/wcct/v1/events", "facebook", 9, "wc_order_abc", "wcct_9_purchase", ';

        $this->assertSame( $call . '"marketing").when(function () { return window.fbq; }, "marketing");', PurchaseConfirmation::confirm_script( 'facebook', $order, 'window.fbq', 'marketing' ) );
        $this->assertSame( $call . '"marketing").when(function () { return true; }, "marketing");', PurchaseConfirmation::confirm_script( 'facebook', $order, '', 'marketing' ) );
        $this->assertSame( $call . '"").when(function () { return window.fbq; });', PurchaseConfirmation::confirm_script( 'facebook', $order, 'window.fbq' ) );
        $this->assertStringContainsString( 'consent.allows(needs)', PurchaseConfirmation::helper() );
    }

    public function test_the_check_in_goes_to_the_events_route_and_reports_a_miss_too() {
        $helper = PurchaseConfirmation::helper();

        $this->assertStringContainsString( 'navigator.sendBeacon(url', $helper );
        $this->assertStringContainsString( 'keepalive: true', $helper );
        $this->assertStringContainsString( '"&loaded=" + (loaded ? 1 : 0) + "&consent=" + consentState()', $helper );
        $this->assertStringContainsString( 'miss("timeout", false); }, 10000)', $helper );
        $this->assertStringContainsString( 'addEventListener("pagehide", function () { miss("hidden", true); })', $helper );
        $this->assertStringNotContainsString( 'wcct_purchase_sent', $helper );
    }

    public function test_a_live_page_retries_once_on_admin_ajax_when_rest_is_blocked() {
        $helper = PurchaseConfirmation::helper();

        $this->assertStringContainsString( 'if (!unloading && window.fetch) return post(url, body, true);', $helper );
        $this->assertStringContainsString( 'response.status == 401 || response.status == 403 || response.status == 404', $helper );
        $this->assertStringContainsString( 'post("https://shop.test/wp-admin/admin-ajax.php", "action=wcct_events&" + body, false)', $helper );
        $this->assertStringContainsString( 'console.warn(', $helper );
    }

    public function test_the_key_comes_from_window_wcct_order_when_the_page_has_none() {
        $this->assertStringContainsString( 'if (!key && order && order.key && (!+order.id || String(order.id) === String(orderId))) key = order.key;', PurchaseConfirmation::helper() );
    }

    public function test_callbacks_report_the_integration_consent_category() {
        $platform = new class( 'ga4' ) extends WCCT_Test_Fake_Integration {
            protected $consent_category = 'statistics';

            public function callback( $order ) {
                return $this->purchase_sent_callback( $order );
            }
        };
        $order    = new WCCT_Test_Fake_Order( array( 'id' => 9, 'key' => 'wc_order_abc' ) );

        $this->assertStringEndsWith( '"wcct_9_purchase", "statistics")', $platform->callback( $order ) );
        $this->assertStringEndsWith( '"wcct_9_purchase", "marketing")', ( new class( 'adwords' ) extends WCCT_Test_Fake_Integration {
            public function callback( $order ) {
                return $this->purchase_sent_callback( $order );
            }
        } )->callback( $order ) );
    }

    public function test_library_check_defaults_to_the_purchase_check() {
        $this->assertSame( 'true', ( new WCCT_Test_Fake_Integration( 'custom' ) )->library_loaded_check() );

        $platform = new class( 'facebook' ) extends WCCT_Test_Fake_Integration {
            protected function purchase_sent_check() {
                return 'window.fbq';
            }
        };

        $this->assertSame( 'window.fbq', $platform->library_loaded_check() );
    }
}
