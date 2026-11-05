<?php

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Access\CustomScripts;

/**
 * Tests for the custom script guard
 */
class CustomScriptsTest extends TestCase {

    private function stored() {
        return array(
            'facebook' => array( 'enabled' => true, array( 'pixel_id' => '111' ) ),
            'custom'   => array(
                'enabled'      => true,
                'checkout'     => "<script>\nfbq('track', '{order_total}');\n</script>",
                'registration' => '<script>reg()</script>',
            ),
        );
    }

    public function test_same_scripts_are_unchanged() {
        $this->assertFalse( CustomScripts::changed( $this->stored(), $this->stored() ) );
    }

    public function test_crlf_from_the_browser_is_unchanged() {
        $new                       = $this->stored();
        $new['custom']['checkout'] = str_replace( "\n", "\r\n", $new['custom']['checkout'] );

        $this->assertFalse( CustomScripts::changed( $new, $this->stored() ) );
    }

    public function test_edited_script_is_changed() {
        $new                           = $this->stored();
        $new['custom']['registration'] = '<script>evil()</script>';

        $this->assertTrue( CustomScripts::changed( $new, $this->stored() ) );
    }

    public function test_emptied_script_is_changed() {
        $new                       = $this->stored();
        $new['custom']['checkout'] = '';

        $this->assertTrue( CustomScripts::changed( $new, $this->stored() ) );
    }

    public function test_new_script_on_empty_store_is_changed() {
        $new = array( 'custom' => array( 'checkout' => '<script>x()</script>' ) );

        $this->assertTrue( CustomScripts::changed( $new, array() ) );
    }

    public function test_array_injection_is_changed() {
        $new                       = $this->stored();
        $new['custom']['checkout'] = array( '<script>x()</script>' );

        $this->assertTrue( CustomScripts::changed( $new, $this->stored() ) );
    }

    public function test_absent_fields_are_unchanged() {
        $new = array( 'custom' => array( 'enabled' => false ), 'facebook' => array( 'enabled' => true ) );

        $this->assertFalse( CustomScripts::changed( $new, $this->stored() ) );
        $this->assertFalse( CustomScripts::changed( array(), $this->stored() ) );
    }

    public function test_other_integrations_do_not_count() {
        $new                            = $this->stored();
        $new['facebook'][0]['pixel_id'] = '222';

        $this->assertFalse( CustomScripts::changed( $new, $this->stored() ) );
    }

    public function test_keep_restores_scripts_and_keeps_the_toggle() {
        $new = array(
            'facebook' => array( 'enabled' => true ),
            'custom'   => array( 'enabled' => false ),
        );

        $kept = CustomScripts::keep( $new, $this->stored() );

        $this->assertFalse( $kept['custom']['enabled'] );
        $this->assertSame( $this->stored()['custom']['checkout'], $kept['custom']['checkout'] );
        $this->assertSame( $this->stored()['custom']['registration'], $kept['custom']['registration'] );
        $this->assertSame( array( 'enabled' => true ), $kept['facebook'] );
    }

    public function test_keep_restores_scripts_when_the_integration_was_not_posted() {
        $kept = CustomScripts::keep( array( 'facebook' => array( 'enabled' => true ) ), $this->stored() );

        $this->assertFalse( $kept['custom']['enabled'] );
        $this->assertSame( '<script>reg()</script>', $kept['custom']['registration'] );
    }

    public function test_keep_overrides_posted_scripts() {
        $new = array( 'custom' => array( 'enabled' => true, 'checkout' => 'posted', 'registration' => 'posted' ) );

        $kept = CustomScripts::keep( $new, $this->stored() );

        $this->assertSame( $this->stored()['custom'], $kept['custom'] );
    }

    public function test_keep_drops_posted_scripts_when_none_were_stored() {
        $kept = CustomScripts::keep( array( 'custom' => array( 'enabled' => true, 'checkout' => 'posted' ) ), array() );

        $this->assertSame( array( 'enabled' => true ), $kept['custom'] );
    }

    public function test_keep_without_custom_anywhere_changes_nothing() {
        $new = array( 'facebook' => array( 'enabled' => true ) );

        $this->assertSame( $new, CustomScripts::keep( $new, array( 'facebook' => array() ) ) );
    }
}
