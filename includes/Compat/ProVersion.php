<?php

namespace StorePulse\ConversionTracking\Compat;

/**
 * Which Pro runs next to Free: none, one built for this Free, or an older one that Free pauses.
 *
 * @since PLUGIN_SINCE
 */
class ProVersion {

    /**
     * Oldest Pro that works with this Free.
     */
    const MINIMUM = '3.0.0';

    /**
     * No Pro loaded.
     */
    const NONE = 'none';

    /**
     * A Pro older than MINIMUM; Free pauses it.
     */
    const OUTDATED = 'outdated';

    /**
     * A Pro built for this Free.
     */
    const COMPATIBLE = 'compatible';

    /**
     * The state for a Pro version; an empty version means no Pro is loaded
     *
     * @param mixed $version Pro version.
     *
     * @return string One of NONE, OUTDATED, COMPATIBLE.
     */
    public static function state( $version ) {
        $version = is_scalar( $version ) ? trim( (string) $version ) : '';

        if ( '' === $version ) {
            return self::NONE;
        }

        return self::at_least( $version, self::MINIMUM ) ? self::COMPATIBLE : self::OUTDATED;
    }

    /**
     * Whether a version reaches a minimum; 3.0 reads as 3.0.0 and a pre-release such as 3.0.0-beta.1 counts as its release
     *
     * @param string $version Version.
     * @param string $minimum Minimum version.
     *
     * @return bool
     */
    public static function at_least( $version, $minimum ) {
        $release = explode( '.', preg_replace( '/[-+].*$/', '', (string) $version ) );

        return version_compare( implode( '.', array_pad( $release, 3, '0' ) ), $minimum, '>=' );
    }

    /**
     * The loaded Pro's version, empty when none
     *
     * @return string
     */
    public static function installed() {
        return defined( 'WCCT_PRO_VERSION' ) && is_scalar( WCCT_PRO_VERSION ) ? (string) WCCT_PRO_VERSION : '';
    }

    /**
     * The loaded Pro's state
     *
     * @return string
     */
    public static function current() {
        return self::state( self::installed() );
    }

    /**
     * Whether a Pro built for this Free is running
     *
     * @return bool
     */
    public static function is_active() {
        return self::COMPATIBLE === self::current();
    }

    /**
     * Whether an older Pro is installed and paused
     *
     * @return bool
     */
    public static function is_outdated() {
        return self::OUTDATED === self::current();
    }

    /**
     * The major.minor of MINIMUM, for notices
     *
     * @return string
     */
    public static function minimum_label() {
        return implode( '.', array_slice( explode( '.', self::MINIMUM ), 0, 2 ) );
    }
}
