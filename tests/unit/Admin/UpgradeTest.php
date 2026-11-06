<?php

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Admin\Upgrade;

/**
 * Tests for the one place upgrade and subscription links come from
 */
class UpgradeTest extends TestCase {

    protected function tearDown(): void {
        unset( $GLOBALS['wcct_test_filters'] );
    }

    public function test_links_go_to_woocommerce_com() {
        $this->assertSame(
            array(
                'upgrade' => 'https://woocommerce.com/products/woocommerce-conversion-tracking-pro/',
                'manage'  => 'https://woocommerce.com/my-account/my-subscriptions/',
            ),
            Upgrade::links()
        );
        $this->assertSame( Upgrade::PRODUCT_URL, Upgrade::url() );
        $this->assertSame( Upgrade::SUBSCRIPTIONS_URL, Upgrade::manage_url() );
    }

    public function test_the_filter_can_change_a_link() {
        $GLOBALS['wcct_test_filters'][ Upgrade::FILTER ] = function ( $links ) {
            $links['upgrade'] = 'https://example.com/pro/?ref=1';

            return $links;
        };

        $this->assertSame( 'https://example.com/pro/?ref=1', Upgrade::url() );
        $this->assertSame( Upgrade::SUBSCRIPTIONS_URL, Upgrade::manage_url() );
    }

    /**
     * @dataProvider bad_filter_values
     */
    public function test_a_bad_filtered_value_keeps_the_default( $filtered ) {
        $GLOBALS['wcct_test_filters'][ Upgrade::FILTER ] = function () use ( $filtered ) {
            return $filtered;
        };

        $this->assertSame( Upgrade::PRODUCT_URL, Upgrade::url() );
        $this->assertSame( Upgrade::SUBSCRIPTIONS_URL, Upgrade::manage_url() );
    }

    public function bad_filter_values() {
        return array(
            'not an array' => array( 'https://example.com/' ),
            'http'         => array( array( 'upgrade' => 'http://example.com/', 'manage' => 'http://example.com/' ) ),
            'script'       => array( array( 'upgrade' => 'javascript:alert(1)', 'manage' => '' ) ),
            'not a string' => array( array( 'upgrade' => array( 'x' ), 'manage' => 42 ) ),
            'relative'     => array( array( 'upgrade' => '/upgrade/', 'manage' => 'https://' ) ),
        );
    }
}
