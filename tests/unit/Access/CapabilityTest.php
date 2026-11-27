<?php

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Access\Capability;

/**
 * Tests for the manage_wcct grant
 */
class CapabilityTest extends TestCase {

    public function test_users_who_manage_options_get_manage_wcct() {
        $caps = Capability::grant( array( 'manage_options' => true, 'administrator' => true ) );

        $this->assertTrue( $caps['manage_wcct'] );
    }

    public function test_shop_manager_without_manage_options_gets_nothing() {
        $caps = Capability::grant( array( 'manage_woocommerce' => true, 'shop_manager' => true ) );

        $this->assertArrayNotHasKey( 'manage_wcct', $caps );
    }

    public function test_denied_manage_options_does_not_grant() {
        $caps = Capability::grant( array( 'manage_options' => false ) );

        $this->assertArrayNotHasKey( 'manage_wcct', $caps );
    }

    public function test_role_grants_alone_mean_nothing_in_lite() {
        $caps = Capability::grant( array( 'shop_manager' => true, 'wcct_permissions' => array( 'shop_manager' => '1' ) ) );

        $this->assertArrayNotHasKey( 'manage_wcct', $caps );
    }

    public function test_an_existing_grant_is_left_alone() {
        $caps = Capability::grant( array( 'manage_wcct' => true ) );

        $this->assertTrue( $caps['manage_wcct'] );
    }

    public function test_other_caps_are_untouched() {
        $before = array( 'manage_options' => true, 'edit_posts' => true, 'unfiltered_html' => false );
        $caps   = Capability::grant( $before );

        unset( $caps['manage_wcct'] );
        $this->assertSame( $before, $caps );
    }

    public function test_filter_callback_passes_non_arrays_through() {
        $this->assertNull( ( new Capability() )->filter_user_caps( null ) );
    }
}
