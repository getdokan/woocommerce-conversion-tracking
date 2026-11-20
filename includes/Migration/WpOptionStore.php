<?php

namespace StorePulse\ConversionTracking\Migration;

/**
 * Option store on the WordPress options table.
 */
class WpOptionStore implements OptionStore {

    /**
     * {@inheritDoc}
     */
    public function exists( string $name, bool $fresh = false ): bool {
        if ( ! $fresh ) {
            return false !== get_option( $name, false );
        }

        return null !== $this->read( $name );
    }

    /**
     * {@inheritDoc}
     */
    public function read( string $name ): ?string {
        global $wpdb;

        $value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ) );

        // A row exists, so drop any stale "not an option" entry a persistent cache may hold.
        if ( null !== $value ) {
            $this->forget( $name );
        }

        return null === $value ? null : (string) $value;
    }

    /**
     * {@inheritDoc}
     */
    public function insert( string $name, string $value ): bool {
        global $wpdb;

        // Not add_option(): it upserts after a cached check, so two callers can both "win".
        $inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $name, $value ) );

        return $this->changed( $name, $inserted );
    }

    /**
     * {@inheritDoc}
     */
    public function replace( string $name, string $expected, string $value ): bool {
        global $wpdb;

        $updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, $name, $expected ) );

        return $this->changed( $name, $updated );
    }

    /**
     * {@inheritDoc}
     */
    public function delete( string $name, string $expected ): bool {
        global $wpdb;

        $deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $expected ) );

        return $this->changed( $name, $deleted );
    }

    /**
     * {@inheritDoc}
     */
    public function save( string $name, string $value ): bool {
        return update_option( $name, $value, true );
    }

    /**
     * Drop cached copies after a raw write and report whether one row changed.
     *
     * @param string   $name Option name.
     * @param int|bool $rows Result of wpdb::query().
     *
     * @return bool
     */
    private function changed( string $name, $rows ): bool {
        $this->forget( $name );

        return 1 === $rows;
    }

    /**
     * Drop the cached copy of an option and its "not an option" entry.
     *
     * @param string $name Option name.
     *
     * @return void
     */
    private function forget( string $name ) {
        wp_cache_delete( $name, 'options' );

        $notoptions = wp_cache_get( 'notoptions', 'options' );

        if ( is_array( $notoptions ) && isset( $notoptions[ $name ] ) ) {
            unset( $notoptions[ $name ] );
            wp_cache_set( 'notoptions', $notoptions, 'options' );
        }
    }
}
