<?php

namespace StorePulse\ConversionTracking\Doctor;

/**
 * The wcct_doctor_checks registry.
 */
class Registry {

    /**
     * Filter that lists the checks.
     */
    const FILTER = 'wcct_doctor_checks';

    /**
     * Checks Lite registers.
     *
     * @return string[]
     */
    public static function defaults() {
        return array(
            Checks\ConsentOffCheck::class,
        );
    }

    /**
     * Every registered check, keyed by ID; the first one with an ID wins, broken entries are skipped.
     *
     * @return Check[]
     */
    public static function all() {
        /**
         * Filter the Tracking Doctor checks: class names implementing Check (usually extending AbstractCheck), or instances.
         *
         * @since 3.0.0
         *
         * @param array $checks Class names or Check instances.
         */
        $entries = apply_filters( self::FILTER, self::defaults() );
        $checks  = array();

        foreach ( is_array( $entries ) ? $entries : array() as $entry ) {
            $check = self::make( $entry );

            if ( ! $check ) {
                continue;
            }

            try {
                $id = $check->id();
            } catch ( \Throwable $e ) {
                continue;
            }

            if ( is_string( $id ) && '' !== $id && Finding::id( $id ) === $id && ! isset( $checks[ $id ] ) ) {
                $checks[ $id ] = $check;
            }
        }

        return $checks;
    }

    /**
     * The checks that apply to this store, keyed by ID.
     *
     * @return Check[]
     */
    public static function applicable() {
        return array_filter(
            self::all(),
            static function ( Check $check ) {
                try {
                    return (bool) $check->applies();
                } catch ( \Throwable $e ) {
                    return false;
                }
            }
        );
    }

    /**
     * A check from a registry entry, or null.
     *
     * @param mixed $entry Class name or instance.
     *
     * @return Check|null
     */
    private static function make( $entry ) {
        if ( $entry instanceof Check ) {
            return $entry;
        }

        if ( ! is_string( $entry ) || ! class_exists( $entry ) || ! is_subclass_of( $entry, Check::class ) ) {
            return null;
        }

        try {
            $reflection = new \ReflectionClass( $entry );

            return $reflection->isInstantiable() ? $reflection->newInstance() : null;
        } catch ( \Throwable $e ) {
            return null;
        }
    }
}
