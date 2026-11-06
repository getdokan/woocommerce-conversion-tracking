<?php

namespace StorePulse\ConversionTracking\Cache;

use StorePulse\ConversionTracking\Platforms\Pause;

/**
 * Purges the known page caches after a settings save or a pause, so visitors get the new tags at once.
 */
class PageCache {

    /**
     * Fired after the known caches are purged; hook a host or CDN purge here.
     */
    const PURGED_ACTION = 'wcct_purge_page_caches';

    /**
     * Filter for the list of purgers.
     */
    const FILTER = 'wcct_page_cache_purgers';

    /**
     * Purge after every save of the settings and every pause or resume.
     *
     * @return void
     */
    public static function register() {
        add_action( 'wcct_settings_saved', array( self::class, 'purge' ), 10, 0 );
        add_action( Pause::CHANGED_ACTION, array( self::class, 'purge' ), 10, 0 );
    }

    /**
     * The known caches, object cache last: ID => { action } | { function } | { static: [class, method] } | { global: [names], method }, with optional args.
     *
     * @return array
     */
    public static function purgers(): array {
        $blog_id = function_exists( 'is_multisite' ) && is_multisite() ? get_current_blog_id() : 0;

        $purgers = array(
            'litespeed'          => array( 'action' => 'litespeed_purge_all' ),
            'wp_rocket'          => array( 'function' => 'rocket_clean_domain' ),
            'w3_total_cache'     => array( 'function' => 'w3tc_flush_all' ),
            // On a network, only this site's cache.
            'wp_super_cache'     => array(
                'function' => 'wp_cache_clear_cache',
                'args'     => array( $blog_id ),
            ),
            'autoptimize'        => array( 'static' => array( 'autoptimizeCache', 'clearall' ) ),
            'wp_fastest_cache'   => array( 'action' => 'wpfc_clear_all_cache' ),
            'cache_enabler'      => array( 'action' => 'cache_enabler_clear_complete_cache' ),
            'sg_optimizer'       => array( 'function' => 'sg_cachepress_purge_everything' ),
            'hummingbird'        => array( 'action' => 'wphb_clear_page_cache' ),
            'breeze'             => array( 'action' => 'breeze_clear_all_cache' ),
            'nginx_helper'       => array( 'action' => 'rt_nginx_helper_purge_all' ),
            'comet_cache'        => array( 'static' => array( 'comet_cache', 'clear' ) ),
            'swift_performance'  => array( 'static' => array( 'Swift_Performance_Cache', 'clear_all_cache' ) ),
            'wp_engine'          => array( 'static' => array( 'WpeCommon', 'purge_varnish_cache' ) ),
            'kinsta'             => array(
                'global' => array( 'kinsta_cache', 'kinsta_cache_purge' ),
                'method' => 'purge_complete_caches',
            ),
            'pantheon'           => array( 'function' => 'pantheon_wp_clear_edge_all' ),
            // Cloudflare's plugin, also for APO.
            'cloudflare'         => array(
                'global' => array( 'cloudflareHooks' ),
                'method' => 'purgeCacheEverything',
            ),
            'object_cache'       => array( 'function' => 'wp_cache_flush' ),
        );

        // A persistent object cache on a network is shared by every site; the filter can add it back.
        if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
            unset( $purgers['object_cache'] );
        }

        /**
         * Filter the caches purged after a settings save or a pause.
         *
         * @since 3.0.0
         *
         * @param array $purgers ID => { action } | { function } | { static: [class, method] } | { global: [names], method }, with optional args.
         */
        $purgers = apply_filters( self::FILTER, $purgers );

        return is_array( $purgers ) ? $purgers : array();
    }

    /**
     * Purge every cache that is present; a missing or failing one is skipped.
     *
     * @param array|null $purgers Purgers; self::purgers() by default.
     *
     * @return string[] IDs of the caches purged.
     */
    public static function purge( ?array $purgers = null ): array {
        $purged = array();

        foreach ( null === $purgers ? self::purgers() : $purgers as $id => $purger ) {
            $call = is_array( $purger ) ? self::callable_for( $purger ) : null;

            if ( ! $call ) {
                continue;
            }

            try {
                call_user_func_array( $call, isset( $purger['args'] ) && is_array( $purger['args'] ) ? array_values( $purger['args'] ) : array() );
                $purged[] = (string) $id;
            } catch ( \Throwable $e ) {
                // A cache plugin's error must not fail the save.
                continue;
            }
        }

        /**
         * Fires after the known page caches are purged; hook a host or CDN purge here.
         *
         * @since 3.0.0
         *
         * @param string[] $purged IDs of the caches purged.
         */
        do_action( self::PURGED_ACTION, $purged );

        return $purged;
    }

    /**
     * What to call for a purger, or null when its plugin isn't active.
     *
     * @param array $purger Purger.
     *
     * @return callable|null
     */
    private static function callable_for( array $purger ) {
        if ( isset( $purger['action'] ) && is_string( $purger['action'] ) ) {
            $action = $purger['action'];

            return has_action( $action ) ? function ( ...$args ) use ( $action ) {
                do_action( $action, ...$args );
            } : null;
        }

        if ( isset( $purger['function'] ) && is_string( $purger['function'] ) ) {
            return function_exists( $purger['function'] ) ? $purger['function'] : null;
        }

        if ( isset( $purger['static'][0], $purger['static'][1] ) && is_string( $purger['static'][0] ) && is_string( $purger['static'][1] ) ) {
            return class_exists( $purger['static'][0], false ) && method_exists( $purger['static'][0], $purger['static'][1] ) ? $purger['static'] : null;
        }

        if ( isset( $purger['global'], $purger['method'] ) && is_array( $purger['global'] ) && is_string( $purger['method'] ) ) {
            $object = self::global_object( $purger['global'] );

            return is_object( $object ) && method_exists( $object, $purger['method'] ) ? array( $object, $purger['method'] ) : null;
        }

        return null;
    }

    /**
     * A global object, or a property path into one.
     *
     * @param array $path Global name, then property names.
     *
     * @return object|null
     */
    private static function global_object( array $path ) {
        $name = array_shift( $path );

        if ( ! is_string( $name ) || ! isset( $GLOBALS[ $name ] ) || ! is_object( $GLOBALS[ $name ] ) ) {
            return null;
        }

        $object = $GLOBALS[ $name ];

        foreach ( $path as $property ) {
            if ( ! is_string( $property ) || ! isset( $object->$property ) || ! is_object( $object->$property ) ) {
                return null;
            }

            $object = $object->$property;
        }

        return $object;
    }
}
