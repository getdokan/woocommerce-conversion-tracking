<?php

require_once dirname( __DIR__ ) . '/Fakes/settings-functions.php';

if ( ! function_exists( 'add_filter' ) ) {
    function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
        return true;
    }
}

if ( ! defined( 'WCCT_INCLUDES' ) ) {
    define( 'WCCT_INCLUDES', dirname( __DIR__, 3 ) . '/includes' );
}

require_once dirname( __DIR__, 3 ) . '/includes/integrations/class-integration-facebook.php';
require_once dirname( __DIR__, 3 ) . '/includes/integrations/class-integration-twitter.php';
require_once dirname( __DIR__, 3 ) . '/includes/integrations/class-integration-google.php';
require_once dirname( __DIR__, 3 ) . '/includes/integrations/class-integration-ga4.php';

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Platforms\GoogleTag;
use StorePulse\ConversionTracking\Platforms\Pause;
use StorePulse\ConversionTracking\Platforms\PlatformSettings;
use StorePulse\ConversionTracking\Platforms\Registry;
use StorePulse\ConversionTracking\Settings\Schema;
use StorePulse\ConversionTracking\Settings\Values;
use StorePulse\ConversionTracking\Doctor\Platforms as DoctorPlatforms;

/**
 * Whether a platform is on and what it sends, on a 2.x store, a 3.0-only store and a migrated store
 */
class PlatformSettingsTest extends TestCase {

    const IDS = array(
        'facebook' => array( 'pixel_id' => '111111111111111' ),
        'twitter'  => array( 'pixel_id' => 'ct0x1' ),
        'bing_ads' => array( 'tag_id' => '1234567' ),
    );

    protected function setUp(): void {
        $GLOBALS['wcct_test_options'] = array();
        $GLOBALS['wcct_test_filters'] = array(
            // Pro's Microsoft platform, as Pro's Settings\Schema adds it.
            Schema::FILTER => function ( $schema ) {
                $schema['platforms']['bing_ads'] = array(
                    'integrations' => array( 'bing_ads' ),
                    'schema'       => array(
                        Schema::id_field( 'bing_ads_connection', 'tag_id', 'UET tag ID', '', '', '^[0-9]{7,9}$', 10, array( PlatformSettings::REQUIRED_KEY => true ) ),
                    ),
                );

                return $schema;
            },
        );

        PlatformSettings::reset();
        Pause::reset();
        GoogleTag::reset();
    }

    protected function tearDown(): void {
        unset( $GLOBALS['wcct_test_options'], $GLOBALS['wcct_test_filters'] );
        PlatformSettings::reset();
        Pause::reset();
        GoogleTag::reset();
    }

    /**
     * The integration for a platform: Lite's Meta and X, a stand-in for Pro's Microsoft reading the same 2.x option.
     *
     * @param string $platform Platform ID.
     *
     * @return WCCT_Integration
     */
    private function integration( $platform ) {
        if ( 'facebook' === $platform ) {
            return new WCCT_Integration_Facebook();
        }

        if ( 'twitter' === $platform ) {
            return new WCCT_Integration_Twitter();
        }

        $fake = new WCCT_Test_Fake_Integration( $platform );

        $fake->settings_value = isset( $GLOBALS['wcct_test_options']['wcct_settings'][ $platform ] ) ? $GLOBALS['wcct_test_options']['wcct_settings'][ $platform ] : false;

        return $fake;
    }

    /**
     * The 2.x entry of a platform: its switch and its ID.
     */
    private static function v1( $platform, $switch, $with_id = true ) {
        return array(
            'enabled' => $switch ? '1' : '',
            0         => $with_id ? self::IDS[ $platform ] : array(),
        );
    }

    /**
     * Per platform: state => [ wcct_settings entry, wcct_settings_v3 entry, wcct_paused_platforms, expected is_enabled(), expected is_active() ]; null leaves an option out.
     */
    public function states() {
        $cases = array();

        foreach ( array_keys( self::IDS ) as $platform ) {
            $id = self::IDS[ $platform ];

            $rows = array(
                '2.x on with ID'                       => array( self::v1( $platform, true ), null, null, true, true ),
                '2.x off with ID (paused)'             => array( self::v1( $platform, false ), null, null, false, false ),
                '2.x on without ID (as 2.x)'           => array( self::v1( $platform, true, false ), null, null, true, true ),
                '2.x off without ID'                   => array( self::v1( $platform, false, false ), null, null, false, false ),
                '3.0 only with ID'                     => array( null, $id, null, true, true ),
                '3.0 only without ID'                  => array( null, array(), null, false, false ),
                '3.0 only with ID, paused'             => array( null, $id, array( $platform ), true, false ),
                'migrated, 2.x on'                     => array( self::v1( $platform, true ), $id, array(), true, true ),
                'migrated, 2.x off (paused)'           => array( self::v1( $platform, false ), $id, array( $platform ), true, false ),
                'migrated, resumed'                    => array( self::v1( $platform, true ), $id, array(), true, true ),
                'migrated, ID cleared in 3.0'          => array( self::v1( $platform, true ), array(), array(), false, false ),
                'migrated, 2.x off but ID only in 3.0' => array( self::v1( $platform, false, false ), $id, array(), true, true ),
            );

            foreach ( $rows as $state => $row ) {
                $cases[ "$platform / $state" ] = array_merge( array( $platform ), $row );
            }
        }

        return $cases;
    }

    /**
     * @dataProvider states
     */
    public function test_on_and_active_per_state( $platform, $v1, $v3, $paused, $enabled, $active ) {
        if ( null !== $v1 ) {
            $GLOBALS['wcct_test_options']['wcct_settings'] = array( $platform => $v1 );
        }

        if ( null !== $v3 ) {
            $GLOBALS['wcct_test_options']['wcct_settings_v3'] = array( 'platforms' => array( $platform => $v3 ) );
        }

        if ( null !== $paused ) {
            $GLOBALS['wcct_test_options']['wcct_paused_platforms'] = $paused;
        }

        $integration = $this->integration( $platform );

        $this->assertSame( $enabled, $integration->is_enabled(), 'is_enabled' );
        $this->assertSame( $active, $integration->is_active(), 'is_active' );
        $this->assertSame( $active ? array( $platform ) : array(), array_keys( Registry::active( Registry::normalise( array( $integration ) ) ) ) );
    }

    public function test_the_classic_page_switch_stays_on_the_2x_option() {
        $GLOBALS['wcct_test_options']['wcct_settings']    = array( 'facebook' => self::v1( 'facebook', false ) );
        $GLOBALS['wcct_test_options']['wcct_settings_v3'] = array( 'platforms' => array( 'facebook' => self::IDS['facebook'] ) );

        $meta = new WCCT_Integration_Facebook();

        $this->assertFalse( $meta->is_switched_on() );
        $this->assertTrue( $meta->is_enabled() );
    }

    public function test_required_ids_come_from_the_schema() {
        $this->assertSame( array( 'pixel_id' ), PlatformSettings::required_ids( 'facebook' ) );
        $this->assertSame( array( 'pixel_id' ), PlatformSettings::required_ids( 'twitter' ) );
        $this->assertSame( array( 'tag_id' ), PlatformSettings::required_ids( 'bing_ads' ) );
        $this->assertSame( array(), PlatformSettings::required_ids( 'google' ) );
        $this->assertSame( array(), PlatformSettings::required_ids( 'custom' ) );
        $this->assertSame( array(), PlatformSettings::required_ids( 'unknown' ) );
    }

    public function test_the_required_marker_stays_on_the_server() {
        $output = wp_json_encode(
            Schema::for_output(
                Schema::get(),
                function () {
                    return true;
                }
            ) );

        $this->assertStringNotContainsString( PlatformSettings::REQUIRED_KEY, $output );
    }

    public function test_a_platform_the_schema_does_not_know_keeps_its_2x_switch() {
        $GLOBALS['wcct_test_options']['wcct_settings_v3'] = array( 'platforms' => array() );

        $this->assertTrue( ( new WCCT_Test_Fake_Integration( 'third_party', true ) )->is_enabled() );
        $this->assertFalse( ( new WCCT_Test_Fake_Integration( 'third_party', false ) )->is_enabled() );
    }

    public function test_meta_reads_its_pixel_and_events_from_the_3_0_settings_once_they_exist() {
        $GLOBALS['wcct_test_options']['wcct_settings'] = array(
            'facebook' => array(
                'enabled' => '1',
                array(
                    'pixel_id' => '222222222222222',
                    'events'   => array( 'AddToCart' => 'on' ),
                ),
            ),
        );

        $meta = new WCCT_Integration_Facebook();

        $this->assertSame( '222222222222222', $meta->get_pixel_id() );
        $this->assertTrue( $meta->event_enabled( 'AddToCart' ) );
        $this->assertFalse( $meta->event_enabled( 'Purchase' ) );

        PlatformSettings::reset();
        $GLOBALS['wcct_test_options']['wcct_settings_v3'] = array(
            'platforms' => array(
                'facebook' => array(
                    'pixel_id' => '111111111111111',
                    'events'   => array( 'purchase', 'sign_up' ),
                ),
            ),
        );

        $this->assertSame( '111111111111111', $meta->get_pixel_id() );
        $this->assertTrue( $meta->event_enabled( 'Purchase' ) );
        $this->assertTrue( $meta->event_enabled( 'Registration' ) );
        $this->assertFalse( $meta->event_enabled( 'AddToCart' ) );
        $this->assertSame( array( '111111111111111' ), $meta->get_event_pixels( 'CompleteRegistration' ) );
    }

    public function test_x_reads_its_pixel_and_event_ids_from_the_3_0_settings_once_they_exist() {
        $GLOBALS['wcct_test_options']['wcct_settings'] = array(
            'twitter' => array(
                'enabled' => '1',
                array(
                    'pixel_id' => 'old01',
                    'events'   => array(
                        'Purchase'          => 'on',
                        'purchase_event_id' => 'tw-old01-pur',
                    ),
                ),
            ),
        );

        $x = new WCCT_Integration_Twitter();

        $this->assertSame( 'old01', $x->get_x_settings()['pixel_id'] );

        PlatformSettings::reset();
        $GLOBALS['wcct_test_options']['wcct_settings_v3'] = array(
            'platforms' => array(
                'twitter' => array(
                    'pixel_id'          => 'ct0x1',
                    'purchase_event_id' => 'tw-ct0x1-ctpur',
                    'events'            => array( 'page_view', 'purchase' ),
                ),
            ),
        );

        $settings = $x->get_x_settings();

        $this->assertSame( 'ct0x1', $settings['pixel_id'] );
        $this->assertSame( 'tw-ct0x1-ctpur', \StorePulse\ConversionTracking\Platforms\XSettings::event_id( $settings, 'purchase' ) );
    }

    public function test_google_is_on_by_its_mode_and_ids_once_the_3_0_settings_exist() {
        $GLOBALS['wcct_test_options']['wcct_settings'] = array(
            'ga4'     => array( 'enabled' => '' ),
            'adwords' => array( 'enabled' => '1' ),
        );

        $this->assertFalse( ( new WCCT_Integration_GA4() )->is_enabled() );
        $this->assertTrue( ( new WCCT_Integration_Google() )->is_enabled() );

        $GLOBALS['wcct_test_options']['wcct_settings_v3'] = array(
            'platforms' => array(
                'google' => array(
                    'mode'           => 'analytics',
                    'measurement_id' => 'G-CTTEST1234',
                    'ads_id'         => 'AW-123456789',
                ),
            ),
        );
        $GLOBALS['wcct_test_options']['wcct_paused_platforms'] = array( 'google' );
        GoogleTag::reset();
        Pause::reset();
        PlatformSettings::reset();

        $ga4 = new WCCT_Integration_GA4();
        $ads = new WCCT_Integration_Google();

        // Paused counts as set up but off, so the Activity log lists it as paused.
        $this->assertTrue( $ga4->is_enabled() );
        $this->assertFalse( $ga4->is_active() );
        $this->assertFalse( $ads->is_enabled() );
        $this->assertFalse( $ga4->is_switched_on() );
    }

    /**
     * Platform => [ values, expected connected ].
     */
    public function connected_cases() {
        return array(
            'meta with its pixel'                 => array( 'facebook', array( 'pixel_id' => '111111111111111' ), true ),
            'meta without a pixel'                => array( 'facebook', array( 'pixel_id' => '' ), false ),
            'x with only a purchase event ID'     => array( 'twitter', array( 'purchase_event_id' => 'tw-ct0x1-ctpur' ), false ),
            'x with its pixel'                    => array( 'twitter', array( 'pixel_id' => 'ct0x1' ), true ),
            'google with only a purchase label'   => array( 'google', array( 'mode' => 'both', 'purchase_label' => 'CtPurchaseLabel' ), false ),
            'google with only a GT- tag ID'       => array( 'google', array( 'mode' => 'both', 'tag_id' => 'GT-ABC123' ), false ),
            'google ads with its conversion ID'   => array( 'google', array( 'mode' => 'ads', 'ads_id' => 'AW-123456789' ), true ),
            'google analytics ID in ads mode'     => array( 'google', array( 'mode' => 'ads', 'measurement_id' => 'G-CTTEST1234' ), false ),
            'microsoft with its tag'              => array( 'bing_ads', array( 'tag_id' => '1234567' ), true ),
            'custom placement on with a script'   => array( 'custom', array( 'thankyou_enabled' => true, 'thankyou_script' => '<script>a()</script>' ), true ),
            'custom placement on without script'  => array( 'custom', array( 'thankyou_enabled' => true, 'thankyou_script' => ' ' ), false ),
            'custom script with its placement off' => array( 'custom', array( 'thankyou_enabled' => false, 'thankyou_script' => '<script>a()</script>' ), false ),
        );
    }

    /**
     * @dataProvider connected_cases
     */
    public function test_one_connected_rule_for_the_settings_route_and_the_doctor( $platform, array $values, $expected ) {
        $schema = Schema::get();
        $doc    = array( 'platforms' => array( $platform => $values ) );
        $got    = Values::get( $schema, $doc )['platforms'];

        $this->assertSame( $expected, $got[ $platform ]['connected'], 'settings route' );

        $states = DoctorPlatforms::build(
            $schema['platforms'],
            $got,
            function () {
                return false;
            }
        );

        $this->assertSame( $expected, $states[ $platform ]['connected'], 'Doctor' );
        $this->assertSame( $expected, PlatformSettings::connected( $platform, $schema['platforms'][ $platform ]['schema'], $values ), 'without the stored value' );
    }

    public function test_extensions_can_change_connected() {
        $GLOBALS['wcct_test_filters']['wcct_platform_connected'] = function ( $connected, $platform, $values ) {
            return $connected || ( 'facebook' === $platform && ! empty( $values['pixels'] ) );
        };

        $schema = Schema::get()['platforms'];

        $this->assertTrue( PlatformSettings::connected( 'facebook', $schema['facebook']['schema'], array( 'pixels' => array( array( 'pixel_id' => '222222222222222' ) ) ) ) );
        $this->assertFalse( PlatformSettings::connected( 'twitter', $schema['twitter']['schema'], array( 'pixels' => array( array( 'pixel_id' => '222222222222222' ) ) ) ) );
    }

    public function test_the_3_0_settings_are_read_once_per_request_until_reset() {
        $GLOBALS['wcct_test_options']['wcct_settings_v3'] = array( 'platforms' => array( 'facebook' => array( 'pixel_id' => '111111111111111' ) ) );

        $this->assertSame( '111111111111111', PlatformSettings::values( 'facebook' )['pixel_id'] );

        $GLOBALS['wcct_test_options']['wcct_settings_v3']['platforms']['facebook']['pixel_id'] = '222222222222222';

        $this->assertSame( '111111111111111', PlatformSettings::values( 'facebook' )['pixel_id'] );

        PlatformSettings::reset();

        $this->assertSame( '222222222222222', PlatformSettings::values( 'facebook' )['pixel_id'] );

        unset( $GLOBALS['wcct_test_options']['wcct_settings_v3'] );
        PlatformSettings::reset();

        $this->assertNull( PlatformSettings::values( 'facebook' ) );
        $this->assertSame( array(), ( function () {
            $GLOBALS['wcct_test_options']['wcct_settings_v3'] = 'junk';
            PlatformSettings::reset();

            return PlatformSettings::values( 'facebook' );
        } )() );
    }
}
