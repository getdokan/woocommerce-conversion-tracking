<?php
// phpcs:disable

/**
 * Permission and round-trip tests for the onboarding, backup, catalog and access routes on Free
 */
class RoutesTest extends WCCT_Test_Rest_Case {

    const V1 = array( 'facebook' => array( 'enabled' => true, 0 => array( 'pixel_id' => '111111111111111' ) ) );

    const FEED = array(
        'types'        => array( 'grouped' ),
        'authenticate' => array( 'wcct-authenticate-enable' => 'yes', 'username' => 'meta', 'password' => 'hunter2' ),
    );

    protected function setUp(): void {
        if ( self::pro_loaded() ) {
            $this->markTestSkipped( 'Free only; Pro has its own route tests.' );
        }
    }

    public function test_only_administrators_reach_the_routes_on_free() {
        $steps = array( array( 'set_option', 'wcct_settings', self::V1 ), array( 'set_option', 'wcct_permissions', array( 'shop_manager' => '1', 'seller' => '1' ) ) );
        $users = array( 'admin' => 200, 'shop_manager' => 403, 'customer' => 403, 'subscriber' => 403, 'seller' => 403, 'logged_out' => 401 );

        foreach ( $users as $user => $status ) {
            foreach ( self::READ_ROUTES as $route ) {
                $steps[] = array( 'request', 'GET', $route, null, $user );
            }
            $steps[] = array( 'request', 'POST', '/wcct/v1/onboarding', array( 'step' => 1 ), $user );
        }

        $statuses = array_slice( self::statuses( $this->rest( $steps ) ), 2 );
        $expected = array();

        foreach ( $users as $user => $status ) {
            $expected = array_merge( $expected, array_fill( 0, 5, $status ) );
        }

        // Free has no role grants: wcct_permissions changes nothing.
        $this->assertSame( $expected, $statuses );
    }

    public function test_onboarding_round_trip_never_touches_the_settings() {
        $settings = array( 'consent_mode' => false, 'platforms' => array( 'facebook' => array( 'pixel_id' => '111111111111111' ) ) );
        $results  = $this->rest(
            array(
                array( 'set_option', 'wcct_settings_v3', $settings ),
                array( 'request', 'GET', '/wcct/v1/onboarding', null, 'admin' ),
                array( 'request', 'POST', '/wcct/v1/onboarding', array( 'step' => 3, 'platforms' => array( 'facebook', 'google' ), 'eu' => 'yes' ), 'admin' ),
                array( 'request', 'POST', '/wcct/v1/onboarding', array( 'action' => 'skip', 'step' => 2 ), 'admin' ),
                array( 'request', 'GET', '/wcct/v1/onboarding', null, 'admin' ),
                array( 'request', 'POST', '/wcct/v1/onboarding', array( 'platforms' => array( 'bing_ads' ) ), 'admin' ),
                array( 'request', 'POST', '/wcct/v1/onboarding', array( 'action' => 'finish' ), 'admin' ),
                array( 'request', 'POST', '/wcct/v1/onboarding', array( 'action' => 'skip', 'step' => 1 ), 'admin' ),
                array( 'option', 'wcct_onboarding' ),
                array( 'option', 'wcct_settings_v3' ),
            )
        );

        $this->assertSame( array( 'status' => 'new', 'step' => 0, 'platforms' => array(), 'eu' => '' ), $results[1]['data'] );
        $this->assertSame( array( 'status' => 'in_progress', 'step' => 3, 'platforms' => array( 'facebook', 'google' ), 'eu' => 'yes' ), $results[2]['data'] );
        $this->assertSame( array( 'status' => 'skipped', 'step' => 3, 'platforms' => array( 'facebook', 'google' ), 'eu' => 'yes' ), $results[4]['data'] );
        $this->assertSame( 400, $results[5]['status'], 'Microsoft is a Pro platform' );
        $this->assertSame( 'done', $results[6]['data']['status'] );
        $this->assertSame( array( 409, 'wcct_rest_onboarding_done' ), array( $results[7]['status'], $results[7]['data']['code'] ) );
        $this->assertSame( array( 'status' => 'done', 'step' => 6, 'platforms' => array( 'facebook', 'google' ), 'eu' => 'yes' ), $results[8] );
        $this->assertSame( $settings, $results[9] );
    }

    public function test_backup_needs_2x_settings_and_never_contains_a_password() {
        $results = $this->rest(
            array(
                array( 'request', 'GET', '/wcct/v1/backup', null, 'admin' ),
                array( 'set_option', 'wcct_permissions', array( 'shop_manager' => '1' ) ),
                array( 'set_option', 'wcct_data_feed', self::FEED ),
                array( 'request', 'GET', '/wcct/v1/backup', null, 'admin' ),
                array( 'set_option', 'wcct_settings', self::V1 ),
                array( 'request', 'GET', '/wcct/v1/backup', null, 'admin' ),
                array( 'set_option', 'wcct_pre3_snapshot', array( 'wcct_settings' => array( 'frozen' => true ), 'wcct_data_feed' => self::FEED ) ),
                array( 'request', 'GET', '/wcct/v1/backup', null, 'admin' ),
                array( 'request', 'POST', '/wcct/v1/backup', array(), 'admin' ),
            )
        );

        $this->assertSame( array( 404, 'wcct_rest_no_backup' ), array( $results[0]['status'], $results[0]['data']['code'] ) );
        $this->assertSame( 404, $results[3]['status'], 'Options 3.0 also writes are no backup on their own' );

        $this->assertSame( 200, $results[5]['status'] );
        $this->assertSame( 'live', $results[5]['data']['source'] );
        $this->assertSame( array( 'wcct_settings' => self::V1 ), $results[5]['data']['options'] );
        $this->assertStringStartsWith( 'attachment; filename="conversion-tracking-settings-before-3.0-', $results[5]['disposition'] );

        // The frozen copy wins, with the 1.1.x feed password removed.
        $this->assertSame( 'snapshot', $results[7]['data']['source'] );
        $this->assertSame( array( 'frozen' => true ), $results[7]['data']['options']['wcct_settings'] );
        $this->assertSame( array( 'wcct-authenticate-enable' => 'yes', 'username' => 'meta' ), $results[7]['data']['options']['wcct_data_feed']['authenticate'] );
        $this->assertStringNotContainsString( 'hunter2', json_encode( array( $results[5], $results[7] ) ) );

        $this->assertSame( 404, $results[8]['status'], 'Read-only' );
    }

    public function test_catalog_and_access_are_locked_on_free() {
        $results = $this->rest(
            array(
                array( 'request', 'GET', '/wcct/v1/catalog', null, 'admin' ),
                array( 'request', 'GET', '/wcct/v1/access', null, 'admin' ),
                array( 'request', 'POST', '/wcct/v1/catalog', array( 'rebuild' => true ), 'admin' ),
                array( 'request', 'POST', '/wcct/v1/access', array( 'roles' => array( 'shop_manager' ) ), 'admin' ),
                array( 'option', 'wcct_permissions' ),
            )
        );

        $this->assertSame( array( 'locked' => true ), $results[0]['data'] );
        $this->assertSame( array( 'locked' => true ), $results[1]['data'] );
        $this->assertSame( array( 404, 404 ), array( $results[2]['status'], $results[3]['status'] ) );
        $this->assertNull( $results[4] );
    }
}
