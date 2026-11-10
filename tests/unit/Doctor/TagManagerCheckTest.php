<?php

require_once __DIR__ . '/DoctorCheckTestCase.php';

use StorePulse\ConversionTracking\Doctor\Checks\TagManagerCheck;

/**
 * An active Google Tag Manager plugin: "we can't see inside"
 */
class TagManagerCheckTest extends DoctorCheckTestCase {

    public function test_gtm4wp_gives_a_not_sure_finding() {
        $this->activate( 'duracelltomi-google-tag-manager/duracelltomi-google-tag-manager-for-wordpress.php' );
        $this->option( 'gtm4wp-options', array( 'gtm-code' => 'GTM-ABC123' ) );

        $findings = $this->run_check( new TagManagerCheck() );

        $this->assertCount( 1, $findings );
        $this->assertSame( 'A Google Tag Manager plugin is active', $findings[0]['found'] );
        $this->assertSame( 'If your container also has a purchase tag, Google counts each sale twice.', $findings[0]['why'] );
        $this->assertSame( array( 'Google', 'Can\'t see inside', 'GTM4WP' ), $findings[0]['tags'] );
        $this->assertSame( array( 'unsure', 'warning' ), array( $findings[0]['confidence'], $findings[0]['severity'] ) );
        $this->assertSame( array( 'How to check', true ), array( $findings[0]['action']['label'], $findings[0]['action']['external'] ) );
    }

    public function test_gtm4wp_without_a_container_gives_nothing() {
        $this->activate( 'duracelltomi-google-tag-manager/duracelltomi-google-tag-manager-for-wordpress.php' );

        $this->assertSame( array(), $this->run_check( new TagManagerCheck() ), 'Not set up yet' );

        $this->option( 'gtm4wp-options', array( 'gtm-code' => '' ) );

        $this->assertSame( array(), $this->run_check( new TagManagerCheck() ) );
    }

    public function test_no_tag_manager_plugin_gives_nothing() {
        $this->activate( 'woocommerce/woocommerce.php' );

        $this->assertSame( array(), $this->run_check( new TagManagerCheck() ) );
        $this->assertSame( 'No Google Tag Manager plugin is active', ( new TagManagerCheck() )->label() );
    }

    public function test_site_kit_counts_only_with_a_tag_manager_container() {
        $this->activate( 'google-site-kit/google-site-kit.php' );

        $this->assertSame( array(), $this->run_check( new TagManagerCheck() ), 'Not applicable: Site Kit without its Tag Manager module' );

        $this->option( 'googlesitekit_tagmanager_settings', array( 'containerID' => 'GTM-ABC123' ) );

        $this->assertSame( array( 'Google', 'Can\'t see inside', 'Site Kit by Google (Tag Manager)' ), $this->run_check( new TagManagerCheck() )[0]['tags'] );
    }
}
