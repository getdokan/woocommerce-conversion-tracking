<?php
// phpcs:disable

use PHPUnit\Framework\TestCase;

/**
 * Wishlist adds: hooked as actions, queued for the browser, and nothing on Free
 */
class WishlistTest extends TestCase {

    private function job( $plugin ) {
        $result = WCCT_Test_WP::run(
            array(
                'type'     => 'file',
                'file'     => dirname( __DIR__ ) . '/jobs/wishlist.php',
                'rollback' => true,
                'options'  => array( 'wcct_settings' => WCCT_Test_Settings::lite_all() ),
                'args'     => array( 'plugin' => $plugin ),
            )
        );

        $this->assertNull( $result['error'] );

        return $result['value'];
    }

    protected function setUp(): void {
        if ( count( WCCT_Test_WP::$env['plugins'] ) > 2 ) {
            $this->markTestSkipped( 'Pro is loaded; Pro checks its own wishlist events.' );
        }
    }

    public function test_the_yith_hook_is_an_action_that_leaves_its_argument_unchanged() {
        $value = $this->job( true );

        $this->assertSame( array( 1 ), $value['yith_accepted_args'] );
        $this->assertSame( 2001, $value['yith_as_filter'] );
    }

    public function test_free_sends_no_wishlist_event() {
        $value = $this->job( true );

        $this->assertSame( array(), $value['queued'] );
        $this->assertSame( array(), $value['triggers'] );
        $this->assertStringNotContainsString( 'wishlist', $value['runner'] );
    }
}
