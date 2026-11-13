<?php

namespace StorePulse\ConversionTracking\Platforms;

use StorePulse\ConversionTracking\Settings\LegacyView;
use StorePulse\ConversionTracking\Settings\Store;

/**
 * Meta's layer over PlatformSettings: its values with every pixel, and the platform event of a Meta event.
 */
class MetaSettings {

    /**
     * Platform ID.
     */
    const PLATFORM = 'facebook';

    /**
     * Meta event names whose 2.x settings key differs.
     */
    const SETTINGS_KEYS = array(
        'CompleteRegistration' => 'Registration',
    );

    /**
     * Meta's values: wcct_settings_v3 through PlatformSettings, or the 2.x settings read as 3.0 until the migration has run.
     *
     * @return array
     */
    public static function platform(): array {
        $values = PlatformSettings::values( self::PLATFORM );

        if ( null !== $values ) {
            return $values;
        }

        $doc = Store::load();

        return isset( $doc['platforms'][ self::PLATFORM ] ) && is_array( $doc['platforms'][ self::PLATFORM ] ) ? $doc['platforms'][ self::PLATFORM ] : array();
    }

    /**
     * 2.x settings key of a Meta event name.
     *
     * @param string $event_name Meta event name.
     *
     * @return string
     */
    public static function settings_key( $event_name ): string {
        $event_name = is_string( $event_name ) ? $event_name : '';

        return isset( self::SETTINGS_KEYS[ $event_name ] ) ? self::SETTINGS_KEYS[ $event_name ] : $event_name;
    }

    /**
     * Platform event of a Meta event name (or a 2.x settings key), from LegacyView::FACEBOOK_EVENTS; empty when unknown.
     *
     * @param string $event_name Meta event name.
     *
     * @return string
     */
    public static function event( $event_name ): string {
        $key = self::settings_key( $event_name );

        return isset( LegacyView::FACEBOOK_EVENTS[ $key ] ) ? LegacyView::FACEBOOK_EVENTS[ $key ] : '';
    }
}
