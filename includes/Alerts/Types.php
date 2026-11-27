<?php

namespace StorePulse\ConversionTracking\Alerts;

/**
 * The alert types registry (filter wcct_alert_types); Settings > Alerts shows a switch per type.
 */
class Types {

    /**
     * A purchase a platform didn't confirm in the last 24 hours.
     */
    const NOT_CONFIRMED = 'not_confirmed';

    /**
     * Filter that registers alert types.
     */
    const FILTER = 'wcct_alert_types';

    /**
     * Registered types: key => { key, label, description }, in registration order.
     *
     * @return array[]
     */
    public static function all() {
        $defaults = self::built_in();

        /**
         * Register alert types; each gets an on/off switch under Settings > Alerts.
         *
         * @since 3.0.0
         *
         * @param array $types Key => { label, description }; a list of { key, label, description } works too.
         */
        $types = apply_filters( self::FILTER, $defaults );

        return self::normalise( is_array( $types ) ? $types : $defaults );
    }

    /**
     * The types 3.0 ships; a filter can relabel them but never drop them.
     *
     * @return array[]
     */
    public static function built_in() {
        return array(
            self::NOT_CONFIRMED => array(
                'label'       => __( 'Not confirmed purchases, on the dashboard', 'woocommerce-conversion-tracking' ),
                'description' => __( "A badge and a card when we couldn't confirm a paid order was sent to a connected platform within 10 minutes.", 'woocommerce-conversion-tracking' ),
            ),
        );
    }

    /**
     * Whether a type is registered.
     *
     * @param string $key Type key.
     *
     * @return bool
     */
    public static function exists( $key ) {
        return isset( self::all()[ (string) $key ] );
    }

    /**
     * Keep well-formed types (a key of a-z, 0-9 and _, and a label), with the built-in ones always there.
     *
     * @param array $types Raw types.
     *
     * @return array[]
     */
    public static function normalise( array $types ) {
        $clean = array();

        foreach ( $types as $index => $type ) {
            if ( ! is_array( $type ) ) {
                continue;
            }

            $key   = isset( $type['key'] ) ? $type['key'] : $index;
            $key   = is_string( $key ) ? strtolower( preg_replace( '/[^A-Za-z0-9_]/', '', $key ) ) : '';
            $label = isset( $type['label'] ) && is_string( $type['label'] ) ? trim( $type['label'] ) : '';

            // The Doctor's switch has its own key.
            if ( '' === $key || '' === $label || isset( $clean[ $key ] ) || Alerts::DOCTOR === $key ) {
                continue;
            }

            $clean[ $key ] = array(
                'key'         => $key,
                'label'       => $label,
                'description' => isset( $type['description'] ) && is_string( $type['description'] ) ? trim( $type['description'] ) : '',
            );
        }

        // The built-in types stay first, even when a filter dropped or broke them.
        $built_in = array();

        foreach ( self::built_in() as $key => $type ) {
            $built_in[ $key ] = isset( $clean[ $key ] ) ? $clean[ $key ] : array_merge( array( 'key' => $key ), $type );
        }

        return array_merge( $built_in, $clean );
    }
}
