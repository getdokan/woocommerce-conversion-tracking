<?php

use StorePulse\ConversionTracking\Migration\OptionStore;

/**
 * Option store held in memory; "cache" and "fresh" reads can be made to differ.
 */
class InMemoryOptionStore implements OptionStore {

    /**
     * Rows as the database holds them.
     *
     * @var array
     */
    public $options = array();

    /**
     * Rows as a request's cache saw them; null means same as the database.
     *
     * @var array|null
     */
    public $cache = null;

    /**
     * Called before each read(); a string replaces the value, false reads as missing, null passes through.
     *
     * @var callable|null
     */
    public $on_read = null;

    /**
     * Writes, in order, for assertions.
     *
     * @var array
     */
    public $writes = array();

    public function exists( string $name, bool $fresh = false ): bool {
        $rows = ( $fresh || null === $this->cache ) ? $this->options : $this->cache;

        return array_key_exists( $name, $rows );
    }

    public function read( string $name ): ?string {
        if ( $this->on_read ) {
            $value = call_user_func( $this->on_read, $name );

            if ( false === $value ) {
                return null;
            }

            if ( null !== $value ) {
                return $value;
            }
        }

        return array_key_exists( $name, $this->options ) ? (string) $this->options[ $name ] : null;
    }

    public function insert( string $name, string $value ): bool {
        if ( array_key_exists( $name, $this->options ) ) {
            return false;
        }

        $this->options[ $name ] = $value;
        $this->writes[]         = array( 'insert', $name );

        return true;
    }

    public function replace( string $name, string $expected, string $value ): bool {
        if ( ! array_key_exists( $name, $this->options ) || $this->options[ $name ] !== $expected ) {
            return false;
        }

        $this->options[ $name ] = $value;
        $this->writes[]         = array( 'replace', $name );

        return true;
    }

    public function delete( string $name, string $expected ): bool {
        if ( ! array_key_exists( $name, $this->options ) || $this->options[ $name ] !== $expected ) {
            return false;
        }

        unset( $this->options[ $name ] );
        $this->writes[] = array( 'delete', $name );

        return true;
    }

    public function save( string $name, string $value ): bool {
        $this->options[ $name ] = $value;
        $this->writes[]         = array( 'save', $name );

        return true;
    }
}
