<?php

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Consent\PluginWarning;

/**
 * Tests for the warning beside "Respect cookie choices" while no cookie plugin passes on the visitor's choice (#135)
 */
class PluginWarningTest extends TestCase {

    const LABELS = array(
        'facebook' => 'Meta',
        'google'   => 'Google',
        'twitter'  => 'X',
        'bing_ads' => 'Microsoft',
    );

    public function test_no_warning_when_a_cookie_plugin_is_connected() {
        $this->assertNull( PluginWarning::build( array( 'kind' => '', 'tools' => array() ), self::LABELS, false ) );
    }

    public function test_no_plugin_says_what_each_platform_does() {
        $warning = PluginWarning::build( array( 'kind' => 'none', 'tools' => array() ), self::LABELS, false );

        $this->assertSame( 'No cookie plugin found.', $warning['title'] );
        $this->assertSame( 'This setting needs a supported cookie consent plugin. Until one is active, Meta events are sent to all visitors.', $warning['messages']['facebook'] );
        $this->assertStringEndsWith( 'Microsoft events are sent to all visitors.', $warning['messages']['bing_ads'] );

        // Google follows consent mode: it isn't "sent to all visitors".
        $this->assertStringNotContainsString( 'sent to all visitors', $warning['messages']['google'] );
        $this->assertStringContainsString( 'Google gets no consent for visitors in Europe', $warning['messages']['google'] );

        // Onboarding's, for every platform.
        $this->assertStringContainsString( 'your tags run for all visitors', $warning['messages'][''] );
        $this->assertSame( array( 'label', 'url' ), array_keys( $warning['link'] ) );
        $this->assertStringStartsWith( 'https://', $warning['link']['url'] );
    }

    public function test_a_silent_plugin_is_named_and_google_is_left_out_when_the_plugin_owns_google_consent() {
        $gap     = array( 'kind' => 'silent', 'tools' => array( 'real-cookie-banner' ) );
        $warning = PluginWarning::build( $gap, self::LABELS, false );

        $this->assertStringContainsString( 'isn\'t passing cookie choices.', $warning['title'] );
        $this->assertStringNotContainsString( 'real-cookie-banner', $warning['title'], 'The name, not the slug' );
        $this->assertStringContainsString( 'Until it does, X events are sent to all visitors.', $warning['messages']['twitter'] );
        $this->assertArrayHasKey( 'google', $warning['messages'] );

        $this->assertArrayNotHasKey( 'google', PluginWarning::build( $gap, self::LABELS, true )['messages'] );
        $this->assertArrayHasKey( 'facebook', PluginWarning::build( $gap, self::LABELS, true )['messages'] );

        // Onboarding's line leaves Google out too then, and names it otherwise.
        $this->assertStringEndsWith( 'your tags other than Google\'s run for all visitors.', PluginWarning::build( $gap, self::LABELS, true )['messages'][''] );
        $this->assertStringContainsString( 'Google measures visitors in Europe without cookies', $warning['messages'][''] );

        // No plugin at all: Google keeps its text whatever the flag.
        $this->assertArrayHasKey( 'google', PluginWarning::build( array( 'kind' => 'none', 'tools' => array() ), self::LABELS, true )['messages'] );
    }
}
