<?php

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Onboarding\Redirect;

/**
 * Tests for the activation redirect to the setup wizard
 */
class RedirectTest extends TestCase {

    // A single-site activation from the Plugins screen by an administrator.
    const ACTIVATION = array( 'can' => true );

    // The next admin page, opened by that administrator.
    const PAGE = array(
        'get'         => true,
        'can'         => true,
        'woocommerce' => true,
    );

    protected function setUp(): void {
        $GLOBALS['wcct_test_options'] = array();
    }

    protected function tearDown(): void {
        unset( $GLOBALS['wcct_test_options'] );
    }

    public function test_a_fresh_activation_redirects_on_the_next_admin_page_once() {
        $this->assertTrue( Redirect::queue( self::ACTIVATION ) );
        $this->assertSame( 'pending', $GLOBALS['wcct_test_options']['wcct_onboarding_redirect'] );

        $this->assertSame( 'https://shop.test/wp-admin/admin.php?page=conversion-tracking#/onboarding', Redirect::target( self::PAGE ) );
        $this->assertSame( 'done', $GLOBALS['wcct_test_options']['wcct_onboarding_redirect'] );
        $this->assertSame( '', Redirect::target( self::PAGE ) );
    }

    public function test_a_second_activation_never_queues_it_again() {
        Redirect::queue( self::ACTIVATION );
        Redirect::target( self::PAGE );

        // Deactivated and activated again.
        $this->assertFalse( Redirect::queue( self::ACTIVATION ) );
        $this->assertSame( '', Redirect::target( self::PAGE ) );
    }

    /**
     * @dataProvider skipped_activations
     */
    public function test_activations_that_never_redirect( array $flags ) {
        $this->assertFalse( Redirect::queue( array_merge( self::ACTIVATION, $flags ) ) );
        $this->assertArrayNotHasKey( 'wcct_onboarding_redirect', $GLOBALS['wcct_test_options'] );
        $this->assertSame( '', Redirect::target( self::PAGE ) );
    }

    public function skipped_activations(): array {
        return array(
            'bulk'                  => array( array( 'bulk' => true ) ),
            'network'               => array( array( 'network_wide' => true ) ),
            'network admin'         => array( array( 'network_admin' => true ) ),
            'WP-CLI'                => array( array( 'cli' => true ) ),
            'AJAX'                  => array( array( 'ajax' => true ) ),
            'REST'                  => array( array( 'rest' => true ) ),
            'cron'                  => array( array( 'cron' => true ) ),
            'user without the cap'  => array( array( 'can' => false ) ),
        );
    }

    public function test_an_upgrader_is_never_redirected() {
        $GLOBALS['wcct_test_options']['wcct_settings'] = array( 'facebook' => array( 'enabled' => true ) );

        $this->assertFalse( Redirect::queue( self::ACTIVATION ) );

        // The recorded install type wins over what the 2.x settings say now.
        $GLOBALS['wcct_test_options'] = array( 'wcct_install_type' => 'upgrade' );

        $this->assertFalse( Redirect::queue( self::ACTIVATION ) );
    }

    /**
     * @dataProvider opened_wizards
     */
    public function test_a_store_that_opened_the_wizard_is_not_redirected( string $status ) {
        $GLOBALS['wcct_test_options']['wcct_onboarding'] = array(
            'status' => $status,
            'step'   => 2,
        );

        $this->assertFalse( Redirect::queue( self::ACTIVATION ) );
    }

    public function opened_wizards(): array {
        return array(
            'in progress' => array( 'in_progress' ),
            'skipped'     => array( 'skipped' ),
            'done'        => array( 'done' ),
        );
    }

    /**
     * @dataProvider background_requests
     */
    public function test_background_requests_leave_it_for_the_next_admin_page( array $flags ) {
        Redirect::queue( self::ACTIVATION );

        $this->assertSame( '', Redirect::target( array_merge( self::PAGE, $flags ) ) );
        $this->assertSame( 'pending', $GLOBALS['wcct_test_options']['wcct_onboarding_redirect'] );
        $this->assertNotSame( '', Redirect::target( self::PAGE ) );
    }

    public function background_requests(): array {
        return array(
            'AJAX'                 => array( array( 'ajax' => true ) ),
            'WP-CLI'               => array( array( 'cli' => true ) ),
            'REST'                 => array( array( 'rest' => true ) ),
            'cron'                 => array( array( 'cron' => true ) ),
            'iframe'               => array( array( 'iframe' => true ) ),
            'user without the cap' => array( array( 'can' => false ) ),
            'form post'            => array( array( 'get' => false ) ),
        );
    }

    /**
     * @dataProvider dropping_pages
     */
    public function test_a_bulk_network_or_no_woocommerce_page_uses_it_up_without_redirecting( array $flags ) {
        Redirect::queue( self::ACTIVATION );

        $this->assertSame( '', Redirect::target( array_merge( self::PAGE, $flags ) ) );
        $this->assertSame( 'done', $GLOBALS['wcct_test_options']['wcct_onboarding_redirect'] );
        $this->assertSame( '', Redirect::target( self::PAGE ) );
    }

    public function dropping_pages(): array {
        return array(
            'activate-multi'       => array( array( 'bulk' => true ) ),
            'network admin'        => array( array( 'network_admin' => true ) ),
            'WooCommerce inactive' => array( array( 'woocommerce' => false ) ),
        );
    }

    public function test_a_wizard_opened_before_the_redirect_cancels_it() {
        Redirect::queue( self::ACTIVATION );

        $GLOBALS['wcct_test_options']['wcct_onboarding'] = array( 'status' => 'skipped' );

        $this->assertSame( '', Redirect::target( self::PAGE ) );
        $this->assertSame( 'done', $GLOBALS['wcct_test_options']['wcct_onboarding_redirect'] );
    }

    public function test_an_unknown_stored_value_never_redirects() {
        $GLOBALS['wcct_test_options']['wcct_onboarding_redirect'] = 'yes';

        $this->assertSame( '', Redirect::target( self::PAGE ) );
        $this->assertFalse( Redirect::queue( self::ACTIVATION ) );
    }
}
