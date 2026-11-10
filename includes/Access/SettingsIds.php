<?php

namespace StorePulse\ConversionTracking\Access;

/**
 * Keeps saved platform IDs and labels to ID characters so they can't break out of the scripts they are printed in.
 */
class SettingsIds {

    /**
     * Strip everything but ID characters from one string.
     *
     * @param string $value Value.
     *
     * @return string
     */
    public static function clean_id( string $value ): string {
        // Pixel IDs, tag IDs and Google labels (AW-123/abc) only need these.
        return preg_replace( '/[^A-Za-z0-9_\-\/\.:]/', '', $value );
    }
}
