<?php

namespace StorePulse\ConversionTracking\Security;

/**
 * Authenticated encryption with libsodium secretbox (XSalsa20-Poly1305).
 */
class Cipher {

    /**
     * Prefix of every payload; names the format so it can change later.
     */
    const PREFIX = 'wcct:sb1:';

    const NONCE_BYTES = 24;
    const MAC_BYTES   = 16;

    /**
     * 32-byte secretbox key.
     *
     * @var string
     */
    private $key;

    /**
     * Constructor.
     *
     * @param string $key_material Secret the key is derived from, such as the WordPress salts.
     *
     * @throws \InvalidArgumentException When the key material is empty.
     */
    public function __construct( string $key_material ) {
        if ( '' === $key_material ) {
            throw new \InvalidArgumentException( 'Key material must not be empty.' );
        }

        $this->key = hash_hmac( 'sha256', 'wcct-secrets', $key_material, true );
    }

    /**
     * Whether secretbox is available (ext-sodium, or the sodium_compat copy WordPress loads).
     *
     * @return bool
     */
    public static function is_available(): bool {
        return function_exists( 'sodium_crypto_secretbox' ) && function_exists( 'sodium_crypto_secretbox_open' );
    }

    /**
     * Encrypt a value; a new random nonce is used on every call.
     *
     * @param string $plaintext Value to encrypt.
     *
     * @return string
     */
    public function encrypt( string $plaintext ): string {
        $nonce = random_bytes( self::NONCE_BYTES );

        return self::PREFIX . base64_encode( $nonce . sodium_crypto_secretbox( $plaintext, $nonce, $this->key ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
    }

    /**
     * Decrypt a payload; null when it is malformed, tampered with or made with another key.
     *
     * @param string $payload Value returned by encrypt().
     *
     * @return string|null
     */
    public function decrypt( string $payload ): ?string {
        if ( 0 !== strpos( $payload, self::PREFIX ) ) {
            return null;
        }

        $raw = base64_decode( substr( $payload, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

        if ( false === $raw || strlen( $raw ) < self::NONCE_BYTES + self::MAC_BYTES ) {
            return null;
        }

        try {
            $plaintext = sodium_crypto_secretbox_open( substr( $raw, self::NONCE_BYTES ), substr( $raw, 0, self::NONCE_BYTES ), $this->key );
        } catch ( \Throwable $e ) {
            return null;
        }

        return false === $plaintext ? null : $plaintext;
    }
}
