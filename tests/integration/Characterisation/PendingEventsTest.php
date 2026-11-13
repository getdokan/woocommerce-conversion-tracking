<?php
// phpcs:disable

use PHPUnit\Framework\TestCase;

/**
 * Add to cart reaches the browser from the session (fragment or uncached request), never from the page HTML
 */
class PendingEventsTest extends TestCase {

    private static $result;

    private function result() {
        if ( null === self::$result ) {
            self::$result = WCCT_Test_WP::run(
                array(
                    'type'     => 'file',
                    'file'     => dirname( __DIR__ ) . '/jobs/pending-add-to-cart.php',
                    'rollback' => true,
                    'options'  => array( 'wcct_settings' => WCCT_Test_Settings::lite_all() ),
                )
            );
        }

        $this->assertNull( self::$result['error'] );

        return self::$result['value'];
    }

    public function test_each_add_is_queued_once_with_code_per_platform() {
        $queued = $this->result()['queued'];

        $this->assertCount( 2, $queued );
        $this->assertSame( array( 'facebook', 'ga4' ), array_keys( $queued[0]['scripts'] ) );
        $this->assertMatchesRegularExpression( '/^wcct_[0-9a-f-]{36}$/', $queued[0]['id'] );
        $this->assertNotSame( $queued[0]['id'], $queued[1]['id'] );
    }

    public function test_meta_sends_the_product_added_and_its_value() {
        $queued = $this->result()['queued'];

        $this->assertStringContainsString( "wcfbq(\"111111111111111\", \"AddToCart\", {\n    \"content_ids\": [\n        \"2001\"\n    ],\n    \"content_type\": \"product\",\n    \"value\": \"36.00\",", $queued[0]['scripts']['facebook'] );
        $this->assertMatchesRegularExpression( '/"content_ids": \[\s*"2003"\s*\]/', $queued[1]['scripts']['facebook'] );

        // The Meta event ID is made when the queued script runs in the browser.
        $this->assertStringEndsWith( '}, id);})(wcctMeta.eventId());', $queued[0]['scripts']['facebook'] );
        $this->assertStringNotContainsString( 'wcct_', $queued[0]['scripts']['facebook'] );
        $this->assertStringContainsString( '"value": "42.00"', $queued[1]['scripts']['facebook'] );
    }

    public function test_ga4_sends_the_item_added() {
        $queued = $this->result()['queued'];

        $this->assertStringContainsString( "('event', 'add_to_cart', {\"send_to\":\"G-CTTEST1234\",\"currency\":\"USD\",\"value\":36,\"items\":[{\"item_id\":\"2001\",\"item_name\":\"Beanie\"", $queued[0]['scripts']['ga4'] );
        $this->assertStringContainsString( '"item_id":"2003"', $queued[1]['scripts']['ga4'] );
    }

    public function test_the_ajax_response_carries_the_adds_in_a_fragment() {
        $value = $this->result();

        $this->assertSame( array_column( $value['queued'], 'id' ), array_column( $value['fragment'], 'id' ) );
    }

    public function test_only_events_after_the_seen_cookie_are_sent() {
        $value = $this->result();

        $this->assertSame( array( $value['queued'][1]['id'] ), $value['unseen'] );
    }

    public function test_the_footer_has_no_session_data() {
        $value  = $this->result();
        $footer = implode( "\n", $value['footer'] ) . $value['runner'];

        foreach ( $value['queued'] as $entry ) {
            $this->assertStringNotContainsString( $entry['id'], $footer );
        }

        $this->assertStringNotContainsString( 'AddToCart', $footer );
        $this->assertStringNotContainsString( 'add_to_cart', $footer );
    }
}
