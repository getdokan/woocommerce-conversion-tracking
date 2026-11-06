<?php

namespace StorePulse\ConversionTracking\Security;

/**
 * Where encrypted secrets are kept.
 */
interface SecretStorage {

    /**
     * Stored payload, null when missing.
     *
     * @param string $option Option name.
     *
     * @return string|null
     */
    public function get( string $option ): ?string;

    /**
     * Store a payload.
     *
     * @param string $option  Option name.
     * @param string $payload Encrypted value.
     *
     * @return bool
     */
    public function put( string $option, string $payload ): bool;

    /**
     * Remove a payload.
     *
     * @param string $option Option name.
     *
     * @return bool
     */
    public function remove( string $option ): bool;
}
