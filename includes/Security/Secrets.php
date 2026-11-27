<?php

namespace StorePulse\ConversionTracking\Security;

/**
 * Encrypted option storage for credentials, with a WCCT_SECRET_<NAME> wp-config.php override.
 */
class Secrets {

    /**
     * @var Vault|null
     */
    private static $vault = null;

    /**
     * The secret, or null when missing or not decryptable.
     *
     * @param string $name Secret name, such as feed_password.
     *
     * @return string|null
     */
    public static function get( string $name ): ?string {
        return self::vault()->get( $name );
    }

    /**
     * Encrypt and store a secret; false while WCCT_SECRET_<NAME> is defined.
     *
     * @param string $name  Secret name.
     * @param string $value Plaintext value.
     *
     * @return bool
     */
    public static function set( string $name, string $value ): bool {
        return self::vault()->set( $name, $value );
    }

    /**
     * Delete the stored secret's option.
     *
     * @param string $name Secret name.
     *
     * @return bool
     */
    public static function delete( string $name ): bool {
        return self::vault()->delete( $name );
    }

    /**
     * Whether the secret has a usable value.
     *
     * @param string $name Secret name.
     *
     * @return bool
     */
    public static function has( string $name ): bool {
        return self::vault()->has( $name );
    }

    /**
     * Whether the secret was set, even if it no longer decrypts; use it to fail closed after a salt change.
     *
     * @param string $name Secret name.
     *
     * @return bool
     */
    public static function is_set( string $name ): bool {
        return self::vault()->is_set( $name );
    }

    /**
     * Shared vault backed by options and keyed from WCCT_SECRETS_KEY or the WordPress auth salt.
     *
     * @return Vault
     */
    public static function vault(): Vault {
        if ( null === self::$vault ) {
            $material = defined( 'WCCT_SECRETS_KEY' ) && '' !== (string) WCCT_SECRETS_KEY ? (string) WCCT_SECRETS_KEY : wp_salt( 'auth' );
            $cipher   = Cipher::is_available() ? new Cipher( $material ) : null;

            self::$vault = new Vault( new OptionSecretStorage(), $cipher );
        }

        return self::$vault;
    }
}
