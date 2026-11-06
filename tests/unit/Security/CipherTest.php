<?php

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Security\Cipher;

/**
 * Tests for the secretbox cipher
 */
class CipherTest extends TestCase {

    protected function setUp(): void {
        if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
            $this->markTestSkipped( 'Needs ext-sodium.' );
        }
    }

    public function test_round_trip() {
        $cipher = new Cipher( 'site-salt' );

        $this->assertSame( 's3cret pässwörd', $cipher->decrypt( $cipher->encrypt( 's3cret pässwörd' ) ) );
        $this->assertSame( '', $cipher->decrypt( $cipher->encrypt( '' ) ) );
    }

    public function test_payload_is_not_the_plaintext_and_uses_a_fresh_nonce() {
        $cipher = new Cipher( 'site-salt' );
        $first  = $cipher->encrypt( 'hunter2' );

        $this->assertStringStartsWith( Cipher::PREFIX, $first );
        $this->assertStringNotContainsString( 'hunter2', $first );
        $this->assertNotSame( $first, $cipher->encrypt( 'hunter2' ) );
    }

    public function test_wrong_key_fails() {
        $payload = ( new Cipher( 'site-salt' ) )->encrypt( 'hunter2' );

        $this->assertNull( ( new Cipher( 'other-salt' ) )->decrypt( $payload ) );
    }

    public function test_tampered_payload_fails() {
        $cipher  = new Cipher( 'site-salt' );
        $raw     = base64_decode( substr( $cipher->encrypt( 'hunter2' ), strlen( Cipher::PREFIX ) ) );
        $raw[30] = chr( ord( $raw[30] ) ^ 1 );

        $this->assertNull( $cipher->decrypt( Cipher::PREFIX . base64_encode( $raw ) ) );
    }

    /**
     * @dataProvider malformed_payloads
     */
    public function test_malformed_payload_fails( $payload ) {
        $this->assertNull( ( new Cipher( 'site-salt' ) )->decrypt( $payload ) );
    }

    public function malformed_payloads() {
        return array(
            'plaintext'      => array( 'hunter2' ),
            'empty'          => array( '' ),
            'prefix only'    => array( Cipher::PREFIX ),
            'not base64'     => array( Cipher::PREFIX . '!!!' ),
            'too short'      => array( Cipher::PREFIX . base64_encode( 'short' ) ),
            'other format'   => array( 'wcct:xx9:' . base64_encode( str_repeat( 'a', 64 ) ) ),
        );
    }

    public function test_empty_key_material_is_rejected() {
        $this->expectException( InvalidArgumentException::class );

        new Cipher( '' );
    }
}
