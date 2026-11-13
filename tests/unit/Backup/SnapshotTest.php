<?php

require_once dirname( __DIR__ ) . '/Fakes/settings-functions.php';

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Backup\Snapshot;

/**
 * Tests for the pre-3.0 settings backup
 */
class SnapshotTest extends TestCase {

    protected function tearDown(): void {
        unset( $GLOBALS['wcct_test_filters'] );
    }

    public function test_free_backs_up_the_2x_settings_and_pro_adds_its_options() {
        $this->assertSame( array( 'wcct_settings' ), Snapshot::options() );

        $GLOBALS['wcct_test_filters']['wcct_backup_options'] = function ( $options ) {
            return array_merge( $options, array( 'wcct_data_feed', 'wcct_permissions', 'wcct_settings', 42 ) );
        };

        $this->assertSame( array( 'wcct_settings', 'wcct_data_feed', 'wcct_permissions' ), Snapshot::options() );
    }

    public function test_the_feed_password_is_removed_and_everything_else_kept() {
        $stored = array(
            'wcct_settings'    => array(
                'facebook' => array( 'enabled' => true, 0 => array( 'pixel_id' => '123' ) ),
                'custom'   => array( 'checkout' => '<script>a()</script>' ),
            ),
            'wcct_data_feed'   => array(
                'types'        => array( 'grouped' ),
                'product_cat'  => array( '15' ),
                'authenticate' => array(
                    'wcct-authenticate-enable' => 'yes',
                    'username'                 => 'meta',
                    'password'                 => 'hunter2',
                ),
            ),
            'wcct_permissions' => array( 'shop_manager' => '1' ),
        );

        $values = Snapshot::collect(
            array( 'wcct_settings', 'wcct_data_feed', 'wcct_permissions', 'wcct_missing' ),
            function ( $option ) use ( $stored ) {
                return isset( $stored[ $option ] ) ? $stored[ $option ] : null;
            }
        );

        $expected = $stored;
        unset( $expected['wcct_data_feed']['authenticate']['password'] );

        $this->assertSame( $expected, $values );
        $this->assertStringNotContainsString( 'hunter2', json_encode( $values ) );
    }

    public function test_secret_keys_are_removed_at_any_depth_and_in_objects() {
        $object         = new stdClass();
        $object->Pass   = 'x';
        $object->name   = 'kept';
        $object->nested = array( 'api_secret' => 'y', 'feed_password' => 'z', 'passport' => 'kept' );

        $this->assertSame(
            array( 'a' => array( 'name' => 'kept', 'nested' => array( 'passport' => 'kept' ) ) ),
            Snapshot::scrub( array( 'a' => $object, 'secret' => 's' ) )
        );
        $this->assertSame( 'plain', Snapshot::scrub( 'plain' ) );
    }

    public function test_the_frozen_copy_wins_over_the_live_options() {
        $live = array(
            'wcct_settings'      => array( 'facebook' => array( 'pixel_id' => 'changed-in-3.0' ) ),
            'wcct_pre3_snapshot' => array(
                'wcct_settings'             => array( 'facebook' => array( 'pixel_id' => '123' ) ),
                'wcct_data_feed'            => array( 'authenticate' => array( 'username' => 'meta', 'password' => 'hunter2' ) ),
                'wcct_secret_feed_password' => 'wcct:sb1:abc',
            ),
        );

        $backup = Snapshot::load( self::reader( $live ) );

        $this->assertSame( 'snapshot', $backup['source'] );
        $this->assertSame(
            array(
                'wcct_settings'  => array( 'facebook' => array( 'pixel_id' => '123' ) ),
                'wcct_data_feed' => array( 'authenticate' => array( 'username' => 'meta' ) ),
            ),
            $backup['options']
        );
    }

    public function test_without_a_frozen_copy_the_live_2x_options_are_used() {
        $backup = Snapshot::load( self::reader( array( 'wcct_settings' => array( 'a' => 1 ), 'wcct_pre3_snapshot' => array() ) ) );

        $this->assertSame( array( 'source' => 'live', 'options' => array( 'wcct_settings' => array( 'a' => 1 ) ) ), $backup );
    }

    public function test_no_backup_without_2x_settings_even_when_3x_wrote_pro_options() {
        $GLOBALS['wcct_test_filters']['wcct_backup_options'] = function ( $options ) {
            return array_merge( $options, array( 'wcct_permissions' ) );
        };

        $this->assertNull( Snapshot::load( self::reader( array( 'wcct_permissions' => array( 'shop_manager' => '1' ) ) ) ) );
        $this->assertNull( Snapshot::load( self::reader( array() ) ) );
    }

    public function test_secret_options_are_never_collected() {
        $values = Snapshot::collect( array( 'wcct_secret_feed_password', 'wcct_api_token', 'wcct_settings', 7 ), self::reader( array( 'wcct_secret_feed_password' => 'x', 'wcct_api_token' => 'y', 'wcct_settings' => array() ) ) );

        $this->assertSame( array( 'wcct_settings' => array() ), $values );
    }

    public function test_the_wider_secret_key_list() {
        $in = array(
            'pwd'                      => 1,
            'passphrase'               => 1,
            'token'                    => 1,
            'access_token'             => 1,
            'api_key'                  => 1,
            'license_key'              => 1,
            'client-secret'            => 1,
            'username'                 => 'kept',
            'pixel_id'                 => 'kept',
            'passport'                 => 'kept',
            'keyword'                  => 'kept',
            'wcct-authenticate-enable' => 'kept',
            'Purchase-label'           => 'kept',
        );

        $this->assertSame( array( 'username', 'pixel_id', 'passport', 'keyword', 'wcct-authenticate-enable', 'Purchase-label' ), array_keys( Snapshot::scrub( $in ) ) );
    }

    public function test_the_document_names_its_format_source_and_time() {
        $doc = Snapshot::document( array( 'source' => 'live', 'options' => array( 'wcct_settings' => array() ) ), '3.0.0', 1767225600 );

        $this->assertSame( 'wcct-settings-backup', $doc['format'] );
        $this->assertSame( 'live', $doc['source'] );
        $this->assertSame( '3.0.0', $doc['plugin_version'] );
        $this->assertSame( '2026-01-01T00:00:00+00:00', $doc['created_at'] );
        $this->assertSame( 'conversion-tracking-settings-before-3.0-2026-01-01.json', Snapshot::filename( 1767225600 ) );
    }

    private static function reader( array $stored ) {
        return function ( $option ) use ( $stored ) {
            return array_key_exists( $option, $stored ) ? $stored[ $option ] : null;
        };
    }
}
