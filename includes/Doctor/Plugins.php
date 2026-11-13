<?php

namespace StorePulse\ConversionTracking\Doctor;

/**
 * Finds other plugins: by basename in the active plugin lists, else by a constant or class they define.
 */
class Plugins {

    /**
     * Whether a plugin is active.
     *
     * @param array $plugin { plugins: basenames, constants: names, classes: names }.
     *
     * @return bool
     */
    public static function is_active( array $plugin ) {
        $active = self::active_basenames();

        foreach ( self::strings( $plugin, 'plugins' ) as $basename ) {
            if ( in_array( $basename, $active, true ) ) {
                return true;
            }
        }

        foreach ( self::strings( $plugin, 'constants' ) as $constant ) {
            if ( defined( $constant ) ) {
                return true;
            }
        }

        foreach ( self::strings( $plugin, 'classes' ) as $class ) {
            // No autoloading: a class another plugin only ships is not a sign that it runs.
            if ( class_exists( $class, false ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Basenames of the plugins active on this site, network-activated ones included.
     *
     * @return string[]
     */
    public static function active_basenames() {
        $active = get_option( 'active_plugins', array() );
        $active = is_array( $active ) ? array_values( array_filter( $active, 'is_string' ) ) : array();

        if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_site_option' ) ) {
            $network = get_site_option( 'active_sitewide_plugins', array() );
            $active  = array_merge( $active, is_array( $network ) ? array_map( 'strval', array_keys( $network ) ) : array() );
        }

        return $active;
    }

    /**
     * A nested value of an array option, null when missing.
     *
     * @param string   $option Option name.
     * @param string[] $keys   Keys, outermost first.
     *
     * @return mixed
     */
    public static function option_value( $option, array $keys ) {
        $value = get_option( $option, null );

        foreach ( $keys as $key ) {
            if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) {
                return null;
            }

            $value = $value[ $key ];
        }

        return $value;
    }

    /**
     * Strings listed under a key.
     *
     * @param array  $plugin Plugin entry.
     * @param string $key    Key.
     *
     * @return string[]
     */
    private static function strings( array $plugin, $key ) {
        return isset( $plugin[ $key ] ) && is_array( $plugin[ $key ] ) ? array_values( array_filter( $plugin[ $key ], 'is_string' ) ) : array();
    }
}
