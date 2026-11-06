<?php

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Access\SettingsIds;

/**
 * Tests for the platform ID cleaning on save
 */
class SettingsIdsTest extends TestCase {

    public function test_script_breakout_is_removed_from_ids() {
        $clean = SettingsIds::clean(
            array(
                'facebook' => array( 'enabled' => true, 0 => array( 'pixel_id' => "123');alert(document.cookie);//" ) ),
                'bing_ads' => array( 0 => array( 'tag_id' => '7272"}<script>x</script>' ) ),
            )
        );

        $this->assertSame( '123alertdocument.cookie//', $clean['facebook'][0]['pixel_id'] );
        $this->assertSame( '7272scriptx/script', $clean['bing_ads'][0]['tag_id'] );
        $this->assertTrue( $clean['facebook']['enabled'] );
    }

    public function test_valid_ids_and_labels_are_kept() {
        $settings = array(
            'adwords'  => array( 0 => array( 'account_id' => 'AW-123456789', 'events' => array( 'Purchase' => 'on', 'Purchase-label' => 'AbC-12_x/y' ) ) ),
            'ga4'      => array( 0 => array( 'measurement_id' => 'G-ABC123' ) ),
            'twitter'  => array( 0 => array( 'pixel_id' => 'o1abc', 'events' => array( 'Purchase' => 'tw-o1abc-o1def' ) ) ),
        );

        $this->assertSame( $settings, SettingsIds::clean( $settings ) );
    }

    public function test_custom_scripts_are_left_alone() {
        $script = "<script>console.log('{order_number}');</script>";
        $clean  = SettingsIds::clean( array( 'custom' => array( 0 => array( 'checkout' => $script ) ) ) );

        $this->assertSame( $script, $clean['custom'][0]['checkout'] );
    }
}
