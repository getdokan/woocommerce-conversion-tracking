<?php

use StorePulse\ConversionTracking\Migration\OptionStore;

/**
 * Option store over $GLOBALS['wcct_test_options'], the same rows the get_option() fakes read, so the trigger and the migration body share state.
 */
class GlobalsOptionStore implements OptionStore {

    /**
     * Called at the start of each insert(), to let another "request" run first.
     *
     * @var callable|null
     */
    public $before_insert = null;

    public function exists( string $name, bool $fresh = false ): bool {
        return isset( $GLOBALS['wcct_test_options'] ) && array_key_exists( $name, $GLOBALS['wcct_test_options'] );
    }

    public function read( string $name ): ?string {
        if ( ! $this->exists( $name ) ) {
            return null;
        }

        $value = $GLOBALS['wcct_test_options'][ $name ];

        return is_scalar( $value ) ? (string) $value : serialize( $value ); // phpcs:ignore
    }

    public function insert( string $name, string $value ): bool {
        if ( $this->before_insert ) {
            $callback            = $this->before_insert;
            $this->before_insert = null;
            call_user_func( $callback );
        }

        if ( $this->exists( $name ) ) {
            return false;
        }

        $GLOBALS['wcct_test_options'][ $name ] = $value;

        return true;
    }

    public function replace( string $name, string $expected, string $value ): bool {
        if ( $this->read( $name ) !== $expected ) {
            return false;
        }

        $GLOBALS['wcct_test_options'][ $name ] = $value;

        return true;
    }

    public function delete( string $name, string $expected ): bool {
        if ( $this->read( $name ) !== $expected ) {
            return false;
        }

        unset( $GLOBALS['wcct_test_options'][ $name ] );

        return true;
    }

    public function save( string $name, string $value ): bool {
        $GLOBALS['wcct_test_options'][ $name ] = $value;

        return true;
    }
}
