<?php

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Admin\ClassicScreens;

/**
 * Tests for what is left of the 2.x settings screens
 */
class ClassicScreensTest extends TestCase {

    public function test_each_2x_tab_opens_the_app_page_that_replaced_it() {
        $this->assertSame( 'https://shop.test/wp-admin/admin.php?page=conversion-tracking#/platforms', ClassicScreens::redirect_url( 'integrations' ) );
        $this->assertSame( 'https://shop.test/wp-admin/admin.php?page=conversion-tracking#/catalog', ClassicScreens::redirect_url( 'product-catalog' ) );
        $this->assertSame( 'https://shop.test/wp-admin/admin.php?page=conversion-tracking#/settings/access', ClassicScreens::redirect_url( 'settings' ) );
        $this->assertSame( 'https://shop.test/wp-admin/admin.php?page=conversion-tracking#/dashboard', ClassicScreens::redirect_url( 'a-third-party-tab' ) );
    }

    public function test_the_four_2x_extension_points_and_their_per_tab_and_per_integration_forms_are_classic() {
        foreach ( array( 'wcct_nav_tab', 'wcct_sidebar', 'wcct_nav_content_settings', 'wcct_integration_facebook', 'wcct_before_nav', 'wcct_save_integrations_settings' ) as $hook ) {
            $this->assertTrue( ClassicScreens::is_classic_hook( $hook ), $hook );
        }

        foreach ( array( 'wcct_integrations', 'wcct_integration_', 'wcct_nav_content_', 'wcct_platforms', 'wcct_settings_saved', 'wcct_menu_page' ) as $hook ) {
            $this->assertFalse( ClassicScreens::is_classic_hook( $hook ), $hook );
        }
    }

    public function test_only_classic_hooks_with_callbacks_are_reported() {
        $filters = array(
            'wcct_nav_tab'              => array( 10 => array( 'cb' => array() ) ),
            'wcct_sidebar'              => array(),
            'wcct_integration_facebook' => array( 10 => array( 'cb' => array() ) ),
            'wcct_platforms'            => array( 10 => array( 'cb' => array() ) ),
        );

        $this->assertSame( array( 'wcct_nav_tab', 'wcct_integration_facebook' ), ClassicScreens::hooked( $filters ) );
    }
}
