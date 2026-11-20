<?php

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Events\Event;
use StorePulse\ConversionTracking\Events\Pipeline;
use StorePulse\ConversionTracking\Events\SkipRoles;
use StorePulse\ConversionTracking\Platforms\Registry;

/**
 * Tests for "Skip these roles" and the pipeline check
 */
class SkipRolesTest extends TestCase {

    protected function tearDown(): void {
        unset( $GLOBALS['wcct_test_options'], $GLOBALS['wcct_test_filters'], $GLOBALS['wcct_test_user_roles'] );
    }

    public function test_administrators_and_shop_managers_are_skipped_by_default_on_a_new_install() {
        foreach ( array( 'facebook', 'ga4', 'adwords', 'twitter', 'bing_ads' ) as $id ) {
            $this->assertSame( array( 'administrator', 'shop_manager' ), SkipRoles::for_platform( $id ), $id );
        }

        $this->assertSame( array(), SkipRoles::for_platform( 'custom' ) );
    }

    public function test_upgraders_track_everyone_until_a_value_is_saved() {
        $GLOBALS['wcct_test_options']['wcct_install_type'] = 'upgrade';

        $this->assertSame( array(), SkipRoles::for_platform( 'facebook' ) );
        $this->assertSame( array(), SkipRoles::for_platform( 'ga4' ) );

        $GLOBALS['wcct_test_options']['wcct_settings_v3'] = array( 'platforms' => array( 'facebook' => array( 'skip_roles' => 'admin-shop' ) ) );

        $this->assertSame( array( 'administrator', 'shop_manager' ), SkipRoles::for_platform( 'facebook' ) );
        $this->assertSame( array(), SkipRoles::for_platform( 'twitter' ) );

        // Before Install records it, 2.x settings mean an upgrade.
        $GLOBALS['wcct_test_options'] = array( 'wcct_settings' => array( 'facebook' => array( 'enabled' => true ) ) );

        $this->assertSame( array(), SkipRoles::for_platform( 'facebook' ) );
    }

    public function test_custom_is_never_skipped() {
        $GLOBALS['wcct_test_options']['wcct_settings_v3'] = array( 'platforms' => array( 'custom' => array( 'skip_roles' => array( 'customer' ) ) ) );
        $GLOBALS['wcct_test_filters']['wcct_skip_roles']  = function () {
            return array( 'customer' );
        };
        $GLOBALS['wcct_test_user_roles']                  = array( 'customer' );

        $this->assertSame( array(), SkipRoles::for_platform( 'custom' ) );
        $this->assertFalse( SkipRoles::skips_current_user( 'custom' ) );
    }

    public function test_saved_presets_and_lists_are_read_per_settings_platform() {
        $GLOBALS['wcct_test_options']['wcct_settings_v3'] = array(
            'platforms' => array(
                'facebook' => array( 'skip_roles' => 'admin' ),
                'google'   => array( 'skip_roles' => 'none' ),
                'twitter'  => array( 'skip_roles' => array( 'editor', '', 5, 'editor' ) ),
                'bing_ads' => array( 'skip_roles' => 'unknown preset' ),
            ),
        );

        $this->assertSame( array( 'administrator' ), SkipRoles::for_platform( 'facebook' ) );
        $this->assertSame( array(), SkipRoles::for_platform( 'ga4' ) );
        $this->assertSame( array(), SkipRoles::for_platform( 'adwords' ) );
        $this->assertSame( array( 'editor' ), SkipRoles::for_platform( 'twitter' ) );
        $this->assertSame( array( 'administrator', 'shop_manager' ), SkipRoles::for_platform( 'bing_ads' ) );
    }

    public function test_filter_changes_the_roles() {
        $GLOBALS['wcct_test_filters']['wcct_skip_roles'] = function ( $roles, $id ) {
            return 'twitter' === $id ? array( 'customer' ) : 'broken';
        };

        $this->assertSame( array( 'customer' ), SkipRoles::for_platform( 'twitter' ) );
        $this->assertSame( array(), SkipRoles::for_platform( 'facebook' ) );
    }

    public function test_visitors_not_logged_in_are_never_skipped() {
        $this->assertFalse( SkipRoles::skips_current_user( 'facebook' ) );

        $GLOBALS['wcct_test_user_roles'] = array( 'customer' );
        $this->assertFalse( SkipRoles::skips_current_user( 'facebook' ) );

        $GLOBALS['wcct_test_user_roles'] = array( 'customer', 'shop_manager' );
        $this->assertTrue( SkipRoles::skips_current_user( 'facebook' ) );
        $this->assertFalse( SkipRoles::skips_current_user( 'custom' ) );
    }

    public function test_pipeline_sends_nothing_to_platforms_that_skip_the_visitor() {
        $GLOBALS['wcct_test_user_roles']                 = array( 'administrator' );
        $GLOBALS['wcct_test_options']['wcct_settings_v3'] = array( 'platforms' => array( 'twitter' => array( 'skip_roles' => 'none' ) ) );

        $platforms = array( new WCCT_Test_Fake_Integration( 'facebook' ), new WCCT_Test_Fake_Integration( 'twitter' ), new WCCT_Test_Fake_Integration( 'bing_ads' ) );
        $pipeline  = new Pipeline(
            function () use ( $platforms ) {
                return $platforms;
            }
        );

        $this->assertSame( array( 'twitter' ), array_keys( $pipeline->get_platforms() ) );

        $pipeline->dispatch( new Event( Event::VIEW_PRODUCT, array( 'product_ids' => array( 4 ) ) ) );

        $this->assertSame( array( 'twitter' => "twitter.view(4);\ntwitter.view(4);\n" ), $pipeline->build_footer_scripts( array( new Event( Event::VIEW_PRODUCT, array( 'product_ids' => array( 4 ) ) ) ) ) );
        $this->assertSame( array( 'twitter' ), array_keys( Registry::for_visitor( Registry::normalise( $platforms ) ) ) );
    }
}
