<?php

namespace StorePulse\ConversionTracking\Migration;

use StorePulse\ConversionTracking\Platforms\Pause;
use StorePulse\ConversionTracking\Settings\Google;
use StorePulse\ConversionTracking\Settings\LegacyView;

/**
 * 2.x integrations switched off with their ID saved, as pauses (Platforms\Pause, wcct_paused_platforms); every write goes through Pause::set().
 */
class LegacyPause {

    /**
     * 2.x integrations that are their own platform => the key of their ID at index 0.
     */
    const ID_KEYS = array(
        'facebook' => 'pixel_id',
        'twitter'  => 'pixel_id',
        'bing_ads' => 'tag_id',
    );

    /**
     * 2.x groups a settings save decides, each with its integrations.
     */
    const GROUPS = array(
        'facebook' => array( 'facebook' ),
        'google'   => array( 'ga4', 'adwords' ),
        'twitter'  => array( 'twitter' ),
        'bing_ads' => array( 'bing_ads' ),
        'custom'   => array( 'custom' ),
    );

    /**
     * Pause list entries for the switched-off 2.x integrations that have an ID (or, for Custom, a script).
     *
     * @param mixed $v1     The wcct_settings option.
     * @param bool  $custom Include Custom; a 2.x settings save switches Custom's placements instead (#94).
     *
     * @return string[]
     */
    public static function ids( $v1, bool $custom = true ): array {
        $v1  = is_array( $v1 ) ? $v1 : array();
        $ids = array();

        if ( self::off_with_id( $v1, 'facebook' ) ) {
            $ids[] = 'facebook';
        }

        $ga4 = '' !== self::id( $v1, 'ga4' );
        $ads = '' !== self::id( $v1, 'adwords' );

        // Google is one platform: both off is a Google pause, one off pauses only that integration.
        if ( ( $ga4 || $ads ) && ! self::google_on( $v1, 'ga4' ) && ! self::google_on( $v1, 'adwords' ) ) {
            $ids[] = 'google';
        } else {
            if ( $ga4 && ! self::google_on( $v1, 'ga4' ) ) {
                $ids[] = 'ga4';
            }

            if ( $ads && ! self::google_on( $v1, 'adwords' ) ) {
                $ids[] = 'adwords';
            }
        }

        foreach ( array( 'twitter', 'bing_ads' ) as $id ) {
            if ( self::off_with_id( $v1, $id ) ) {
                $ids[] = $id;
            }
        }

        if ( $custom && isset( $v1['custom'] ) && is_array( $v1['custom'] ) && ! Google::enabled( $v1['custom'] ) && self::has_script( $v1['custom'] ) ) {
            $ids[] = 'custom';
        }

        return $ids;
    }

    /**
     * The pause entries of each 2.x group: the platform and, for Google, each integration alone.
     *
     * @param string $group Group key from GROUPS.
     *
     * @return string[]
     */
    private static function entries( string $group ): array {
        return array_values( array_unique( array_merge( array( $group ), self::GROUPS[ $group ] ) ) );
    }

    /**
     * Groups a 2.x save changed: an integration's switch or ID (Custom: scripts) differs, or there was no earlier save.
     *
     * @param mixed      $v1  The saved wcct_settings option.
     * @param mixed|null $old The option before the save, null when it was just created.
     *
     * @return string[]
     */
    public static function changed( $v1, $old = null ): array {
        $v1      = is_array( $v1 ) ? $v1 : array();
        $old     = is_array( $old ) ? $old : null;
        $changed = array();

        foreach ( self::GROUPS as $group => $integrations ) {
            $held = false;
            $diff = null === $old;

            foreach ( $integrations as $id ) {
                $held = $held || ( isset( $v1[ $id ] ) && is_array( $v1[ $id ] ) );
                $diff = $diff || self::signature( $v1, $id ) !== self::signature( $old, $id );
            }

            if ( $held && $diff ) {
                $changed[] = $group;
            }
        }

        return $changed;
    }

    /**
     * The 3.0 settings read from 2.x with "off" as a pause: Google's mode follows its saved IDs and Custom's placements its saved scripts.
     *
     * @param array $settings 3.0 settings from LegacyView::from_v1().
     * @param mixed $v1       The wcct_settings option.
     * @param bool  $custom   Read a switched-off Custom as paused (the migration) rather than as switched-off placements.
     *
     * @return array
     */
    public static function apply( array $settings, $v1, bool $custom = true ): array {
        $ids = self::ids( $v1, $custom );

        if ( isset( $settings['platforms']['google'] ) && is_array( $settings['platforms']['google'] ) ) {
            $google    = $settings['platforms']['google'];
            $analytics = isset( $google['measurement_id'] ) && '' !== $google['measurement_id'];
            $ads       = isset( $google['ads_id'] ) && '' !== $google['ads_id'];

            if ( $analytics || $ads ) {
                $settings['platforms']['google']['mode'] = $analytics && $ads ? Google::MODE_BOTH : ( $analytics ? Google::MODE_ANALYTICS : Google::MODE_ADS );
            }
        }

        if ( in_array( 'custom', $ids, true ) && isset( $settings['platforms']['custom'] ) && is_array( $settings['platforms']['custom'] ) ) {
            foreach ( array( 'thankyou', 'signup' ) as $placement ) {
                $script = isset( $settings['platforms']['custom'][ $placement . '_script' ] ) ? $settings['platforms']['custom'][ $placement . '_script' ] : '';

                $settings['platforms']['custom'][ $placement . '_enabled' ] = is_string( $script ) && '' !== $script;
            }
        }

        return $settings;
    }

    /**
     * Add the 2.x switched-off integrations to the pauses (the migration); pauses already there stay.
     *
     * @param mixed $v1 The wcct_settings option.
     *
     * @throws \RuntimeException When a pause can't be saved, so the 3.0 settings aren't written without it.
     *
     * @return void
     */
    public static function migrate( $v1 ) {
        foreach ( self::ids( $v1 ) as $id ) {
            // The stored list, not Pause::ids(): before the first write that reads the 2.x flags.
            if ( ! in_array( $id, (array) Pause::stored(), true ) ) {
                Pause::set( $id, true );
            }

            if ( ! in_array( $id, (array) Pause::stored(), true ) ) {
                throw new \RuntimeException( 'The paused platforms could not be saved.' );
            }
        }
    }

    /**
     * After a 2.x settings save, re-read the entries of the integrations whose switch or ID changed; every other entry stays.
     *
     * @param mixed      $v1  The saved wcct_settings option.
     * @param mixed|null $old The option before the save, null when it was just created.
     *
     * @return void
     */
    public static function sync( $v1, $old = null ) {
        $drop = array();

        foreach ( self::changed( $v1, $old ) as $group ) {
            $drop = array_merge( $drop, self::entries( $group ) );
        }

        if ( ! $drop ) {
            return;
        }

        $want    = array_intersect( self::ids( $v1, false ), $drop );
        $current = Pause::ids();

        // Resume first: resuming google also clears ga4 or adwords alone, which a pause below may set again.
        foreach ( array_diff( array_intersect( $current, $drop ), $want ) as $id ) {
            Pause::set( $id, false );
        }

        foreach ( array_diff( $want, Pause::ids() ) as $id ) {
            Pause::set( $id, true );
        }
    }

    /**
     * Whether a 2.x integration is switched off with its ID saved.
     *
     * @param array  $v1 The wcct_settings option.
     * @param string $id Integration ID.
     *
     * @return bool
     */
    private static function off_with_id( array $v1, string $id ): bool {
        return isset( $v1[ $id ] ) && is_array( $v1[ $id ] ) && ! Google::enabled( $v1[ $id ] ) && '' !== self::id( $v1, $id );
    }

    /**
     * The saved ID of a 2.x integration, or an empty string.
     *
     * @param array  $v1 The wcct_settings option.
     * @param string $id Integration ID.
     *
     * @return string
     */
    private static function id( array $v1, string $id ): string {
        $first = LegacyView::first( $v1, $id );
        $keys  = array(
            'ga4'     => 'measurement_id',
            'adwords' => 'account_id',
        ) + self::ID_KEYS;

        if ( null === $first || ! isset( $keys[ $id ] ) ) {
            return '';
        }

        $value = isset( $first[ $keys[ $id ] ] ) && is_scalar( $first[ $keys[ $id ] ] ) ? trim( (string) $first[ $keys[ $id ] ] ) : '';

        // 2.x X kept its pixel as the universal tag, inside events.
        if ( '' === $value && 'twitter' === $id && isset( $first['events']['universal_tag_id'] ) && is_scalar( $first['events']['universal_tag_id'] ) ) {
            $value = trim( (string) $first['events']['universal_tag_id'] );
        }

        return $value;
    }

    /**
     * Whether a Google integration is switched on, read as the google platform reads it.
     *
     * @param array  $v1 The wcct_settings option.
     * @param string $id ga4 or adwords.
     *
     * @return bool
     */
    private static function google_on( array $v1, string $id ): bool {
        return isset( $v1[ $id ] ) && is_array( $v1[ $id ] ) && Google::enabled( $v1[ $id ] );
    }

    /**
     * What a 2.x save can change about an integration: held, switch, ID (Custom: scripts).
     *
     * @param array|null $v1 The wcct_settings option.
     * @param string     $id Integration ID.
     *
     * @return array
     */
    private static function signature( $v1, string $id ): array {
        if ( ! is_array( $v1 ) || ! isset( $v1[ $id ] ) || ! is_array( $v1[ $id ] ) ) {
            return array();
        }

        if ( 'custom' === $id ) {
            return array( Google::enabled( $v1[ $id ] ), self::has_script( $v1[ $id ] ) );
        }

        return array( Google::enabled( $v1[ $id ] ), self::id( $v1, $id ) );
    }

    /**
     * Whether a 2.x Custom entry holds a script.
     *
     * @param array $custom 2.x Custom settings.
     *
     * @return bool
     */
    private static function has_script( array $custom ): bool {
        foreach ( array( 'checkout', 'registration' ) as $key ) {
            if ( isset( $custom[ $key ] ) && is_string( $custom[ $key ] ) && '' !== trim( $custom[ $key ] ) ) {
                return true;
            }
        }

        return false;
    }
}
