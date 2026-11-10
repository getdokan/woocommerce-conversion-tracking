<?php

namespace StorePulse\ConversionTracking\Access;

/**
 * Keeps the Custom script out of reach of users without unfiltered_html.
 */
class CustomScripts {

    /**
     * Capability needed to change the scripts.
     */
    const CAPABILITY = 'unfiltered_html';

    /**
     * Whether a new value differs from the stored one, ignoring line-break style.
     *
     * @param mixed $new New value.
     * @param mixed $old Stored value.
     *
     * @return bool
     */
    public static function differs( $new, $old ): bool {
        $new = is_string( $new ) ? self::normalize( $new ) : $new;
        $old = is_string( $old ) ? self::normalize( $old ) : $old;

        return $new !== $old;
    }

    /**
     * Browsers post textarea line breaks as CRLF.
     *
     * @param mixed $value Script.
     *
     * @return string
     */
    private static function normalize( $value ): string {
        return str_replace( array( "\r\n", "\r" ), "\n", (string) $value );
    }
}
