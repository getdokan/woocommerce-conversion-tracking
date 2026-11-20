<?php

namespace StorePulse\ConversionTracking\Settings;

use StorePulse\ConversionTracking\Migration\SettingsMigration;

/**
 * Reads and writes the 3.0 settings option; the 2.x option is only ever read.
 */
class Store {

    /**
     * The 3.0 settings option.
     */
    const OPTION = SettingsMigration::OPTION;

    /**
     * The 2.x settings option, read-only.
     */
    const V1_OPTION = SettingsMigration::V1_OPTION;

    /**
     * The stored 3.0 settings, or the 2.x settings read as 3.0 until the migration has written them.
     *
     * @return array
     */
    public static function load(): array {
        $doc = get_option( self::OPTION, null );

        if ( null !== $doc ) {
            return is_array( $doc ) ? $doc : array();
        }

        return LegacyView::from_v1( get_option( self::V1_OPTION, array() ) );
    }

    /**
     * Whether the 2.x settings still wait for the migration, which owns the first 3.0 write.
     *
     * @return bool
     */
    public static function migration_pending(): bool {
        return SettingsMigration::trigger()->is_due();
    }

    /**
     * Save the 3.0 settings.
     *
     * @param array $doc Settings.
     *
     * @return void
     */
    public static function save( array $doc ) {
        // Autoloaded: the storefront reads it on every page.
        update_option( self::OPTION, $doc, true );
    }
}
