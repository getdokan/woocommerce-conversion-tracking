<?php

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Settings\Sanitizer;
use StorePulse\ConversionTracking\Settings\Schema;

/**
 * Tests for the Advanced settings fields of the platform pages (#104)
 */
class AdvancedFieldsTest extends TestCase {

    protected function tearDown(): void {
        unset( $GLOBALS['wcct_test_options'] );
    }

    public function test_skip_roles_takes_a_preset_or_the_site_roles_and_saves_a_matching_pick_as_its_preset() {
        $field = Schema::fields( Schema::get()['platforms']['facebook']['schema'] )['skip_roles'];

        $field['roles'] = array(
            array( 'value' => 'administrator', 'label' => 'Administrator' ),
            array( 'value' => 'shop_manager', 'label' => 'Shop manager' ),
            array( 'value' => 'editor', 'label' => 'Editor' ),
        );

        $this->assertSame( 'admin', Sanitizer::field( $field, 'admin', 'none' ) );
        $this->assertSame( array( 'editor', 'shop_manager' ), Sanitizer::field( $field, array( 'editor', 'shop_manager', 'editor' ), 'none' ) );
        $this->assertSame( 'admin-shop', Sanitizer::field( $field, array( 'shop_manager', 'administrator' ), 'none' ), 'a pick that is a preset' );
        $this->assertSame( 'none', Sanitizer::field( $field, array(), 'admin' ), 'nobody skipped' );

        foreach ( array( 'everyone', array( 'subscriber' ), array( 1 ), 7 ) as $bad ) {
            try {
                Sanitizer::field( $field, $bad, 'none' );
                $this->fail( 'accepted ' . var_export( $bad, true ) );
            } catch ( InvalidArgumentException $e ) {
                $this->assertNotSame( '', $e->getMessage() );
            }
        }
    }

    public function test_skip_roles_drops_a_saved_role_the_site_no_longer_has_but_refuses_a_new_unknown_one() {
        $field = Schema::fields( Schema::get()['platforms']['facebook']['schema'] )['skip_roles'];

        $field['roles'] = array(
            array( 'value' => 'administrator', 'label' => 'Administrator' ),
            array( 'value' => 'editor', 'label' => 'Editor' ),
        );

        $this->assertSame( array( 'editor' ), Sanitizer::field( $field, array( 'old_role', 'editor' ), array( 'old_role', 'administrator' ) ) );

        $this->expectException( InvalidArgumentException::class );
        Sanitizer::field( $field, array( 'editor', 'another_role' ), array( 'old_role' ) );
    }

    public function test_value_mode_explains_the_kept_order_total_to_upgraders_only() {
        $this->assertArrayNotHasKey( 'kept_note', Schema::fields( Schema::get()['platforms']['facebook']['schema'] )['value_mode'] );

        $GLOBALS['wcct_test_options']['wcct_install_type'] = 'upgrade';

        $platforms = Schema::get()['platforms'];

        $this->assertNotEmpty( Schema::fields( $platforms['facebook']['schema'] )['value_mode']['kept_note'] );
        $this->assertNotEmpty( Schema::fields( $platforms['google']['schema'] )['value_mode']['kept_note'], 'Google Ads kept the total too' );
        $this->assertStringContainsString( 'Analytics always gets the product total, without fees', Schema::fields( $platforms['google']['schema'] )['value_mode']['description'] );
    }
}
