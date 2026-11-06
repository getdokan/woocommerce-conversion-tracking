<?php

namespace StorePulse\ConversionTracking;

/**
 * Upgraded from 2.x or new on 3.0, recorded once on the first 3.0 run; every default that differs for upgraders reads is_upgrade().
 */
class Install {

    /**
     * Option holding the install type.
     */
    const OPTION = 'wcct_install_type';

    /**
     * The store ran 2.x and had saved settings before 3.0 first ran.
     */
    const UPGRADE = 'upgrade';

    /**
     * The store started on 3.0.
     */
    const FRESH = 'new';

    /**
     * Record the install type on the first 3.0 run; returns it.
     *
     * @return string
     */
    public static function record() {
        $type = self::stored();

        if ( '' === $type ) {
            $type = self::detect();
            add_option( self::OPTION, $type );
        }

        return $type;
    }

    /**
     * The install type: the recorded one, else what the stored settings say now.
     *
     * @return string 'upgrade' or 'new'.
     */
    public static function type() {
        $type = self::stored();

        return '' !== $type ? $type : self::detect();
    }

    /**
     * Whether the store was upgraded from 2.x.
     *
     * @return bool
     */
    public static function is_upgrade() {
        return self::UPGRADE === self::type();
    }

    /**
     * The recorded type, or an empty string.
     *
     * @return string
     */
    private static function stored() {
        $type = get_option( self::OPTION, '' );

        return in_array( $type, array( self::UPGRADE, self::FRESH ), true ) ? $type : '';
    }

    /**
     * 2.x saved settings mean an upgrade.
     *
     * @return string
     */
    private static function detect() {
        $legacy = get_option( 'wcct_settings', array() );

        return is_array( $legacy ) && ! empty( $legacy ) ? self::UPGRADE : self::FRESH;
    }
}
