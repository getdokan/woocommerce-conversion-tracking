<?php

namespace StorePulse\ConversionTracking\Access;

/**
 * Keeps saved platform IDs and labels to ID characters so they can't break out of the scripts they are printed in.
 */
class SettingsIds {

    /**
     * Integration whose fields hold scripts; those are guarded by CustomScripts.
     */
    const SCRIPT_INTEGRATION = 'custom';

    /**
     * Strip everything but ID characters from every value outside the custom scripts.
     *
     * @param array $settings Posted integration settings.
     *
     * @return array
     */
    public static function clean( array $settings ): array {
        foreach ( $settings as $integration => $values ) {
            if ( self::SCRIPT_INTEGRATION === $integration ) {
                continue;
            }

            $settings[ $integration ] = self::clean_value( $values );
        }

        return $settings;
    }

    /**
     * Clean one value, walking into arrays.
     *
     * @param mixed $value Value.
     *
     * @return mixed
     */
    private static function clean_value( $value ) {
        if ( is_array( $value ) ) {
            return array_map( array( self::class, 'clean_value' ), $value );
        }

        if ( ! is_string( $value ) ) {
            return $value;
        }

        // Pixel IDs, tag IDs and Google labels (AW-123/abc) only need these.
        return preg_replace( '/[^A-Za-z0-9_\-\/\.:]/', '', $value );
    }
}
