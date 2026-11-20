<?php

use StorePulse\ConversionTracking\Security\SecretStorage;

/**
 * Secret storage held in memory.
 */
class InMemorySecretStorage implements SecretStorage {

    /**
     * @var array
     */
    public $options = array();

    public function get( string $option ): ?string {
        return $this->options[ $option ] ?? null;
    }

    public function put( string $option, string $payload ): bool {
        $this->options[ $option ] = $payload;

        return true;
    }

    public function remove( string $option ): bool {
        if ( ! array_key_exists( $option, $this->options ) ) {
            return false;
        }

        unset( $this->options[ $option ] );

        return true;
    }
}
