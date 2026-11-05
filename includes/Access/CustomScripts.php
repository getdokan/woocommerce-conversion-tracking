<?php

namespace StorePulse\ConversionTracking\Access;

/**
 * Keeps the Custom integration scripts out of reach of users without unfiltered_html.
 */
class CustomScripts {

    /**
     * Capability needed to change the scripts.
     */
    const CAPABILITY = 'unfiltered_html';

    /**
     * Integration id the scripts live under.
     */
    const INTEGRATION = 'custom';

    /**
     * Script fields of that integration.
     */
    const FIELDS = array( 'checkout', 'registration' );

    /**
     * Whether the new settings change a stored script; an absent field counts as unchanged.
     *
     * @param array $new New, unslashed settings.
     * @param array $old Stored settings.
     *
     * @return bool
     */
    public static function changed( array $new, array $old ): bool {
        $posted = self::scripts_of( $new );
        $stored = self::scripts_of( $old );

        foreach ( self::FIELDS as $field ) {
            if ( ! array_key_exists( $field, $posted ) ) {
                continue;
            }

            if ( ! is_scalar( $posted[ $field ] ) ) {
                return true;
            }

            $before = isset( $stored[ $field ] ) && is_scalar( $stored[ $field ] ) ? $stored[ $field ] : '';

            if ( self::normalize( $posted[ $field ] ) !== self::normalize( $before ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Put the stored scripts back into the new settings.
     *
     * @param array $new New settings.
     * @param array $old Stored settings.
     *
     * @return array
     */
    public static function keep( array $new, array $old ): array {
        $stored  = array_intersect_key( self::scripts_of( $old ), array_flip( self::FIELDS ) );
        $present = isset( $new[ self::INTEGRATION ] ) && is_array( $new[ self::INTEGRATION ] );

        if ( ! $stored && ! $present ) {
            return $new;
        }

        // An unchecked toggle posts nothing for the integration.
        $custom = $present ? $new[ self::INTEGRATION ] : array( 'enabled' => false );
        $custom = array_merge( array_diff_key( $custom, array_flip( self::FIELDS ) ), $stored );

        $new[ self::INTEGRATION ] = $custom;

        return $new;
    }

    /**
     * The Custom integration's settings, or an empty array.
     *
     * @param array $settings All integration settings.
     *
     * @return array
     */
    private static function scripts_of( array $settings ): array {
        return isset( $settings[ self::INTEGRATION ] ) && is_array( $settings[ self::INTEGRATION ] ) ? $settings[ self::INTEGRATION ] : array();
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
