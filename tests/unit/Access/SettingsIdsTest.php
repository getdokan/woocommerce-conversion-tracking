<?php

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Access\SettingsIds;

/**
 * Tests for the platform ID cleaning on save
 */
class SettingsIdsTest extends TestCase {

    public function test_script_breakout_is_removed_from_an_id() {
        $this->assertSame( '123alertdocument.cookie//', SettingsIds::clean_id( "123');alert(document.cookie);//" ) );
        $this->assertSame( '7272scriptx/script', SettingsIds::clean_id( '7272"}<script>x</script>' ) );
    }

    public function test_valid_ids_and_labels_are_kept() {
        foreach ( array( 'AW-123456789', 'AbC-12_x/y', 'G-ABC123', 'tw-o1abc-o1def', 'GT-ABC:1.2' ) as $id ) {
            $this->assertSame( $id, SettingsIds::clean_id( $id ) );
        }
    }
}
