<?php

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Access\CustomScripts;

/**
 * Tests for the custom script guard
 */
class CustomScriptsTest extends TestCase {

    public function test_differs_ignores_line_break_style_only() {
        $this->assertFalse( CustomScripts::differs( "a\r\nb", "a\nb" ) );
        $this->assertTrue( CustomScripts::differs( 'a', 'b' ) );
        $this->assertTrue( CustomScripts::differs( true, false ) );
        $this->assertFalse( CustomScripts::differs( false, false ) );
    }
}
