<?php

namespace StorePulse\ConversionTracking\Security;

/**
 * Secret storage in non-autoloaded WordPress options.
 */
class OptionSecretStorage implements SecretStorage {

    /**
     * {@inheritDoc}
     */
    public function get( string $option ): ?string {
        $value = get_option( $option, null );

        return is_string( $value ) ? $value : null;
    }

    /**
     * {@inheritDoc}
     */
    public function put( string $option, string $payload ): bool {
        return update_option( $option, $payload, false );
    }

    /**
     * {@inheritDoc}
     */
    public function remove( string $option ): bool {
        return delete_option( $option );
    }
}
