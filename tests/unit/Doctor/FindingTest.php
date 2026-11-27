<?php

require_once dirname( __DIR__ ) . '/Fakes/doctor-functions.php';

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Doctor\Finding;

/**
 * Tests for the finding shape, its evidence hash and its action
 */
class FindingTest extends TestCase {

    public function test_a_finding_has_the_common_shape_without_its_evidence() {
        $finding = new Finding(
            'sample',
            array(
                'key'        => 'pys',
                'found'      => 'PixelYourSite is <b>also</b> active',
                'why'        => "Each sale\n reaches Meta twice.",
                'action'     => array(
                    'label' => 'Open Platforms',
                    'url'   => '#/platforms/facebook',
                ),
                'confidence' => Finding::UNSURE,
                'label'      => 'Duplicate',
                'tags'       => array( 'Meta', '', '<i>Sure</i>' ),
                'steps'      => array( 'Pick one plugin.', 'Then check again.' ),
                'evidence'   => array( 'slug' => 'pixelyoursite' ),
            )
        );

        $this->assertSame(
            array(
                'hash'       => $finding->hash(),
                'check'      => 'sample',
                'key'        => 'pys',
                'found'      => 'PixelYourSite is also active',
                'why'        => 'Each sale reaches Meta twice.',
                'action'     => array(
                    'type'     => 'link',
                    'label'    => 'Open Platforms',
                    'url'      => '#/platforms/facebook',
                    'external' => false,
                    'confirm'  => '',
                ),
                'confidence' => 'unsure',
                'severity'   => 'warning',
                'label'      => 'Duplicate',
                'tags'       => array( 'Meta', 'Sure' ),
                'steps'      => array( 'Pick one plugin.', 'Then check again.' ),
            ),
            $finding->to_array()
        );
        $this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $finding->hash() );
        $this->assertTrue( $finding->is_warning() );
    }

    public function test_defaults_are_a_sure_warning_with_check_again() {
        $data = ( new Finding( 'sample', array( 'found' => 'Something' ) ) )->to_array();

        $this->assertSame( 'sure', $data['confidence'] );
        $this->assertSame( 'warning', $data['severity'] );
        $this->assertSame( 'recheck', $data['action']['type'] );
        $this->assertSame( '', $data['action']['url'] );
    }

    public function test_the_hash_follows_the_check_key_and_evidence_only() {
        $base = new Finding( 'sample', array( 'found' => 'A', 'evidence' => array( 'id' => '1' ) ) );

        $this->assertSame( $base->hash(), ( new Finding( 'sample', array( 'found' => 'Reworded', 'evidence' => array( 'id' => '1' ) ) ) )->hash(), 'Wording is not evidence' );
        $this->assertNotSame( $base->hash(), ( new Finding( 'sample', array( 'evidence' => array( 'id' => '2' ) ) ) )->hash() );
        $this->assertNotSame( $base->hash(), ( new Finding( 'sample', array( 'key' => 'x', 'evidence' => array( 'id' => '1' ) ) ) )->hash() );
        $this->assertNotSame( $base->hash(), ( new Finding( 'other', array( 'evidence' => array( 'id' => '1' ) ) ) )->hash() );
    }

    public function test_only_app_routes_and_http_links_are_kept() {
        $link = function ( $url, $extra = array() ) {
            return ( new Finding( 'sample', array( 'action' => array_merge( array( 'label' => 'Go', 'url' => $url ), $extra ) ) ) )->to_array()['action'];
        };

        $this->assertSame( 'https://example.com/docs', $link( 'https://example.com/docs', array( 'external' => true ) )['url'] );
        $this->assertTrue( $link( 'https://example.com/docs', array( 'external' => true ) )['external'] );
        $this->assertSame( '#/catalog?tab=feed', $link( '#/catalog?tab=feed' )['url'] );
        $this->assertSame( '#/catalogscript', $link( '#/catalog"><script>' )['url'], 'Markup is stripped from app routes' );
        $this->assertSame( array( 'recheck', '' ), array( $link( 'javascript:alert(1)' )['type'], $link( 'javascript:alert(1)' )['url'] ) );
        $this->assertSame( 'recheck', $link( '#/catalog', array( 'type' => 'recheck' ) )['type'] );
        $this->assertSame( 'Turn off its pixel?', $link( 'https://shop.test/wp-admin/', array( 'confirm' => 'Turn off its pixel?' ) )['confirm'] );
    }

    public function test_check_ids_are_cleaned() {
        $this->assertSame( 'consent-off', Finding::id( 'Consent-Off!' ) );
        $this->assertSame( '', Finding::id( array( 'x' ) ) );
    }
}
