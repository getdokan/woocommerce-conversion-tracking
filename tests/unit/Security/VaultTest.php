<?php

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Security\Cipher;
use StorePulse\ConversionTracking\Security\Vault;

/**
 * Tests for encrypted secret storage
 */
class VaultTest extends TestCase {

    /**
     * @var InMemorySecretStorage
     */
    private $storage;

    /**
     * Constants the vault can see.
     *
     * @var array
     */
    private $constants = array();

    protected function setUp(): void {
        if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
            $this->markTestSkipped( 'Needs ext-sodium.' );
        }

        $this->storage   = new InMemorySecretStorage();
        $this->constants = array();
    }

    private function vault( $key = 'site-salt' ): Vault {
        return new Vault(
            $this->storage,
            null === $key ? null : new Cipher( $key ),
            function ( $name ) {
                return $this->constants[ $name ] ?? null;
            }
        );
    }

    public function test_round_trip() {
        $vault = $this->vault();

        $this->assertTrue( $vault->set( 'feed_password', 'hunter2' ) );
        $this->assertSame( 'hunter2', $vault->get( 'feed_password' ) );
        $this->assertTrue( $vault->has( 'feed_password' ) );
    }

    public function test_stored_option_is_not_the_plaintext() {
        $this->vault()->set( 'feed_password', 'hunter2' );

        $stored = $this->storage->options['wcct_secret_feed_password'];

        $this->assertStringStartsWith( Cipher::PREFIX, $stored );
        $this->assertStringNotContainsString( 'hunter2', $stored );
    }

    public function test_wrong_key_reads_as_missing() {
        $this->vault( 'old-salt' )->set( 'feed_password', 'hunter2' );

        $vault = $this->vault( 'rotated-salt' );

        $this->assertNull( $vault->get( 'feed_password' ) );
        $this->assertFalse( $vault->has( 'feed_password' ) );
        $this->assertTrue( $vault->is_set( 'feed_password' ) );
    }

    public function test_empty_or_false_constant_is_no_override() {
        $vault = $this->vault();

        foreach ( array( false, '' ) as $value ) {
            $this->constants['WCCT_SECRET_FEED_PASSWORD'] = $value;

            $this->assertTrue( $vault->set( 'feed_password', 'hunter2' ) );
            $this->assertSame( 'hunter2', $vault->get( 'feed_password' ) );
        }
    }

    public function test_is_set_without_any_value() {
        $this->assertFalse( $this->vault()->is_set( 'feed_password' ) );
    }

    public function test_missing_value() {
        $vault = $this->vault();

        $this->assertNull( $vault->get( 'feed_password' ) );
        $this->assertFalse( $vault->has( 'feed_password' ) );
        $this->assertFalse( $vault->delete( 'feed_password' ) );
    }

    public function test_constant_overrides_the_stored_value() {
        $vault = $this->vault();
        $vault->set( 'feed_password', 'stored' );

        $this->constants['WCCT_SECRET_FEED_PASSWORD'] = 'from-config';

        $this->assertSame( 'from-config', $vault->get( 'feed_password' ) );
        $this->assertTrue( $vault->has( 'feed_password' ) );
    }

    public function test_set_is_refused_while_the_constant_is_defined() {
        $this->constants['WCCT_SECRET_FEED_PASSWORD'] = 'from-config';

        $this->assertFalse( $this->vault()->set( 'feed_password', 'new' ) );
        $this->assertSame( array(), $this->storage->options );
    }

    public function test_delete_removes_the_option() {
        $vault = $this->vault();
        $vault->set( 'feed_password', 'hunter2' );

        $this->assertTrue( $vault->delete( 'feed_password' ) );
        $this->assertArrayNotHasKey( 'wcct_secret_feed_password', $this->storage->options );
        $this->assertNull( $vault->get( 'feed_password' ) );
    }

    public function test_without_a_cipher_nothing_is_stored_in_plaintext() {
        $vault = $this->vault( null );

        $this->assertFalse( $vault->set( 'feed_password', 'hunter2' ) );
        $this->assertSame( array(), $this->storage->options );
        $this->assertNull( $vault->get( 'feed_password' ) );
    }

    public function test_constant_still_works_without_a_cipher() {
        $this->constants['WCCT_SECRET_FEED_PASSWORD'] = 'from-config';

        $this->assertSame( 'from-config', $this->vault( null )->get( 'feed_password' ) );
    }

    /**
     * @dataProvider invalid_names
     */
    public function test_invalid_names_are_rejected( $name ) {
        $vault = $this->vault();

        $this->assertFalse( $vault->set( $name, 'x' ) );
        $this->assertNull( $vault->get( $name ) );
        $this->assertSame( array(), $this->storage->options );
    }

    public function invalid_names() {
        return array(
            'empty'      => array( '' ),
            'upper case' => array( 'Feed_Password' ),
            'dash'       => array( 'feed-password' ),
            'too long'   => array( str_repeat( 'a', 65 ) ),
        );
    }

    public function test_names_map_to_option_and_constant() {
        $this->assertSame( 'wcct_secret_feed_password', Vault::option_name( 'feed_password' ) );
        $this->assertSame( 'WCCT_SECRET_FEED_PASSWORD', Vault::constant_name( 'feed_password' ) );
    }
}
