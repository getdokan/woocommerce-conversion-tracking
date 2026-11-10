<?php

namespace StorePulse\ConversionTracking\Platforms;

/**
 * Cleans and filters the platform list built from wcct_platforms.
 */
class Registry {

    /**
     * Platforms keyed by integration ID, a later duplicate replacing the earlier in place; anything not extending WCCT_Integration is dropped
     *
     * @param mixed $platforms Raw list.
     *
     * @return \WCCT_Integration[]
     */
    public static function normalise( $platforms ) {
        $clean = array();

        foreach ( is_array( $platforms ) ? $platforms : array() as $platform ) {
            if ( ! $platform instanceof \WCCT_Integration ) {
                continue;
            }

            $id = $platform->get_id();

            if ( is_string( $id ) && '' !== $id ) {
                $clean[ $id ] = $platform;
            }
        }

        return $clean;
    }

    /**
     * Platforms that are enabled and not paused.
     *
     * @param \WCCT_Integration[] $platforms Platforms.
     *
     * @return \WCCT_Integration[]
     */
    public static function active( array $platforms ) {
        return array_filter(
            $platforms,
            function ( $platform ) {
                return $platform->is_active();
            }
        );
    }

    /**
     * Platforms that send events from the browser.
     *
     * @param \WCCT_Integration[] $platforms Platforms.
     *
     * @return \WCCT_Integration[]
     */
    public static function browser( array $platforms ) {
        return array_filter(
            $platforms,
            function ( $platform ) {
                return in_array( 'browser', (array) $platform->get_transports(), true );
            }
        );
    }

    /**
     * Platforms that track the current visitor: those that skip the visitor's role are left out.
     *
     * @param \WCCT_Integration[] $platforms Platforms.
     *
     * @return \WCCT_Integration[]
     */
    public static function for_visitor( array $platforms ) {
        return array_filter(
            $platforms,
            function ( $platform ) {
                return ! $platform->skips_current_user();
            }
        );
    }
}
