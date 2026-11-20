<?php

namespace StorePulse\ConversionTracking\Dashboard;

use StorePulse\ConversionTracking\Settings\Schema;
use StorePulse\ConversionTracking\Settings\Values;

/**
 * When each platform was connected, for "Connected 5 minutes ago" on the dashboard; 0 when we don't know (connected before 3.0 or outside the app).
 */
class ConnectedAt {

    /**
     * Option: platform ID => Unix time (0 for unknown). Not autoloaded.
     */
    const OPTION = 'wcct_connected_at';

    /**
     * Hook into WordPress.
     *
     * @return void
     */
    public static function register() {
        add_action( 'wcct_settings_saved', array( self::class, 'saved' ), 10, 2 );
    }

    /**
     * After a settings save, stamp the platforms that became connected and forget the ones that no longer are.
     *
     * @param array $new Saved settings.
     * @param array $old Settings before the save.
     *
     * @return void
     */
    public static function saved( $new, $old ) {
        if ( ! is_array( $new ) || ! is_array( $old ) ) {
            return;
        }

        $schema = Schema::get();
        $before = self::connected( $schema, Values::get( $schema, $old ) );
        $after  = self::connected( $schema, Values::get( $schema, $new ) );
        $known  = self::stored();
        $times  = self::update( null === $known ? array_fill_keys( $before, 0 ) : $known, $before, $after, time() );

        if ( $times !== $known ) {
            update_option( self::OPTION, $times, false );
        }
    }

    /**
     * The times after a save: newly connected platforms get the time, disconnected ones are dropped.
     *
     * @param int[]    $times  Platform ID => Unix time.
     * @param string[] $before Platforms connected before.
     * @param string[] $after  Platforms connected after.
     * @param int      $now    Unix time.
     *
     * @return int[]
     */
    public static function update( array $times, array $before, array $after, $now ) {
        foreach ( $after as $id ) {
            if ( ! in_array( $id, $before, true ) ) {
                $times[ $id ] = (int) $now;
            } elseif ( ! isset( $times[ $id ] ) ) {
                $times[ $id ] = 0;
            }
        }

        return array_intersect_key( $times, array_flip( $after ) );
    }

    /**
     * Connected platforms => when they were connected (0 for unknown); stores what changed.
     *
     * @param string[] $connected Platforms connected now.
     *
     * @return int[]
     */
    public static function get( array $connected ) {
        $known = self::stored();
        $times = self::update( null === $known ? array() : $known, $connected, $connected, 0 );

        if ( $times !== $known ) {
            update_option( self::OPTION, $times, false );
        }

        return $times;
    }

    /**
     * Connected platform IDs: the read-only connected value Values::get() gives each platform (PlatformSettings::connected()).
     *
     * @param array $schema Normalized schema.
     * @param array $values Values::get(), with a platforms key.
     *
     * @return string[]
     */
    public static function connected( array $schema, array $values ) {
        $ids = array();

        foreach ( array_keys( isset( $schema['platforms'] ) && is_array( $schema['platforms'] ) ? $schema['platforms'] : array() ) as $id ) {
            if ( ! empty( $values['platforms'][ $id ]['connected'] ) ) {
                $ids[] = (string) $id;
            }
        }

        return $ids;
    }

    /**
     * The stored times, or null when nothing was stored yet.
     *
     * @return int[]|null
     */
    private static function stored() {
        $times = get_option( self::OPTION, null );

        if ( ! is_array( $times ) ) {
            return null;
        }

        $clean = array();

        foreach ( $times as $id => $time ) {
            if ( is_string( $id ) && '' !== $id ) {
                $clean[ $id ] = max( 0, (int) $time );
            }
        }

        return $clean;
    }
}
