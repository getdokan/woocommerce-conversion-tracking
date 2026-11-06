<?php

namespace StorePulse\ConversionTracking\Security;

/**
 * Encrypted secret storage with a constant override per secret.
 */
class Vault {

    /**
     * Option name prefix; one option per secret.
     */
    const OPTION_PREFIX = 'wcct_secret_';

    /**
     * Constant name prefix for the wp-config.php override.
     */
    const CONSTANT_PREFIX = 'WCCT_SECRET_';

    /**
     * @var SecretStorage
     */
    private $storage;

    /**
     * Null when no secretbox implementation is available.
     *
     * @var Cipher|null
     */
    private $cipher;

    /**
     * @var callable
     */
    private $constant;

    /**
     * Constructor.
     *
     * @param SecretStorage $storage  Where payloads are kept.
     * @param Cipher|null   $cipher   Cipher, null when encryption is unavailable.
     * @param callable|null $constant Returns a constant's value, or null when undefined.
     */
    public function __construct( SecretStorage $storage, ?Cipher $cipher, ?callable $constant = null ) {
        $this->storage  = $storage;
        $this->cipher   = $cipher;
        $this->constant = $constant ? $constant : array( self::class, 'read_constant' );
    }

    /**
     * The secret, from its constant when defined, otherwise decrypted from storage.
     *
     * @param string $name Secret name, lower-case letters, digits and underscores.
     *
     * @return string|null Null when missing or not decryptable.
     */
    public function get( string $name ): ?string {
        if ( ! self::is_valid_name( $name ) ) {
            return null;
        }

        $override = $this->constant_value( $name );

        if ( null !== $override ) {
            return $override;
        }

        $payload = $this->storage->get( self::option_name( $name ) );

        if ( null === $payload || null === $this->cipher ) {
            return null;
        }

        return $this->cipher->decrypt( $payload );
    }

    /**
     * Encrypt and store a secret; refused while its constant is defined or encryption is unavailable.
     *
     * @param string $name  Secret name.
     * @param string $value Plaintext value.
     *
     * @return bool
     */
    public function set( string $name, string $value ): bool {
        if ( ! self::is_valid_name( $name ) || null === $this->cipher || null !== $this->constant_value( $name ) ) {
            return false;
        }

        return $this->storage->put( self::option_name( $name ), $this->cipher->encrypt( $value ) );
    }

    /**
     * Delete the stored secret; a constant override is not affected.
     *
     * @param string $name Secret name.
     *
     * @return bool
     */
    public function delete( string $name ): bool {
        if ( ! self::is_valid_name( $name ) ) {
            return false;
        }

        return $this->storage->remove( self::option_name( $name ) );
    }

    /**
     * Whether a usable value exists, from the constant or storage.
     *
     * @param string $name Secret name.
     *
     * @return bool
     */
    public function has( string $name ): bool {
        return null !== $this->get( $name );
    }

    /**
     * Whether a value was set, even one that no longer decrypts after a key change.
     *
     * @param string $name Secret name.
     *
     * @return bool
     */
    public function is_set( string $name ): bool {
        if ( ! self::is_valid_name( $name ) ) {
            return false;
        }

        return null !== $this->constant_value( $name ) || null !== $this->storage->get( self::option_name( $name ) );
    }

    /**
     * Option that holds a secret.
     *
     * @param string $name Secret name.
     *
     * @return string
     */
    public static function option_name( string $name ): string {
        return self::OPTION_PREFIX . $name;
    }

    /**
     * Constant that overrides a secret.
     *
     * @param string $name Secret name.
     *
     * @return string
     */
    public static function constant_name( string $name ): string {
        return self::CONSTANT_PREFIX . strtoupper( $name );
    }

    /**
     * Default constant reader.
     *
     * @param string $constant Constant name.
     *
     * @return mixed|null
     */
    public static function read_constant( string $constant ) {
        return defined( $constant ) ? constant( $constant ) : null;
    }

    /**
     * Value of the override constant as a string, null when undefined.
     *
     * @param string $name Secret name.
     *
     * @return string|null
     */
    private function constant_value( string $name ): ?string {
        $value = call_user_func( $this->constant, self::constant_name( $name ) );

        // An empty or false constant means no override.
        return ! is_scalar( $value ) || false === $value || '' === $value ? null : (string) $value;
    }

    /**
     * Names map to option and constant names, so keep them simple.
     *
     * @param string $name Secret name.
     *
     * @return bool
     */
    private static function is_valid_name( string $name ): bool {
        return 1 === preg_match( '/^[a-z0-9_]{1,64}$/', $name );
    }
}
