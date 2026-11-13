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

    /**
     * A store the migration upgraded: 2.x settings, a snapshot and the 3.0 settings.
     */
    private function upgraded() {
        $GLOBALS['wcct_test_options'] = array(
            'wcct_install_type'  => 'upgrade',
            'wcct_settings'      => array( 'facebook' => array( 'enabled' => true ) ),
            'wcct_pre3_snapshot' => array( 'wcct_settings' => array( 'facebook' => array( 'enabled' => true ) ) ),
            'wcct_settings_v3'   => array( 'platforms' => array() ),
        );
    }

    public function test_an_upgrade_opens_whats_new_once_instead_of_the_wizard() {
        $this->upgraded();

        $this->assertTrue( Redirect::queue_whats_new() );
        $this->assertFalse( Redirect::queue_whats_new(), 'Only once' );
        $this->assertSame( 'https://shop.test/wp-admin/admin.php?page=conversion-tracking#/whats-new', Redirect::target( self::PAGE ) );
        $this->assertSame( 'done', $GLOBALS['wcct_test_options']['wcct_onboarding_redirect'] );
        $this->assertSame( '', Redirect::target( self::PAGE ) );
        $this->assertFalse( Redirect::queue( self::ACTIVATION ), 'Activating again never queues the wizard' );
    }

    public function test_a_fresh_install_never_queues_whats_new() {
        $this->assertFalse( Redirect::queue_whats_new() );
        $this->assertArrayNotHasKey( 'wcct_onboarding_redirect', $GLOBALS['wcct_test_options'] );
    }

    /**
     * @dataProvider background_requests
     */
    public function test_whats_new_waits_for_a_page_that_can_show_it( array $flags ) {
        $this->upgraded();
        Redirect::queue_whats_new();

        $this->assertSame( '', Redirect::target( array_merge( self::PAGE, $flags ) ) );
        $this->assertTrue( Redirect::whats_new_pending() );
        $this->assertStringEndsWith( '#/whats-new', Redirect::target( self::PAGE ) );
    }

    /**
     * @dataProvider dropping_pages
     */
    public function test_a_bulk_or_network_page_uses_up_whats_new( array $flags ) {
        $this->upgraded();
        Redirect::queue_whats_new();

        $this->assertSame( '', Redirect::target( array_merge( self::PAGE, $flags ) ) );
        $this->assertFalse( Redirect::whats_new_pending() );
    }

    public function test_whats_new_opened_on_its_own_or_gone_drops_the_redirect() {
        $this->upgraded();
        Redirect::queue_whats_new();
        Redirect::drop_whats_new();

        $this->assertSame( '', Redirect::target( self::PAGE ) );

        // Queued, but the 3.0 settings are gone again (say, deleted by hand): no page to open.
        $this->upgraded();
        Redirect::queue_whats_new();
        unset( $GLOBALS['wcct_test_options']['wcct_settings_v3'] );

        $this->assertSame( '', Redirect::target( self::PAGE ) );
        $this->assertSame( 'done', $GLOBALS['wcct_test_options']['wcct_onboarding_redirect'] );
    }

    public function test_dropping_whats_new_leaves_the_wizard_redirect_alone() {
        Redirect::queue( self::ACTIVATION );
        Redirect::drop_whats_new();

        $this->assertSame( 'pending', $GLOBALS['wcct_test_options']['wcct_onboarding_redirect'] );
    }

    public function test_whats_new_waits_while_a_migration_has_failed() {
        $this->upgraded();
        Redirect::queue_whats_new();

        // Pro's migration failed after Lite's ran.
        $GLOBALS['wcct_test_options']['wcct_migration_error'] = array( 'message' => 'Boom', 'time' => 1700000000, 'source' => 'pro' );

        $this->assertSame( '', Redirect::target( self::PAGE ) );
        $this->assertTrue( Redirect::whats_new_pending() );

        // Retry succeeded.
        unset( $GLOBALS['wcct_test_options']['wcct_migration_error'] );

        $this->assertStringEndsWith( '#/whats-new', Redirect::target( self::PAGE ) );
    }
}
