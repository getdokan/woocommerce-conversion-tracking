<?php
// phpcs:disable

/**
 * The wcct/v1/whats-new routes (#124) on a store upgraded from 2.x by the real migration
 */
class WhatsNewRouteTest extends WCCT_Test_Rest_Case {

    const MIGRATION = 'StorePulse\ConversionTracking\Migration\SettingsMigration::maybe_run';

    // 2.x: Ads ID with its label pasted in, X Purchase without an event ID, a Custom script filling the customer's email, Meta switched off.
    const V1 = array(
        'facebook' => array( 'enabled' => false, 0 => array( 'pixel_id' => '123456789012345', 'events' => array( 'Purchase' => 'on' ) ) ),
        'adwords'  => array( 'enabled' => true, 0 => array( 'account_id' => 'AW-123456789/AbC-dEf_12', 'events' => array( 'Purchase' => 'on', 'Purchase-label' => '' ) ) ),
        'twitter'  => array( 'enabled' => true, 0 => array( 'events' => array( 'Purchase' => 'on', 'universal_tag_id' => 'o8x2k' ) ) ),
        'custom'   => array( 'enabled' => true, 'checkout' => "<script>ct('{order_number}', '{customer_email}');</script>", 'registration' => '' ),
    );

    const FEED = array(
        'types'        => array( 'grouped' ),
        'authenticate' => array( 'wcct-authenticate-enable' => 'yes', 'username' => 'meta', 'password' => 'hunter2' ),
    );

    /**
     * Steps that turn the test store into a 2.x store and run the migration, as the first request after the update does.
     *
     * @param array $v1 The 2.x settings.
     *
     * @return array
     */
    private static function upgrade( array $v1 = self::V1 ) {
        $steps = array();

        foreach ( array( 'wcct_settings_v3', 'wcct_pre3_snapshot', 'wcct_migration_flags', 'wcct_migration_done', 'wcct_migration_lock', 'wcct_migration_error', 'wcct_paused_platforms', 'wcct_onboarding_redirect', 'wcct_whats_new', 'wcct_consent_mode' ) as $option ) {
            $steps[] = array( 'set_option', $option, null );
        }

        $steps[] = array( 'set_option', 'wcct_install_type', 'upgrade' );
        $steps[] = array( 'set_option', 'wcct_data_feed', self::FEED );
        $steps[] = array( 'set_option', 'wcct_settings', $v1 );
        $steps[] = array( 'call', self::MIGRATION );

        return $steps;
    }

    public function test_only_users_who_manage_tracking_reach_the_routes() {
        $steps = self::upgrade();
        $users = array( 'admin' => 200, 'shop_manager' => 403, 'customer' => 403, 'subscriber' => 403, 'seller' => 403, 'logged_out' => 401 );

        foreach ( array_keys( $users ) as $user ) {
            $steps[] = array( 'request', 'GET', '/wcct/v1/whats-new', null, $user );
            $steps[] = array( 'request', 'POST', '/wcct/v1/whats-new/seen', array(), $user );
            $steps[] = array( 'request', 'POST', '/wcct/v1/whats-new/dismiss', array(), $user );
            $steps[] = array( 'request', 'POST', '/wcct/v1/whats-new/confirm', array( 'id' => 'google_ads_id_split' ), $user );
        }

        $expected = array();

        foreach ( $users as $status ) {
            $expected = array_merge( $expected, array_fill( 0, 4, $status ) );
        }

        $results = $this->rest( $steps );

        $this->assertSame( $expected, self::statuses( array_slice( $results, count( self::upgrade() ) ) ) );
    }

    public function test_a_store_that_started_on_3_0_has_no_whats_new() {
        $results = $this->rest(
            array(
                array( 'set_option', 'wcct_install_type', 'new' ),
                array( 'set_option', 'wcct_pre3_snapshot', null ),
                array( 'request', 'GET', '/wcct/v1/whats-new', null, 'admin' ),
                array( 'request', 'POST', '/wcct/v1/whats-new/dismiss', array(), 'admin' ),
                array( 'option', 'wcct_whats_new' ),
            )
        );

        $this->assertSame( array( 404, 'wcct_rest_no_whats_new' ), array( $results[2]['status'], $results[2]['data']['code'] ) );
        $this->assertSame( 404, $results[3]['status'] );
        $this->assertNull( $results[4] );
    }

    public function test_hidden_while_a_migration_has_failed() {
        $steps   = self::upgrade();
        $skip    = count( $steps );
        $steps[] = array( 'set_option', 'wcct_migration_error', array( 'message' => 'Boom', 'time' => 1700000000, 'source' => 'pro' ) );
        $steps[] = array( 'request', 'GET', '/wcct/v1/whats-new', null, 'admin' );
        $steps[] = array( 'set_option', 'wcct_migration_error', null );
        $steps[] = array( 'request', 'GET', '/wcct/v1/whats-new', null, 'admin' );

        $results = array_slice( $this->rest( $steps ), $skip );

        $this->assertSame( array( 404, 'wcct_rest_no_whats_new' ), array( $results[1]['status'], $results[1]['data']['code'] ) );
        $this->assertSame( 200, $results[3]['status'] );
    }

    public function test_needs_attention_follows_the_flags_and_the_live_settings() {
        $steps   = self::upgrade();
        $skip    = count( $steps );
        $steps[] = array( 'option', 'wcct_onboarding_redirect' );
        $steps[] = array( 'request', 'GET', '/wcct/v1/whats-new', null, 'admin' );
        // X Purchase gets its event ID: the X item goes, though the flag option keeps it.
        $steps[] = array( 'request', 'POST', '/wcct/v1/settings', array( 'platforms' => array( 'twitter' => array( 'purchase_event_id' => 'tw-o8x2k-abc12' ) ) ), 'admin' );
        $steps[] = array( 'request', 'GET', '/wcct/v1/whats-new', null, 'admin' );
        $steps[] = array( 'option', 'wcct_migration_flags' );
        // A new label: the Google item goes too.
        $steps[] = array( 'request', 'POST', '/wcct/v1/settings', array( 'platforms' => array( 'google' => array( 'purchase_label' => 'NewLabel99' ) ) ), 'admin' );
        $steps[] = array( 'request', 'GET', '/wcct/v1/whats-new', null, 'admin' );

        $results = array_slice( $this->rest( $steps ), $skip );

        $this->assertSame( 'whats_new', $results[0], 'The migration queued the one-time redirect' );

        $page = $results[1]['data'];

        $this->assertSame( 'attention', $page['state'] );
        $this->assertSame( array( 'google_ads_id_split', 'twitter_event_ids' ), array_column( $page['attention'], 'id' ) );
        $this->assertSame( array( '#/platforms/google?field=ads_id', '#/platforms/twitter?field=purchase_event_id' ), array_column( $page['attention'], 'url' ) );
        $this->assertSame( 'We split your Google Ads ID into a conversion ID and a label.', $page['attention'][0]['title'] );
        $this->assertSame( array( 'X retired the old event codes.', 'Purchases need a new event ID.', 'Set up X' ), array( $page['attention'][1]['title'], $page['attention'][1]['text'], $page['attention'][1]['action'] ) );
        $this->assertNotContains( 'custom', array_column( $page['attention'], 'platform' ), 'A script with {customer_email} keeps filling it, so no customer item' );
        $this->assertSame( array( true, false, false, false, 0 ), array( $page['backup'], $page['dismissed'], $page['seen'], $page['consent'], $page['extra_pixels'] ) );
        $this->assertSame( array( 'facebook' ), array_column( $page['paused'], 'id' ) );
        $this->assertSame( self::pro_loaded() ? array( 'pro' ) : array(), array_column( $page['notes'], 'id' ) );

        $this->assertSame( 200, $results[2]['status'] );
        $this->assertSame( array( 'google_ads_id_split' ), array_column( $results[3]['data']['attention'], 'id' ) );
        $this->assertArrayHasKey( 'twitter_event_ids', $results[4], 'The flag itself is never rewritten' );

        $this->assertSame( 200, $results[5]['status'] );
        $this->assertSame( array( 'done', array() ), array( $results[6]['data']['state'], $results[6]['data']['attention'] ) );
    }

    public function test_the_migrated_custom_script_keeps_customer_details_on() {
        $steps   = self::upgrade();
        $skip    = count( $steps );
        $steps[] = array( 'option', 'wcct_settings_v3' );

        $results = array_slice( $this->rest( $steps ), $skip );

        $this->assertTrue( $results[0]['platforms']['custom']['customer_details'] );
    }

    public function test_seen_uses_up_the_redirect_and_dismiss_hides_the_banner() {
        $steps   = self::upgrade();
        $skip    = count( $steps );
        $steps[] = array( 'request', 'POST', '/wcct/v1/whats-new/seen', array(), 'admin' );
        $steps[] = array( 'option', 'wcct_onboarding_redirect' );
        $steps[] = array( 'request', 'POST', '/wcct/v1/whats-new/dismiss', array(), 'admin' );
        $steps[] = array( 'option', 'wcct_whats_new' );
        $steps[] = array( 'request', 'GET', '/wcct/v1/whats-new', null, 'admin' );

        $results = array_slice( $this->rest( $steps ), $skip );

        $this->assertTrue( $results[0]['data']['seen'] );
        $this->assertSame( 'done', $results[1] );
        $this->assertTrue( $results[2]['data']['dismissed'] );
        $this->assertIsInt( $results[3]['dismissed'] );
        $this->assertSame( array( 'google_ads_id_split', 'twitter_event_ids' ), $results[3]['flags'] );
        $this->assertSame( array( true, true ), array( $results[4]['data']['dismissed'], $results[4]['data']['seen'] ), 'The page stays reachable' );
    }

    public function test_looks_right_closes_only_items_that_offer_it() {
        $steps   = self::upgrade();
        $skip    = count( $steps );
        $steps[] = array( 'request', 'GET', '/wcct/v1/whats-new', null, 'admin' );
        $steps[] = array( 'request', 'POST', '/wcct/v1/whats-new/confirm', array( 'id' => 'twitter_event_ids' ), 'admin' );
        $steps[] = array( 'request', 'POST', '/wcct/v1/whats-new/confirm', array( 'id' => 'nope' ), 'admin' );
        $steps[] = array( 'request', 'POST', '/wcct/v1/whats-new/confirm', array( 'id' => 'google_ads_id_split' ), 'admin' );
        $steps[] = array( 'option', 'wcct_whats_new' );
        $steps[] = array( 'request', 'POST', '/wcct/v1/whats-new/confirm', array( 'id' => 'google_ads_id_split' ), 'admin' );

        $results = array_slice( $this->rest( $steps ), $skip );

        $this->assertSame( array( true, false ), array_column( $results[0]['data']['attention'], 'confirmable' ) );
        $this->assertSame( array( 400, 'wcct_rest_whats_new_item' ), array( $results[1]['status'], $results[1]['data']['code'] ), 'X needs its event ID' );
        $this->assertSame( 400, $results[2]['status'] );
        $this->assertSame( 200, $results[3]['status'] );
        $this->assertSame( array( 'twitter_event_ids' ), array_column( $results[3]['data']['attention'], 'id' ) );
        $this->assertSame( array( 'google_ads_id_split' ), $results[4]['confirmed'] );
        $this->assertSame( 400, $results[5]['status'], 'Already closed' );
    }

    public function test_a_flag_written_after_the_dismissal_brings_the_banner_back() {
        $google  = array( 'platform' => 'google', 'fields' => array( 'ads_id', 'purchase_label' ), 'saved' => 'AW-123456789/AbC-dEf_12', 'ads_id' => 'AW-123456789', 'purchase_label' => 'AbC-dEf_12' );
        $x       = array( 'platform' => 'twitter', 'fields' => array( 'purchase_event_id' ), 'events' => array( 'purchase' ) );
        $steps   = self::upgrade();
        $skip    = count( $steps );
        // Dismissed while only the Google flag existed.
        $steps[] = array( 'set_option', 'wcct_migration_flags', array( 'google_ads_id_split' => $google ) );
        $steps[] = array( 'request', 'POST', '/wcct/v1/whats-new/dismiss', array(), 'admin' );
        // A later migration (say, Pro updated after Free) adds one.
        $steps[] = array( 'set_option', 'wcct_migration_flags', array( 'google_ads_id_split' => $google, 'twitter_event_ids' => $x ) );
        $steps[] = array( 'request', 'GET', '/wcct/v1/whats-new', null, 'admin' );

        $results = array_slice( $this->rest( $steps ), $skip );

        $this->assertTrue( $results[1]['data']['dismissed'] );
        $this->assertSame( array( 'google_ads_id_split' ), array_column( $results[1]['data']['attention'], 'id' ) );
        $this->assertFalse( $results[3]['data']['dismissed'] );
        $this->assertSame( array( 'google_ads_id_split', 'twitter_event_ids' ), array_column( $results[3]['data']['attention'], 'id' ) );
    }

    public function test_a_resumed_platform_leaves_the_paused_list_and_the_download_has_no_password() {
        $steps   = self::upgrade();
        $skip    = count( $steps );
        $steps[] = array( 'request', 'POST', '/wcct/v1/settings/pause', array( 'platform' => 'facebook', 'paused' => false ), 'admin' );
        $steps[] = array( 'request', 'GET', '/wcct/v1/whats-new', null, 'admin' );
        $steps[] = array( 'request', 'GET', '/wcct/v1/backup', null, 'admin' );

        $results = array_slice( $this->rest( $steps ), $skip );

        $this->assertSame( 200, $results[0]['status'] );
        $this->assertSame( array(), $results[1]['data']['paused'] );

        $backup = $results[2];

        $this->assertSame( array( 200, 'snapshot' ), array( $backup['status'], $backup['data']['source'] ) );
        $this->assertSame( self::V1, $backup['data']['options']['wcct_settings'] );
        $this->assertSame( array( 'wcct-authenticate-enable' => 'yes', 'username' => 'meta' ), $backup['data']['options']['wcct_data_feed']['authenticate'] );
        $this->assertStringNotContainsString( 'hunter2', json_encode( $backup ) );
    }
}
