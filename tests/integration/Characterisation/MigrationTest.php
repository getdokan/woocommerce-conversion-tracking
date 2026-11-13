<?php
// phpcs:disable

/**
 * The 2.x settings migration (#122) in a real store: what it writes, its lock, Retry, and that it leaves the storefront output as it was
 *
 * Every other characterisation test seeds only wcct_settings, so each of their requests runs this migration first and compares the result with the fixtures recorded from 2.1.6.
 */
class MigrationTest extends WCCT_Test_Page_Case {

    /**
     * 2.x fixtures (tests/fixtures/settings-2x.php) compared before and after the migration.
     */
    const FIXTURES = array( 'all-on', 'ads-only', 'ads-id-with-label', 'ads-odd-slashes', 'flat-2-0', 'x-pro-labels', 'off-with-ids', 'string-switches', 'custom-signup-placeholder', 'malformed' );

    /**
     * Pages compared: page events and head blocks, and a verified customer's Purchase.
     */
    const PAGES = array( 'product-simple', 'order-received-customer' );

    protected function setUp(): void {
        parent::setUp();

        if ( count( WCCT_Test_WP::$env['plugins'] ) > 2 ) {
            $this->markTestSkipped( 'Lite only; Pro migrates its own settings (#123).' );
        }
    }

    protected function fixtures_dir() {
        return dirname( __DIR__, 2 ) . '/fixtures';
    }

    private static function v1( $name ) {
        $fixtures = require dirname( __DIR__, 2 ) . '/fixtures/settings-2x.php';

        return $fixtures[ $name ];
    }

    private function state( array $options, array $args = array() ) {
        $result = WCCT_Test_WP::run(
            array(
                'type'     => 'file',
                'file'     => dirname( __DIR__ ) . '/jobs/migration-state.php',
                'rollback' => true,
                'options'  => $options,
                'args'     => $args,
            )
        );

        $this->assertNull( $result['error'] );

        return $result['value'];
    }

    public function test_the_first_request_on_a_2x_store_migrates_and_leaves_wcct_settings_as_it_was() {
        $v1    = self::v1( 'all-on' );
        $state = $this->state( array( 'wcct_settings' => $v1, 'wcct_permissions' => array( 'shop_manager' => '1' ) ) )['after_request'];

        $this->assertSame( serialize( $v1 ), $state['v1_raw'] );
        $this->assertSame( $state['expected'], $state['v3'] );
        $this->assertSame( 'all', $state['v3']['platforms']['facebook']['value_mode'] );
        $this->assertTrue( $state['v3']['platforms']['custom']['customer_details'] );
        $this->assertSame( array( 'wcct_settings' => $v1, 'wcct_permissions' => array( 'shop_manager' => '1' ) ), $state['snapshot'] );
        $this->assertSame( array( 'twitter_event_ids' ), array_keys( $state['flags'] ) );
        $this->assertSame( $state['version'], $state['marker'] );
        $this->assertNull( $state['lock'] );
        $this->assertNull( $state['error'] );
        $this->assertIsNumeric( $state['since'] );
        $this->assertNull( $state['paused'] );

        $off = $this->state( array( 'wcct_settings' => self::v1( 'off-with-ids' ) ) )['after_request'];

        $this->assertEqualsCanonicalizing( array( 'facebook', 'google', 'custom' ), $off['paused'] );

        // A list that exists gains them.
        $listed = $this->state( array( 'wcct_settings' => self::v1( 'off-with-ids' ), 'wcct_paused_platforms' => array( 'twitter' ) ) )['after_request'];

        $this->assertSame( array( 'twitter', 'facebook', 'google', 'custom' ), $listed['paused'] );
    }

    public function test_the_settings_page_opens_after_migrating_malformed_settings() {
        $out = $this->state( array( 'wcct_settings' => self::v1( 'malformed' ) ), array( 'classic' => true ) );

        $this->assertNotNull( $out['after_request']['v3'] );
        $this->assertMatchesRegularExpression( '/wcct-app-root|settings app is missing/', $out['classic'] );
    }

    public function test_a_clean_install_writes_no_settings_snapshot_flags_marker_or_lock() {
        $state = $this->state( array( 'wcct_settings' => null, 'wcct_install_type' => 'new' ) )['after_request'];

        foreach ( array( 'v1_raw', 'v3', 'snapshot', 'flags', 'marker', 'lock', 'error', 'paused' ) as $key ) {
            $this->assertNull( $state[ $key ], $key );
        }
    }

    public function test_a_held_lock_leaves_the_2x_view_and_an_abandoned_one_is_taken_over() {
        $held = $this->state( array( 'wcct_settings' => self::v1( 'all-on' ), 'wcct_migration_lock' => time() . '|other' ) )['after_request'];

        $this->assertNull( $held['v3'] );
        $this->assertNull( $held['marker'] );

        $stale = $this->state( array( 'wcct_settings' => self::v1( 'all-on' ), 'wcct_migration_lock' => ( time() - 3600 ) . '|crashed' ) )['after_request'];

        $this->assertSame( $stale['expected'], $stale['v3'] );
        $this->assertNull( $stale['lock'] );
    }

    public function test_a_failed_migration_keeps_the_2x_settings_and_retry_finishes_it() {
        $out = $this->state( array( 'wcct_settings' => self::v1( 'x-pro-labels' ) ), array( 'fail' => true ) );

        $this->assertSame( 'failed', $out['failed_status'] );
        $this->assertNull( $out['after_failure']['v3'] );
        $this->assertNotNull( $out['after_failure']['lock'] );
        $this->assertSame( 'Simulated failure', $out['after_failure']['error']['message'] );
        $this->assertSame( 'locked', $out['locked_status'] );

        $this->assertStringContainsString( 'Retry migration', $out['notice_admin'] );
        $this->assertStringContainsString( 'Error: Simulated failure', $out['notice_admin'] );
        $this->assertStringContainsString( 'name="_wpnonce"', $out['notice_admin'] );
        $this->assertSame( '', $out['notice_customer'] );

        // Without the capability or the nonce nothing runs.
        $this->assertStringStartsWith( 'wp_die:', $out['handle_customer'] );
        $this->assertStringStartsWith( 'wp_die:', $out['handle_bad_nonce'] );
        $this->assertNull( $out['after_refused']['v3'] );

        $this->assertStringStartsWith( 'Redirect (302)', $out['handle_admin'] );
        $this->assertSame( $out['after_retry']['expected'], $out['after_retry']['v3'] );
        $this->assertNull( $out['after_retry']['lock'] );
        $this->assertNull( $out['after_retry']['error'] );
        $this->assertSame( $out['after_retry']['version'], $out['after_retry']['marker'] );
        $this->assertSame( '', $out['notice_after'] );
    }

    public function test_a_platform_resumed_after_the_migration_prints_again_after_a_2x_option_write() {
        $v1 = self::v1( 'off-with-ids' );

        // Migrated: the switched-off integrations are paused, so nothing prints.
        $paused = $this->output_of( $this->request_page( 'product-simple', $v1 ) );

        // Resume Meta (Pause::set), then write an unrelated field to the 2.x option.
        $out   = $this->state( array( 'wcct_settings' => $v1 ), array( 'resume' => 'facebook' ) );
        $saved = $out['after_save'];

        $this->assertEqualsCanonicalizing( array( 'google', 'custom' ), $out['after_resume']['paused'] );
        $this->assertEqualsCanonicalizing( array( 'google', 'custom' ), $saved['paused'] );

        $resumed = $this->output_of(
            $this->request_page(
                'product-simple',
                $saved['v1'],
                array(
                    'wcct_settings_v3'      => $saved['v3'],
                    'wcct_migration_done'   => $saved['version'],
                    'wcct_paused_platforms' => $saved['paused'],
                )
            )
        );

        $this->assertStringNotContainsString( "fbq('init', '123456789012345'", $paused );
        $this->assertStringContainsString( "fbq('init', '123456789012345'", $resumed );
        $this->assertStringNotContainsString( 'G-ABCDE12345', $resumed );
    }

    /**
     * 2.x fixture and page.
     *
     * @return array
     */
    public function fixtures_and_pages() {
        $cases = array();

        foreach ( self::FIXTURES as $fixture ) {
            foreach ( self::PAGES as $page ) {
                $cases[ "$fixture / $page" ] = array( $fixture, $page );
            }
        }

        return $cases;
    }

    /**
     * @dataProvider fixtures_and_pages
     */
    public function test_the_storefront_prints_the_same_before_and_after_the_migration( $fixture, $page ) {
        $v1 = self::v1( $fixture );

        // A held lock keeps the request on the read-time view of the 2.x settings.
        $before = $this->output_of( $this->request_page( $page, $v1, array( 'wcct_migration_lock' => time() . '|held' ) ) );
        $after  = $this->output_of( $this->request_page( $page, $v1 ) );

        $this->assertSame( $before, $after );
    }
}
