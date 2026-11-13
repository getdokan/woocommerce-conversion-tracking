<?php

require_once dirname( __DIR__ ) . '/Fakes/doctor-functions.php';

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Migration\Flags;
use StorePulse\ConversionTracking\Migration\SettingsMigration;
use StorePulse\ConversionTracking\Migration\WhatsNew;
use StorePulse\ConversionTracking\Settings\Schema;
use StorePulse\ConversionTracking\Onboarding\Redirect;

/**
 * Tests for the What's new page data (#124)
 */
class WhatsNewTest extends TestCase {

    const LABELS = array(
        'facebook' => 'Meta',
        'google'   => 'Google',
        'twitter'  => 'X',
        'custom'   => 'Custom script',
    );

    const GOOGLE_FLAG = array(
        'platform'       => 'google',
        'fields'         => array( 'ads_id', 'purchase_label' ),
        'saved'          => 'AW-123456789/AbC-dEf_12',
        'ads_id'         => 'AW-123456789',
        'purchase_label' => 'AbC-dEf_12',
    );

    const X_FLAG = array(
        'platform' => 'twitter',
        'fields'   => array( 'purchase_event_id', 'cart_event_id' ),
        'events'   => array( 'purchase', 'add_to_cart' ),
    );

    protected function setUp(): void {
        $GLOBALS['wcct_test_options'] = array();
    }

    protected function tearDown(): void {
        unset( $GLOBALS['wcct_test_options'], $GLOBALS['wcct_test_filters']['wcct_settings_schema'] );
    }

    private static function fixture( string $name ): array {
        $fixtures = require dirname( __DIR__, 2 ) . '/fixtures/settings-2x.php';

        return $fixtures[ $name ];
    }

    // Pro unlocks X's Add to cart, Start checkout and Registration.
    private static function unlock_x() {
        $GLOBALS['wcct_test_filters']['wcct_settings_schema'] = function ( $schema ) {
            $schema['platforms']['twitter']['schema'] = Schema::unlock_options( $schema['platforms']['twitter']['schema'], 'events' );

            return $schema;
        };
    }

    private static function doc( array $platforms ): array {
        return array( 'platforms' => $platforms );
    }

    private function upgraded( array $more = array() ) {
        $GLOBALS['wcct_test_options'] = array_merge(
            array(
                'wcct_settings'      => self::fixture( 'all-on' ),
                'wcct_install_type'  => 'upgrade',
                'wcct_pre3_snapshot' => array( 'wcct_settings' => self::fixture( 'all-on' ) ),
                'wcct_settings_v3'   => array( 'platforms' => array() ),
            ),
            $more
        );
    }

    public function test_no_flags_is_done() {
        $this->assertSame( array(), WhatsNew::attention( array(), self::doc( array() )) );
    }

    public function test_the_google_split_links_to_the_ads_id_until_its_fields_change() {
        $doc   = self::doc( array( 'google' => array( 'ads_id' => 'AW-123456789', 'purchase_label' => 'AbC-dEf_12' ) ) );
        $items = WhatsNew::attention( array( Flags::GOOGLE_ADS_ID_SPLIT => self::GOOGLE_FLAG ), $doc );

        $this->assertCount( 1, $items );
        $this->assertSame( 'google_ads_id_split', $items[0]['id'] );
        $this->assertSame( 'We split your Google Ads ID into a conversion ID and a label.', $items[0]['title'] );
        $this->assertSame( 'Check it looks right.', $items[0]['text'] );
        $this->assertSame( 'Check Google', $items[0]['action'] );
        $this->assertSame( '#/platforms/google?field=ads_id', $items[0]['url'] );
        $this->assertTrue( $items[0]['confirmable'], 'A split that was right needs no change, so "Looks right" closes it' );

        // The merchant fixed the label: nothing left to check.
        $doc['platforms']['google']['purchase_label'] = 'NewLabel';

        $this->assertSame( array(), WhatsNew::attention( array( Flags::GOOGLE_ADS_ID_SPLIT => self::GOOGLE_FLAG ), $doc ) );
    }

    public function test_the_x_item_reads_the_live_settings_and_closes_once_every_event_has_an_id() {
        self::unlock_x();

        $flags = array( Flags::X_EVENT_IDS => self::X_FLAG );
        $x     = array(
            'pixel_id'          => 'o8x2k',
            'events'            => array( 'page_view', 'purchase', 'add_to_cart' ),
            'needs_resetup'     => array( 'purchase', 'add_to_cart' ),
            'purchase_event_id' => '',
            'cart_event_id'     => '',
        );

        $items = WhatsNew::attention( $flags, self::doc( array( 'twitter' => $x ) ) );

        $this->assertSame( 'X retired the old event codes.', $items[0]['title'] );
        $this->assertSame( '2 events need a new event ID each.', $items[0]['text'] );
        $this->assertSame( 'Set up X', $items[0]['action'] );
        $this->assertSame( '#/platforms/twitter?field=purchase_event_id', $items[0]['url'] );

        // Add to cart got its ID: only Purchase is left, in the prototype's words.
        $x['cart_event_id'] = 'tw-o8x2k-abc12';
        $items              = WhatsNew::attention( $flags, self::doc( array( 'twitter' => $x ) ) );

        $this->assertSame( array( 'purchase_event_id' ), $items[0]['fields'] );
        $this->assertSame( 'Purchases need a new event ID.', $items[0]['text'] );

        $this->assertArrayNotHasKey( 'confirmable', $items[0], 'X needs its ID; nothing to confirm' );

        // The flag option is stale now; the live settings win.
        $x['purchase_event_id'] = 'tw-o8x2k-def34';

        $this->assertSame( array(), WhatsNew::attention( $flags, self::doc( array( 'twitter' => $x ) ) ) );
    }

    public function test_free_leaves_out_the_x_events_it_locks_as_the_x_page_does() {
        $x = array(
            'pixel_id'          => 'o8x2k',
            'events'            => array( 'page_view', 'purchase', 'add_to_cart' ),
            'needs_resetup'     => array( 'purchase', 'add_to_cart' ),
            'purchase_event_id' => '',
            'cart_event_id'     => '',
        );

        $items = WhatsNew::attention( array( Flags::X_EVENT_IDS => self::X_FLAG ), self::doc( array( 'twitter' => $x ) ) );

        $this->assertSame( array( 'purchase_event_id' ), $items[0]['fields'] );
        $this->assertSame( 'Purchases need a new event ID.', $items[0]['text'] );

        // Only a Pro event left: nothing Free can set up.
        $x['purchase_event_id'] = 'tw-o8x2k-def34';

        $this->assertSame( array(), WhatsNew::attention( array( Flags::X_EVENT_IDS => self::X_FLAG ), self::doc( array( 'twitter' => $x ) ) ) );
    }

    public function test_an_x_event_switched_off_since_needs_no_id() {
        self::unlock_x();

        $x = array(
            'pixel_id'          => 'o8x2k',
            'events'            => array( 'page_view', 'add_to_cart' ),
            'needs_resetup'     => array( 'purchase', 'add_to_cart' ),
            'purchase_event_id' => '',
            'cart_event_id'     => '',
        );

        $items = WhatsNew::attention( array( Flags::X_EVENT_IDS => self::X_FLAG ), self::doc( array( 'twitter' => $x ) ) );

        $this->assertSame( array( 'cart_event_id' ), $items[0]['fields'] );

        $x['events'] = array( 'page_view' );

        $this->assertSame( array(), WhatsNew::attention( array( Flags::X_EVENT_IDS => self::X_FLAG ), self::doc( array( 'twitter' => $x ) ) ) );
    }

    public function test_looks_right_closes_an_item_and_survives_the_dismissal() {
        $this->upgraded( array( 'wcct_migration_flags' => array( Flags::GOOGLE_ADS_ID_SPLIT => self::GOOGLE_FLAG ) ) );

        $items = array(
            array( 'id' => 'google_ads_id_split' ),
            array( 'id' => 'twitter_event_ids' ),
        );

        WhatsNew::confirm( 'google_ads_id_split' );
        WhatsNew::confirm( 'google_ads_id_split' );

        $this->assertSame( array( 'google_ads_id_split' ), WhatsNew::confirmed() );
        $this->assertSame( array( array( 'id' => 'twitter_event_ids' ) ), WhatsNew::open_items( $items, WhatsNew::confirmed() ) );

        WhatsNew::dismiss( 1700000000 );

        $this->assertSame( array( 'google_ads_id_split' ), WhatsNew::confirmed() );
        $this->assertTrue( WhatsNew::is_dismissed() );
    }

    public function test_other_flags_are_left_to_the_filter_and_malformed_ones_are_skipped() {
        $flags = array(
            // Pro's (#123): Pro adds their items through wcct_whats_new_attention, so without Pro they don't show.
            'facebook_pixels'           => array( 'platform' => 'facebook', 'fields' => array( 'pixels' ) ),
            'catalog_feed_login'        => array( 'platform' => 'catalog', 'fields' => array( 'login' ) ),
            Flags::GOOGLE_ADS_ID_SPLIT  => 'not an array',
            Flags::X_EVENT_IDS          => array( 'fields' => array( 'purchase_event_id' ) ),
        );

        $this->assertSame( array(), WhatsNew::attention( $flags, self::doc( array() ) ) );
    }

    public function test_platforms_2x_switched_off_list_while_still_paused() {
        $v1     = self::fixture( 'off-with-ids' );
        $paused = WhatsNew::paused( $v1, array( 'facebook', 'google', 'custom' ), self::LABELS );

        $this->assertSame( array( 'facebook', 'google', 'custom' ), array_column( $paused, 'id' ) );
        $this->assertSame( array( 'Meta', 'Google', 'Custom script' ), array_column( $paused, 'label' ) );
        $this->assertSame( '#/platforms/custom', $paused[2]['url'] );

        // Meta was resumed; Twitter was paused later, by hand, so it isn't the update's doing.
        $this->assertSame( array( 'google', 'custom' ), array_column( WhatsNew::paused( $v1, array( 'google', 'custom', 'twitter' ), self::LABELS ), 'id' ) );
    }

    public function test_one_google_integration_switched_off_lists_by_its_own_name() {
        $paused = WhatsNew::paused( self::fixture( 'ads-only' ), array( 'ga4' ), self::LABELS );

        $this->assertSame( array( 'ga4' ), array_column( $paused, 'id' ) );
        $this->assertSame( array( 'google', 'Google Analytics' ), array( $paused[0]['platform'], $paused[0]['label'] ) );
        $this->assertSame( array(), WhatsNew::paused( 'not an array', array( 'ga4' ), self::LABELS ) );
    }

    public function test_extra_pixels_count_only_pro_indexes_with_an_id() {
        $v1 = array(
            'facebook' => array(
                'enabled' => true,
                0         => array( 'pixel_id' => '111' ),
                1         => array( 'pixel_id' => '222' ),
                3         => array( 'pixel_id' => ' ' ),
                4         => array( 'pixel_id' => '444' ),
                'x'       => array( 'pixel_id' => '555' ),
            ),
        );

        $this->assertSame( 2, WhatsNew::extra_pixels( $v1 ) );
        $this->assertSame( 0, WhatsNew::extra_pixels( self::fixture( 'all-on' ) ) );
        $this->assertSame( 0, WhatsNew::extra_pixels( null ) );
    }

    public function test_available_only_after_an_upgrade_migration() {
        $this->assertFalse( WhatsNew::is_available(), 'Fresh install' );

        $this->upgraded();
        $this->assertTrue( WhatsNew::is_available() );

        // The migration hasn't written the 3.0 settings (it failed): the Retry notice owns this state.
        unset( $GLOBALS['wcct_test_options']['wcct_settings_v3'] );
        $this->assertFalse( WhatsNew::is_available() );

        $this->upgraded( array( 'wcct_pre3_snapshot' => array() ) );
        $this->assertFalse( WhatsNew::is_available(), 'No snapshot' );

        foreach ( array( 'lite', 'pro' ) as $source ) {
            $this->upgraded( array( 'wcct_migration_error' => array( 'message' => 'Boom', 'time' => 1700000000, 'source' => $source ) ) );
            $this->assertFalse( WhatsNew::is_available(), "The $source migration failed" );
        }

        $this->upgraded( array( 'wcct_install_type' => 'new' ) );
        $this->assertFalse( WhatsNew::is_available(), 'Recorded as new' );
    }

    public function test_dismiss_hides_the_banner_and_uses_up_the_redirect() {
        $this->upgraded( array( 'wcct_migration_flags' => array( Flags::X_EVENT_IDS => self::X_FLAG ) ) );
        Redirect::queue_whats_new();

        $this->assertFalse( WhatsNew::is_dismissed() );
        $this->assertTrue( Redirect::whats_new_pending() );

        WhatsNew::dismiss( 1700000000 );

        $this->assertSame( array( 'dismissed' => 1700000000, 'flags' => array( 'twitter_event_ids' ), 'confirmed' => array() ), $GLOBALS['wcct_test_options']['wcct_whats_new'] );
        $this->assertSame( 'done', $GLOBALS['wcct_test_options']['wcct_onboarding_redirect'] );
        $this->assertTrue( WhatsNew::is_dismissed( array( 'twitter_event_ids' ) ) );
    }

    public function test_a_flag_that_appears_after_the_dismissal_shows_it_again() {
        $this->upgraded( array( 'wcct_migration_flags' => array( Flags::X_EVENT_IDS => self::X_FLAG ) ) );
        WhatsNew::dismiss( 1700000000 );

        // Pro 3.0 updated after Free and its migration added a flag.
        $this->assertFalse( WhatsNew::is_dismissed( array( 'twitter_event_ids', 'catalog_feed_login' ) ) );

        // A dismissal from before flags were tracked counts every open item as new.
        $GLOBALS['wcct_test_options']['wcct_whats_new'] = array( 'dismissed' => 1700000000 );

        $this->assertTrue( WhatsNew::is_dismissed() );
        $this->assertFalse( WhatsNew::is_dismissed( array( 'twitter_event_ids' ) ) );
    }

    public function test_the_migration_queues_the_redirect_for_upgraders_only() {
        $GLOBALS['wcct_test_options'] = array(
            'wcct_settings'     => self::fixture( 'all-on' ),
            'wcct_install_type' => 'upgrade',
        );

        ( new SettingsMigration() )->run();

        $this->assertSame( 'whats_new', $GLOBALS['wcct_test_options']['wcct_onboarding_redirect'] );
        $this->assertTrue( WhatsNew::is_available() );

        $GLOBALS['wcct_test_options'] = array(
            'wcct_settings'     => self::fixture( 'all-on' ),
            'wcct_install_type' => 'new',
        );

        ( new SettingsMigration() )->run();

        $this->assertArrayNotHasKey( 'wcct_onboarding_redirect', $GLOBALS['wcct_test_options'] );
        $this->assertFalse( WhatsNew::is_available() );
    }
}
