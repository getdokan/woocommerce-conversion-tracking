<?php

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Privacy\PolicyText;

/**
 * Tests for the suggested privacy policy text
 */
class PolicyTextTest extends TestCase {

    public function test_names_every_free_platform() {
        $content = PolicyText::content();

        foreach ( array( 'Meta Pixel', 'Google Analytics 4', 'Google Ads', 'X Pixel', 'custom script' ) as $platform ) {
            $this->assertStringContainsString( $platform, $content );
        }
    }

    public function test_marks_the_suggested_text() {
        $this->assertStringContainsString( '<strong class="privacy-policy-tutorial">Suggested text: </strong>', PolicyText::content() );
    }
}
