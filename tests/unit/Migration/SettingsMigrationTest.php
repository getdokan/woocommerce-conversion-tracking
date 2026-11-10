<?php

require_once dirname( __DIR__ ) . '/Fakes/GlobalsOptionStore.php';

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Migration\Flags;
use StorePulse\ConversionTracking\Migration\SettingsMigration;
use StorePulse\ConversionTracking\Migration\Trigger;

/**
 * Tests for the 2.x to 3.0 settings migration (#122)
 */
class SettingsMigrationTest extends TestCase {

    const DEFAULTS = array(
        'value_mode'      => 'all',
        'product_id_mode' => 'id',
        'skip_roles'      => 'none',
    );

    const X_IDS = array(
        'purchase_event_id' => '',
        'cart_event_id'     => '',
        'checkout_event_id' => '',
        'signup_event_id'   => '',
    );

    protected function setUp(): void {
        $GLOBALS['wcct_test_options'] = array();
    }

    protected function tearDown(): void {
        unset( $GLOBALS['wcct_test_options'], $GLOBALS['wcct_test_filters'] );
    }

    private static function fixture( string $name ): array {
        $fixtures = require dirname( __DIR__, 2 ) . '/fixtures/settings-2x.php';

        return $fixtures[ $name ];
    }

    private function upgrader( $v1, array $more = array() ) {
        $GLOBALS['wcct_test_options'] = array_merge(
            array(
                'wcct_settings'     => $v1,
                'wcct_install_type' => 'upgrade',
            ),
            $more
        );
    }

    private function x( array $values ): array {
        return array_merge( array( 'pixel_id' => 'o8x2k' ), $values );
    }

    /**
     * 2.x fixture => expected platforms and flags after the migration.
     *
     * @return array
     */
    public function mapping_cases(): array {
        $google = array(
            'measurement_id' => '',
            'ads_id'         => 'AW-123456789',
            'purchase_label' => 'AbC-dEf_12',
            'mode'           => 'ads',
            'events'         => array( 'purchase' ),
        );
        $split  = array(
            'platform' => 'google',
            'fields'   => array( 'ads_id', 'purchase_label' ),
        );

        return array(
            'meta, google, x and custom all on'     => array(
                'all-on',
                array(
                    'facebook' => array( 'pixel_id' => '123456789012345', 'events' => array( 'add_to_cart', 'begin_checkout', 'purchase', 'sign_up' ) ) + self::DEFAULTS,
                    'google'   => array(
                        'measurement_id' => 'G-ABCDE12345',
                        'ads_id'         => 'AW-123456789',
                        'purchase_label' => 'AbCdEfGh12',
                        'mode'           => 'both',
                        'events'         => array( 'view_product', 'add_to_cart', 'begin_checkout', 'purchase' ),
                    ) + self::DEFAULTS,
                    'twitter'  => $this->x( array( 'events' => array( 'page_view', 'purchase' ) ) + self::X_IDS + array( 'needs_resetup' => array( 'purchase' ) ) ) + self::DEFAULTS,
                    'custom'   => array(
                        'thankyou_enabled' => true,
                        'thankyou_script'  => "<script>ct('{order_number}', '{order_total}', '{customer_email}');</script>",
                        'signup_enabled'   => true,
                        'signup_script'    => "<script>ct('signup');</script>",
                        'customer_details' => true,
                    ),
                ),
                array( Flags::X_EVENT_IDS => array( 'platform' => 'twitter', 'fields' => array( 'purchase_event_id' ), 'events' => array( 'purchase' ) ) ),
            ),
            'meta alone, the rest saved off'        => array(
                'meta-only',
                array(
                    'facebook' => array( 'pixel_id' => '123456789012345', 'events' => array( 'add_to_cart', 'purchase' ) ) + self::DEFAULTS,
                    'google'   => array( 'measurement_id' => '', 'ads_id' => '', 'purchase_label' => '', 'mode' => 'both', 'events' => array() ) + self::DEFAULTS,
                    'twitter'  => array( 'pixel_id' => '', 'events' => array( 'page_view' ) ) + self::X_IDS + array( 'needs_resetup' => array() ) + self::DEFAULTS,
                    'custom'   => array( 'thankyou_enabled' => false, 'thankyou_script' => '', 'signup_enabled' => false, 'signup_script' => '', 'customer_details' => false ),
                ),
                array(),
            ),
            'ga4 with search and sign_up'           => array(
                'ga4-only',
                array( 'google' => array( 'measurement_id' => 'G-ABCDE12345', 'ads_id' => '', 'purchase_label' => '', 'mode' => 'analytics', 'events' => array( 'purchase', 'search', 'sign_up' ) ) + self::DEFAULTS ),
                array(),
                array( 'google' ),
            ),
            'ads on, ga4 off with its id kept'      => array(
                'ads-only',
                // Both IDs saved: mode Both, with GA4 paused alone (see the pause table).
                array( 'google' => array( 'measurement_id' => 'G-OLD0000001', 'ads_id' => 'AW-123456789', 'purchase_label' => 'AbCdEfGh12', 'mode' => 'both', 'events' => array( 'purchase' ) ) + self::DEFAULTS ),
                array(),
                array( 'google' ),
            ),
            'AW-x/label in the account id'          => array(
                'ads-id-with-label',
                array( 'google' => $google + self::DEFAULTS ),
                array( Flags::GOOGLE_ADS_ID_SPLIT => $split + array( 'saved' => 'AW-123456789/AbC-dEf_12', 'ads_id' => 'AW-123456789', 'purchase_label' => 'AbC-dEf_12' ) ),
            ),
            'AW-x/label and a typed label'          => array(
                'ads-id-and-label',
                array( 'google' => array_merge( $google, array( 'ads_id' => 'AW-987654321', 'purchase_label' => 'NewLabel' ) ) + self::DEFAULTS ),
                array( Flags::GOOGLE_ADS_ID_SPLIT => $split + array( 'saved' => 'aw-987654321 / OldLabel', 'ads_id' => 'AW-987654321', 'purchase_label' => 'NewLabel' ) ),
            ),
            'AW-x/label in the label field'         => array(
                'ads-label-with-id',
                array( 'google' => array_merge( $google, array( 'ads_id' => 'AW-555555555', 'purchase_label' => 'Lbl55' ) ) + self::DEFAULTS ),
                array( Flags::GOOGLE_ADS_ID_SPLIT => $split + array( 'saved' => 'AW-555555555', 'ads_id' => 'AW-555555555', 'purchase_label' => 'Lbl55' ) ),
            ),
            'x with pro 1.x labels'                 => array(
                'x-pro-labels',
                array( 'twitter' => $this->x( array( 'events' => array( 'page_view', 'purchase', 'add_to_cart', 'sign_up' ) ) + self::X_IDS + array( 'needs_resetup' => array( 'purchase', 'add_to_cart', 'sign_up' ) ) ) + self::DEFAULTS ),
                array( Flags::X_EVENT_IDS => array( 'platform' => 'twitter', 'fields' => array( 'purchase_event_id', 'cart_event_id', 'signup_event_id' ), 'events' => array( 'purchase', 'add_to_cart', 'sign_up' ) ) ),
            ),
            'x switched off keeps its tag, paused'  => array(
                'x-off-with-tag',
                array( 'twitter' => $this->x( array( 'events' => array( 'page_view', 'purchase' ) ) + self::X_IDS + array( 'needs_resetup' => array( 'purchase' ) ) ) + self::DEFAULTS ),
                array( Flags::X_EVENT_IDS => array( 'platform' => 'twitter', 'fields' => array( 'purchase_event_id' ), 'events' => array( 'purchase' ) ) ),
            ),
            'x tag without purchase: no flag'       => array(
                'x-tag-only',
                array( 'twitter' => $this->x( array( 'events' => array( 'page_view' ) ) + self::X_IDS + array( 'needs_resetup' => array() ) ) + self::DEFAULTS ),
                array(),
            ),
            'switched off with ids: paused'         => array(
                'off-with-ids',
                array(
                    'facebook' => array( 'pixel_id' => '123456789012345', 'events' => array( 'purchase' ) ) + self::DEFAULTS,
                    'google'   => array( 'measurement_id' => 'G-ABCDE12345', 'ads_id' => 'AW-123456789', 'purchase_label' => 'AbCdEfGh12', 'mode' => 'both', 'events' => array( 'purchase' ) ) + self::DEFAULTS,
                    // Paused rather than switched off, so Resume prints the saved script.
                    'custom'   => array( 'thankyou_enabled' => true, 'thankyou_script' => '<script>ct()</script>', 'signup_enabled' => false, 'signup_script' => '', 'customer_details' => false ),
                ),
                array(),
            ),
            'string switches'                       => array(
                'string-switches',
                array(
                    'facebook' => array( 'pixel_id' => '123456789012345', 'events' => array( 'purchase' ) ) + self::DEFAULTS,
                    // Ads saved 'on' was off in 2.x: mode from the IDs, Ads paused alone.
                    'google'   => array( 'measurement_id' => 'G-ABCDE12345', 'ads_id' => 'AW-123456789', 'purchase_label' => 'AbCdEfGh12', 'mode' => 'both', 'events' => array( 'purchase' ) ) + self::DEFAULTS,
                    'twitter'  => $this->x( array( 'events' => array( 'page_view', 'purchase' ) ) + self::X_IDS + array( 'needs_resetup' => array( 'purchase' ) ) ) + self::DEFAULTS,
                ),
                array( Flags::X_EVENT_IDS => array( 'platform' => 'twitter', 'fields' => array( 'purchase_event_id' ), 'events' => array( 'purchase' ) ) ),
            ),
            'on with empty ids'                     => array(
                'on-with-empty-ids',
                array(
                    'facebook' => array( 'pixel_id' => '', 'events' => array( 'purchase' ) ) + self::DEFAULTS,
                    'google'   => array( 'measurement_id' => '', 'ads_id' => '', 'purchase_label' => '', 'mode' => 'both', 'events' => array() ) + self::DEFAULTS,
                    'twitter'  => array( 'pixel_id' => '', 'events' => array( 'page_view' ) ) + self::X_IDS + array( 'needs_resetup' => array() ) + self::DEFAULTS,
                    'custom'   => array( 'thankyou_enabled' => false, 'thankyou_script' => '', 'signup_enabled' => false, 'signup_script' => '', 'customer_details' => false ),
                ),
                array(),
            ),
            'customer placeholder in sign-up only'  => array(
                'custom-signup-placeholder',
                // 2.x filled customer placeholders in the thank-you script only.
                array( 'custom' => array( 'thankyou_enabled' => true, 'thankyou_script' => "<script>ct('{order_total}');</script>", 'signup_enabled' => true, 'signup_script' => "<script>ct('{customer_id}');</script>", 'customer_details' => false ) ),
                array(),
            ),
            'odd slashes: no split'                 => array(
                'ads-odd-slashes',
                array( 'google' => array( 'measurement_id' => '', 'ads_id' => 'AW-123456789/', 'purchase_label' => 'abc/def', 'mode' => 'ads', 'events' => array( 'purchase' ) ) + self::DEFAULTS ),
                array(),
            ),
            'flat 2.0 scripts kept, switched off'   => array(
                'flat-2-0',
                array( 'custom' => array( 'thankyou_enabled' => false, 'thankyou_script' => "<script>ct('{order_total}', '{customer_email}');</script>", 'signup_enabled' => false, 'signup_script' => '<script>reg()</script>', 'customer_details' => true ) ),
                array(),
            ),
            'order placeholders only'               => array(
                'custom-no-customer',
                array( 'custom' => array( 'thankyou_enabled' => true, 'thankyou_script' => "<script>ct('{order_number}', '{order_total}', '{used_coupons}');</script>", 'signup_enabled' => false, 'signup_script' => '', 'customer_details' => false ) ),
                array(),
            ),
            'pro 1.x keys lite leaves to pro'       => array(
                'pro-shapes',
                array(
                    'facebook' => array( 'pixel_id' => '123456789012345', 'events' => array( 'view_product', 'purchase', 'search' ) ) + self::DEFAULTS,
                    'google'   => array( 'measurement_id' => '', 'ads_id' => 'AW-123456789', 'purchase_label' => 'AbCdEfGh12', 'registration_label' => 'RegLbl1', 'mode' => 'ads', 'events' => array( 'purchase', 'sign_up' ) ) + self::DEFAULTS,
                ),
                array(),
            ),
            'malformed values never fatal'          => array(
                'malformed',
                array(
                    'google'  => array( 'measurement_id' => '', 'ads_id' => '', 'purchase_label' => '', 'mode' => 'both', 'events' => array() ) + self::DEFAULTS,
                    'twitter' => array( 'pixel_id' => '', 'events' => array( 'page_view' ) ) + self::X_IDS + array( 'needs_resetup' => array() ) + self::DEFAULTS,
                    'custom'  => array( 'thankyou_enabled' => false, 'thankyou_script' => '', 'signup_enabled' => true, 'signup_script' => '5', 'customer_details' => false ),
                ),
                array(),
            ),
            'empty option'                          => array( 'empty', array(), array() ),
        );
    }

    /**
     * @dataProvider mapping_cases
     */
    public function test_2x_settings_map_to_3_0( string $fixture, array $platforms, array $flags, array $only = array() ) {
        $result = SettingsMigration::map( self::fixture( $fixture ), true );

        $actual = $only ? array_intersect_key( $result['settings']['platforms'], array_flip( $only ) ) : $result['settings']['platforms'];

        $this->assertSame( $platforms, $actual );
        $this->assertSame( $flags, $result['flags'] );
    }

    public function test_every_fixture_maps_to_a_platforms_object() {
        foreach ( require dirname( __DIR__, 2 ) . '/fixtures/settings-2x.php' as $name => $v1 ) {
            $this->assertIsArray( SettingsMigration::map( $v1, true )['settings']['platforms'], $name );
        }

        $this->assertSame( array( 'platforms' => array() ), SettingsMigration::map( 'not an array', true )['settings'] );
        $this->assertSame( array( 'platforms' => array() ), SettingsMigration::map( null, true )['settings'] );
    }

    public function test_upgraders_keep_the_order_total_on_meta_ads_and_x_and_ga4_has_no_mode() {
        $platforms = SettingsMigration::map( self::fixture( 'all-on' ), true )['settings']['platforms'];

        foreach ( array( 'facebook', 'google', 'twitter' ) as $platform ) {
            $this->assertSame( 'all', $platforms[ $platform ]['value_mode'], $platform );
        }

        // Google's mode is the Ads one; GA4 always sends the item total.
        $this->assertSame( 'items', \StorePulse\ConversionTracking\Events\ValueMode::for_platform( 'ga4' ) );
        $this->assertArrayNotHasKey( 'value_mode', $platforms['custom'] );
    }

    public function test_upgraders_send_the_product_id_and_skip_no_role() {
        $platforms = SettingsMigration::map( self::fixture( 'all-on' ), true )['settings']['platforms'];

        foreach ( array( 'facebook', 'google', 'twitter' ) as $platform ) {
            $this->assertSame( 'id', $platforms[ $platform ]['product_id_mode'], $platform );
            $this->assertSame( 'none', $platforms[ $platform ]['skip_roles'], $platform );
        }

        $this->assertArrayNotHasKey( 'skip_roles', $platforms['custom'] );
    }

    public function test_a_view_filter_that_already_set_a_default_keeps_it() {
        $GLOBALS['wcct_test_filters']['wcct_settings_v1_view'] = function ( $view ) {
            $view['platforms']['bing_ads'] = array( 'tag_id' => '12345678', 'events' => array( 'purchase' ) );
            $view['platforms']['facebook']['value_mode'] = 'items';

            return $view;
        };

        $platforms = SettingsMigration::map( self::fixture( 'pro-shapes' ), true )['settings']['platforms'];

        $this->assertSame( 'items', $platforms['facebook']['value_mode'] );
        $this->assertSame( array( 'tag_id' => '12345678', 'events' => array( 'purchase' ) ) + self::DEFAULTS, $platforms['bing_ads'] );
    }

    public function test_a_new_install_gets_no_upgrade_defaults_and_no_flags() {
        $result = SettingsMigration::map( self::fixture( 'all-on' ), false );

        $this->assertSame( array(), $result['flags'] );
        $this->assertArrayNotHasKey( 'value_mode', $result['settings']['platforms']['facebook'] );
        $this->assertArrayNotHasKey( 'skip_roles', $result['settings']['platforms']['google'] );
    }

    public function test_run_writes_v3_snapshot_flags_and_cutoff_and_leaves_v1_untouched() {
        $v1 = self::fixture( 'all-on' );
        $this->upgrader(
            $v1,
            array(
                'wcct_permissions' => array( 'shop_manager' => '1' ),
                'wcct_data_feed'   => array( 'types' => array( 'simple' ), 'authenticate' => array( 'wcct-authenticate-enable' => 'on', 'username' => 'meta', 'password' => 'plain' ) ),
            )
        );
        $before = serialize( $GLOBALS['wcct_test_options']['wcct_settings'] ); // phpcs:ignore

        ( new SettingsMigration() )->run();

        $options = $GLOBALS['wcct_test_options'];

        $this->assertSame( $before, serialize( $options['wcct_settings'] ) ); // phpcs:ignore
        $this->assertSame( SettingsMigration::map( $v1, true )['settings'], $options['wcct_settings_v3'] );
        $this->assertSame( array( 'wcct_settings', 'wcct_data_feed', 'wcct_permissions' ), array_keys( $options['wcct_pre3_snapshot'] ) );
        $this->assertSame( $v1, $options['wcct_pre3_snapshot']['wcct_settings'] );
        $this->assertSame( array( 'shop_manager' => '1' ), $options['wcct_pre3_snapshot']['wcct_permissions'] );
        // The legacy feed password is never copied.
        $this->assertSame( array( 'wcct-authenticate-enable' => 'on', 'username' => 'meta' ), $options['wcct_pre3_snapshot']['wcct_data_feed']['authenticate'] );
        $this->assertSame( array( Flags::X_EVENT_IDS ), array_keys( $options['wcct_migration_flags'] ) );
        $this->assertIsInt( $options['wcct_count_once_since'] );
    }

    public function test_the_snapshot_holds_only_options_that_exist() {
        $this->upgrader( self::fixture( 'meta-only' ) );

        ( new SettingsMigration() )->run();

        $this->assertSame( array( 'wcct_settings' ), array_keys( $GLOBALS['wcct_test_options']['wcct_pre3_snapshot'] ) );
        $this->assertArrayNotHasKey( 'wcct_migration_flags', $GLOBALS['wcct_test_options'] );
    }

    public function test_a_new_install_with_2x_style_settings_gets_no_snapshot_and_no_flags() {
        $this->upgrader( self::fixture( 'x-pro-labels' ), array( 'wcct_install_type' => 'new' ) );

        ( new SettingsMigration() )->run();

        $this->assertArrayNotHasKey( 'wcct_pre3_snapshot', $GLOBALS['wcct_test_options'] );
        $this->assertArrayNotHasKey( 'wcct_migration_flags', $GLOBALS['wcct_test_options'] );
        $this->assertSame( 'o8x2k', $GLOBALS['wcct_test_options']['wcct_settings_v3']['platforms']['twitter']['pixel_id'] );
    }

    public function test_a_rerun_keeps_the_cutoff_and_the_snapshot_and_drops_a_fixed_flag() {
        $this->upgrader(
            self::fixture( 'ads-id-with-label' ),
            array(
                'wcct_count_once_since' => 123,
                'wcct_pre3_snapshot'    => array( 'wcct_settings' => array( 'frozen' => true ) ),
                'wcct_migration_flags'  => array( 'pro_flag' => array( 'platform' => 'bing_ads' ), Flags::X_EVENT_IDS => array( 'platform' => 'twitter' ) ),
            )
        );

        ( new SettingsMigration() )->run();

        $options = $GLOBALS['wcct_test_options'];

        $this->assertSame( 123, $options['wcct_count_once_since'] );
        $this->assertSame( array( 'wcct_settings' => array( 'frozen' => true ) ), $options['wcct_pre3_snapshot'] );
        $this->assertSame( array( 'pro_flag', Flags::GOOGLE_ADS_ID_SPLIT ), array_keys( $options['wcct_migration_flags'] ) );
    }

    public function test_switched_off_integrations_with_ids_are_paused_and_added_to_an_existing_list() {
        $this->upgrader( self::fixture( 'off-with-ids' ) );

        ( new SettingsMigration() )->run();

        $this->assertEqualsCanonicalizing( array( 'facebook', 'google', 'custom' ), $GLOBALS['wcct_test_options']['wcct_paused_platforms'] );

        // An existing list gains the 2.x pauses, so nothing that was off in 2.x starts tracking.
        $this->upgrader( self::fixture( 'off-with-ids' ), array( 'wcct_paused_platforms' => array( 'twitter', 'facebook' ) ) );

        ( new SettingsMigration() )->run();

        $this->assertSame( array( 'twitter', 'facebook', 'google', 'custom' ), $GLOBALS['wcct_test_options']['wcct_paused_platforms'] );

        // Nothing switched off: no list.
        $this->upgrader( self::fixture( 'all-on' ) );

        ( new SettingsMigration() )->run();

        $this->assertArrayNotHasKey( 'wcct_paused_platforms', $GLOBALS['wcct_test_options'] );
    }

    public function test_a_failure_halfway_leaves_the_migration_due_and_a_rerun_finishes_it() {
        $this->upgrader( self::fixture( 'all-on' ) );
        $store = new GlobalsOptionStore();
        $fail  = true;

        // Fails after the snapshot and flags, before the 3.0 settings are written.
        $GLOBALS['wcct_test_filters'][ SettingsMigration::FILTER ] = function ( $settings ) use ( &$fail ) {
            if ( $fail ) {
                throw new RuntimeException( 'disk full' );
            }

            return $settings;
        };

        $this->assertSame( Trigger::FAILED, SettingsMigration::maybe_run( $store ) );

        $options = $GLOBALS['wcct_test_options'];
        $this->assertArrayNotHasKey( 'wcct_settings_v3', $options );
        $this->assertArrayNotHasKey( SettingsMigration::MARKER, $options );
        $this->assertArrayHasKey( 'wcct_pre3_snapshot', $options );
        $this->assertSame( 'disk full', $options[ SettingsMigration::ERROR_OPTION ]['message'] );
        $this->assertTrue( SettingsMigration::is_pending( $store ) );

        // The lock is kept, so the next requests wait for its timeout instead of retrying each time.
        $this->assertSame( Trigger::LOCKED, SettingsMigration::maybe_run( $store ) );

        // The merchant saves on the 2.x page meanwhile; the frozen snapshot keeps what was there first.
        $GLOBALS['wcct_test_options']['wcct_settings']['facebook'][0]['pixel_id'] = '999999999999999';
        $fail = false;

        $this->assertSame( Trigger::RAN, SettingsMigration::retry( $store ) );

        $options = $GLOBALS['wcct_test_options'];
        $this->assertSame( '999999999999999', $options['wcct_settings_v3']['platforms']['facebook']['pixel_id'] );
        $this->assertSame( '123456789012345', $options['wcct_pre3_snapshot']['wcct_settings']['facebook'][0]['pixel_id'] );
        $this->assertArrayHasKey( SettingsMigration::MARKER, $options );
        $this->assertArrayNotHasKey( SettingsMigration::LOCK, $options );
        $this->assertArrayNotHasKey( SettingsMigration::ERROR_OPTION, $options );
        $this->assertSame( Trigger::IDLE, SettingsMigration::maybe_run( $store ) );
    }

    public function test_retry_leaves_a_lock_taken_after_the_failure() {
        $this->upgrader( self::fixture( 'meta-only' ), array( SettingsMigration::ERROR_OPTION => array( 'message' => 'x', 'time' => 1000 ) ) );
        $store = new GlobalsOptionStore();
        $GLOBALS['wcct_test_options'][ SettingsMigration::LOCK ] = time() . '|newer';

        $this->assertSame( Trigger::LOCKED, SettingsMigration::retry( $store ) );
        $this->assertSame( time() . '|newer', $GLOBALS['wcct_test_options'][ SettingsMigration::LOCK ] );
        $this->assertArrayNotHasKey( 'wcct_settings_v3', $GLOBALS['wcct_test_options'] );
    }

    public function test_a_second_request_during_the_migration_skips_it() {
        $this->upgrader( self::fixture( 'all-on' ) );
        $store  = new GlobalsOptionStore();
        $runs   = 0;
        $second = null;

        $GLOBALS['wcct_test_filters'][ SettingsMigration::FILTER ] = function ( $settings ) use ( $store, &$runs, &$second ) {
            $runs++;

            if ( null === $second ) {
                $second = SettingsMigration::maybe_run( $store );
            }

            return $settings;
        };

        $this->assertSame( Trigger::RAN, SettingsMigration::maybe_run( $store ) );
        $this->assertSame( Trigger::LOCKED, $second );
        $this->assertSame( 1, $runs );
    }

    public function test_a_request_that_loses_the_lock_race_after_the_run_finished_does_nothing() {
        $this->upgrader( self::fixture( 'all-on' ) );
        $store = new GlobalsOptionStore();
        $runs  = 0;

        $GLOBALS['wcct_test_filters'][ SettingsMigration::FILTER ] = function ( $settings ) use ( &$runs ) {
            $runs++;

            return $settings;
        };

        // Another request runs the whole migration between this one's check and its lock.
        $store->before_insert = function () use ( $store ) {
            SettingsMigration::maybe_run( $store );
        };

        $this->assertSame( Trigger::IDLE, SettingsMigration::maybe_run( $store ) );
        $this->assertSame( 1, $runs );
    }

    public function test_a_run_that_died_before_its_marker_is_finished_and_its_lock_dropped() {
        $this->upgrader( self::fixture( 'all-on' ), array( 'wcct_settings_v3' => array( 'platforms' => array() ) ) );
        $store = new GlobalsOptionStore();
        $GLOBALS['wcct_test_options'][ SettingsMigration::LOCK ] = ( time() - 30 ) . '|died';

        $this->assertSame( Trigger::IDLE, SettingsMigration::maybe_run( $store ) );
        $this->assertArrayHasKey( SettingsMigration::MARKER, $GLOBALS['wcct_test_options'] );
        $this->assertArrayNotHasKey( SettingsMigration::LOCK, $GLOBALS['wcct_test_options'] );

        // Nothing to heal on a finished store or a fresh install.
        $this->assertFalse( SettingsMigration::heal( $store ) );
        $GLOBALS['wcct_test_options'] = array( 'wcct_settings_v3' => array() );
        $this->assertFalse( SettingsMigration::heal( $store ) );
        $this->assertArrayNotHasKey( SettingsMigration::MARKER, $GLOBALS['wcct_test_options'] );
    }

    public function test_a_fresh_install_runs_nothing() {
        $store = new GlobalsOptionStore();

        $this->assertSame( Trigger::IDLE, SettingsMigration::maybe_run( $store ) );
        $this->assertSame( array(), $GLOBALS['wcct_test_options'] );
    }
}
