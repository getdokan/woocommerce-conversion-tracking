<?php

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Cache\PageCache;
use StorePulse\ConversionTracking\Migration\LegacyPause;
use StorePulse\ConversionTracking\Migration\SettingsMigration;
use StorePulse\ConversionTracking\Platforms\Pause;
use StorePulse\ConversionTracking\Settings\Google;
use StorePulse\ConversionTracking\Settings\LegacySync;

/**
 * Tests for reading 2.x switched-off integrations as pauses once the 3.0 settings exist (#122)
 */
class LegacyPauseTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['wcct_test_options'] = array();
        Pause::reset();
    }

    protected function tearDown(): void {
        unset( $GLOBALS['wcct_test_options'], $GLOBALS['wcct_test_filters'], $GLOBALS['wcct_test_failing_options'], $GLOBALS['wcct_test_actions'], $GLOBALS['wcct_test_hooks'] );
        Pause::reset();
    }

    private static function entry( $enabled, array $first ): array {
        return array( 'enabled' => $enabled, 0 => $first );
    }

    /**
     * 2.x option => pause list entries.
     *
     * @return array
     */
    public function pause_cases(): array {
        $meta  = array( 'pixel_id' => '123456789012345' );
        $ga4   = array( 'measurement_id' => 'G-1' );
        $ads   = array( 'account_id' => 'AW-1' );
        $x_tag = array( 'events' => array( 'universal_tag_id' => 'o8x2k' ) );

        return array(
            'meta off with its id'           => array( array( 'facebook' => self::entry( false, $meta ) ), array( 'facebook' ) ),
            'meta off without an id'         => array( array( 'facebook' => self::entry( false, array( 'pixel_id' => ' ' ) ) ), array() ),
            'meta on'                        => array( array( 'facebook' => self::entry( true, $meta ) ), array() ),
            'meta off as the string false'   => array( array( 'facebook' => self::entry( 'false', $meta ) ), array( 'facebook' ) ),
            'meta on as the string yes'      => array( array( 'facebook' => self::entry( 'yes', $meta ) ), array() ),
            'ga4 and ads both off'           => array( array( 'ga4' => self::entry( false, $ga4 ), 'adwords' => self::entry( false, $ads ) ), array( 'google' ) ),
            'ga4 off, ads never saved'       => array( array( 'ga4' => self::entry( false, $ga4 ) ), array( 'google' ) ),
            'ga4 off, ads off without an id' => array( array( 'ga4' => self::entry( false, $ga4 ), 'adwords' => self::entry( false, array( 'account_id' => '' ) ) ), array( 'google' ) ),
            'ga4 off, ads on'                => array( array( 'ga4' => self::entry( false, $ga4 ), 'adwords' => self::entry( true, $ads ) ), array( 'ga4' ) ),
            'ga4 on, ads off'                => array( array( 'ga4' => self::entry( true, $ga4 ), 'adwords' => self::entry( false, $ads ) ), array( 'adwords' ) ),
            'ga4 on, ads on'                 => array( array( 'ga4' => self::entry( true, $ga4 ), 'adwords' => self::entry( true, $ads ) ), array() ),
            'google off without ids'         => array( array( 'ga4' => self::entry( false, array() ), 'adwords' => self::entry( false, array() ) ), array() ),
            'x off with the universal tag'   => array( array( 'twitter' => self::entry( false, $x_tag ) ), array( 'twitter' ) ),
            'x off with the 3.0 pixel id'    => array( array( 'twitter' => self::entry( false, array( 'pixel_id' => 'o8x2k' ) ) ), array( 'twitter' ) ),
            'x on'                           => array( array( 'twitter' => self::entry( true, $x_tag ) ), array() ),
            'microsoft off with its tag'     => array( array( 'bing_ads' => self::entry( false, array( 'tag_id' => '1234' ) ) ), array( 'bing_ads' ) ),
            'microsoft on'                   => array( array( 'bing_ads' => self::entry( true, array( 'tag_id' => '1234' ) ) ), array() ),
            'custom off with a script'       => array( array( 'custom' => array( 'enabled' => false, 'checkout' => '', 'registration' => '<script>a()</script>' ) ), array( 'custom' ) ),
            'custom off without scripts'     => array( array( 'custom' => array( 'enabled' => false, 'checkout' => ' ', 'registration' => '' ) ), array() ),
            'custom on'                      => array( array( 'custom' => array( 'enabled' => true, 'checkout' => '<script>a()</script>' ) ), array() ),
            'malformed entries'              => array( array( 'facebook' => 'x', 'ga4' => array( 0 => 'x' ), 'twitter' => array( 'enabled' => false, 0 => array( 'events' => 'x' ) ), 'custom' => 'x' ), array() ),
            'not an array'                   => array( 'junk', array() ),
        );
    }

    /**
     * @dataProvider pause_cases
     */
    public function test_switched_off_integrations_with_an_id_are_pauses( $v1, array $expected ) {
        $this->assertSame( $expected, LegacyPause::ids( $v1 ) );
    }

    public function test_only_integrations_2x_had_switched_off_are_paused() {
        foreach ( require dirname( __DIR__, 2 ) . '/fixtures/settings-2x.php' as $name => $v1 ) {
            foreach ( LegacyPause::ids( $v1 ) as $id ) {
                foreach ( isset( LegacyPause::GROUPS[ $id ] ) ? LegacyPause::GROUPS[ $id ] : array( $id ) as $integration ) {
                    $entry = isset( $v1[ $integration ] ) && is_array( $v1[ $integration ] ) ? $v1[ $integration ] : array();

                    $this->assertFalse( Google::enabled( $entry ), "$name: $integration tracked in 2.x" );
                }
            }
        }
    }

    public function test_migrate_resume_and_an_unrelated_2x_save_keep_the_platform_printing() {
        $fixtures = require dirname( __DIR__, 2 ) . '/fixtures/settings-2x.php';
        $v1       = $fixtures['off-with-ids'] + array( 'twitter' => self::entry( true, array( 'pixel_id' => 'o8x2k' ) ) );
        $GLOBALS['wcct_test_options'] = array(
            'wcct_settings'     => $v1,
            'wcct_install_type' => 'upgrade',
        );

        ( new SettingsMigration() )->run();

        $meta = new WCCT_Test_Fake_Integration( 'facebook' );
        $ga4  = new WCCT_Test_Fake_Integration( 'ga4' );

        $meta->settings_value = $v1['facebook'];

        $this->assertEqualsCanonicalizing( array( 'facebook', 'google', 'custom' ), $GLOBALS['wcct_test_options']['wcct_paused_platforms'] );
        $this->assertFalse( $meta->is_active() );
        $this->assertTrue( $ga4->is_paused(), 'a google pause covers ga4' );

        // Resume switches the 2.x integration on as well.
        Pause::set( 'facebook', false );

        $saved                = $GLOBALS['wcct_test_options']['wcct_settings'];
        $meta->settings_value = $saved['facebook'];

        $this->assertSame( '1', $saved['facebook']['enabled'] );
        $this->assertTrue( $meta->is_active() );

        // A 2.x save of an unrelated field keeps it resumed, and Google paused.
        $new                         = $saved;
        $new['twitter'][0]['pixel_id'] = 'o9x3k';
        $GLOBALS['wcct_test_options']['wcct_settings'] = $new;

        LegacySync::sync( $new, array(), true, $saved );
        Pause::reset();

        $this->assertSame( array( 'google', 'custom' ), $GLOBALS['wcct_test_options']['wcct_paused_platforms'] );
        $this->assertTrue( $meta->is_active() );
    }

    public function test_a_2x_switch_reads_as_saved() {
        $meta                 = new WCCT_Test_Fake_Integration( 'facebook' );
        $meta->settings_value = self::entry( false, array( 'pixel_id' => '123456789012345' ) );

        $this->assertFalse( $meta->is_enabled() );
        $this->assertFalse( $meta->is_switched_on() );
    }

    /**
     * Fired actions of one name.
     *
     * @param string $hook Action.
     *
     * @return array
     */
    private static function fired( string $hook ): array {
        return array_values(
            array_filter(
                isset( $GLOBALS['wcct_test_actions'] ) ? $GLOBALS['wcct_test_actions'] : array(),
                function ( $action ) use ( $hook ) {
                    return $hook === $action[0];
                }
            )
        );
    }

    public function test_the_migration_purges_the_page_caches_once_for_all_its_pauses() {
        $fixtures = require dirname( __DIR__, 2 ) . '/fixtures/settings-2x.php';

        // As PageCache::register() hooks it.
        $GLOBALS['wcct_test_hooks'][ Pause::CHANGED_ACTION ] = array(
            function () {
                PageCache::purge();
            },
        );

        foreach ( array( 'off-with-ids' => 1, 'all-on' => 0 ) as $fixture => $purges ) {
            $GLOBALS['wcct_test_actions'] = array();
            $GLOBALS['wcct_test_options'] = array(
                'wcct_settings'     => $fixtures[ $fixture ],
                'wcct_install_type' => 'upgrade',
            );
            Pause::reset();

            ( new SettingsMigration() )->run();

            // wcct_platform_pause_changed still fires for each Pause::set() (the first write also stores the pauses 2.x implied).
            $this->assertCount( 'off-with-ids' === $fixture ? 2 : 0, self::fired( Pause::CHANGED_ACTION ), $fixture );
            $this->assertCount( $purges, self::fired( PageCache::PURGED_ACTION ), $fixture );
        }

        // Outside the migration every pause purges, as before.
        $GLOBALS['wcct_test_actions'] = array();
        Pause::set( 'twitter', true );
        Pause::set( 'twitter', false );

        $this->assertCount( 2, self::fired( PageCache::PURGED_ACTION ) );
    }

    public function test_a_failed_migration_still_releases_the_purge_hold() {
        $fixtures = require dirname( __DIR__, 2 ) . '/fixtures/settings-2x.php';
        $GLOBALS['wcct_test_options'] = array(
            'wcct_settings'     => $fixtures['all-on'],
            'wcct_install_type' => 'upgrade',
        );
        $GLOBALS['wcct_test_filters'][ SettingsMigration::FILTER ] = function () {
            throw new RuntimeException( 'x' );
        };

        try {
            ( new SettingsMigration() )->run();
        } catch ( RuntimeException $e ) {
            $this->assertSame( 'x', $e->getMessage() );
        }

        $GLOBALS['wcct_test_actions'] = array();
        PageCache::purge();

        $this->assertCount( 1, self::fired( PageCache::PURGED_ACTION ) );
    }

    public function test_a_failed_pause_write_stops_the_migration_before_the_3_0_settings() {
        $v1 = require dirname( __DIR__, 2 ) . '/fixtures/settings-2x.php';
        $GLOBALS['wcct_test_options'] = array(
            'wcct_settings'     => $v1['off-with-ids'],
            'wcct_install_type' => 'upgrade',
        );
        $GLOBALS['wcct_test_failing_options']['wcct_paused_platforms'] = true;

        try {
            ( new SettingsMigration() )->run();
            $this->fail( 'No exception' );
        } catch ( RuntimeException $e ) {
            $this->assertStringContainsString( 'paused platforms', $e->getMessage() );
        }

        $this->assertArrayNotHasKey( 'wcct_settings_v3', $GLOBALS['wcct_test_options'] );
    }

    public function test_a_malformed_switch_reads_as_off_without_a_fatal() {
        foreach ( array( array(), new stdClass(), 'on', null ) as $enabled ) {
            $meta                 = new WCCT_Test_Fake_Integration( 'facebook' );
            $meta->settings_value = array( 'enabled' => $enabled, 0 => array( 'pixel_id' => '1' ) );

            $this->assertFalse( $meta->is_switched_on() );
        }

        $meta                 = new WCCT_Test_Fake_Integration( 'facebook' );
        $meta->settings_value = 'garbage';

        $this->assertFalse( $meta->is_switched_on() );
        $this->assertFalse( $meta->is_enabled() );
    }

    public function test_the_migration_keeps_customer_details_a_view_already_set() {
        // #106's LegacyView::uses_customer_placeholder() sets it in the view; the migration doesn't override it.
        $GLOBALS['wcct_test_filters']['wcct_settings_v1_view'] = function ( $view ) {
            $view['platforms']['custom']['customer_details'] = false;

            return $view;
        };

        $custom = SettingsMigration::map( array( 'custom' => array( 'enabled' => true, 'checkout' => "x('{customer_email}')" ) ), true )['settings']['platforms']['custom'];

        $this->assertFalse( $custom['customer_details'] );
    }
}
