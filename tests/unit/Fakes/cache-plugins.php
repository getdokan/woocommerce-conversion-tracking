<?php
/**
 * Stand-ins for the public purge APIs of cache plugins; each logs its call to $GLOBALS['wcct_test_purges']. Loaded only by PageCacheTest.
 */

// phpcs:disable

function rocket_clean_domain( ...$args ) {
    $GLOBALS['wcct_test_purges'][] = array( 'rocket_clean_domain', $args );
}

function w3tc_flush_all( ...$args ) {
    $GLOBALS['wcct_test_purges'][] = array( 'w3tc_flush_all', $args );
}

function wp_cache_clear_cache( ...$args ) {
    $GLOBALS['wcct_test_purges'][] = array( 'wp_cache_clear_cache', $args );
}

function sg_cachepress_purge_everything( ...$args ) {
    $GLOBALS['wcct_test_purges'][] = array( 'sg_cachepress_purge_everything', $args );
}

function pantheon_wp_clear_edge_all( ...$args ) {
    $GLOBALS['wcct_test_purges'][] = array( 'pantheon_wp_clear_edge_all', $args );
}

function wp_cache_flush( ...$args ) {
    $GLOBALS['wcct_test_purges'][] = array( 'wp_cache_flush', $args );
}

function is_multisite() {
    return ! empty( $GLOBALS['wcct_test_multisite'] );
}

function get_current_blog_id() {
    return 3;
}

function wp_using_ext_object_cache() {
    return ! empty( $GLOBALS['wcct_test_ext_object_cache'] );
}

class autoptimizeCache {
    public static function clearall() {
        $GLOBALS['wcct_test_purges'][] = array( 'autoptimizeCache::clearall', array() );
    }
}

class comet_cache {
    public static function clear() {
        $GLOBALS['wcct_test_purges'][] = array( 'comet_cache::clear', array() );
    }
}

class Swift_Performance_Cache {
    public static function clear_all_cache() {
        $GLOBALS['wcct_test_purges'][] = array( 'Swift_Performance_Cache::clear_all_cache', array() );
    }
}

class WpeCommon {
    public static function purge_varnish_cache() {
        $GLOBALS['wcct_test_purges'][] = array( 'WpeCommon::purge_varnish_cache', array() );
    }
}

class WCCT_Test_Fake_Kinsta_Purge {
    public function purge_complete_caches() {
        $GLOBALS['wcct_test_purges'][] = array( 'kinsta_cache->kinsta_cache_purge->purge_complete_caches', array() );
    }
}

class WCCT_Test_Fake_Cloudflare_Hooks {
    public function purgeCacheEverything() {
        $GLOBALS['wcct_test_purges'][] = array( 'cloudflareHooks->purgeCacheEverything', array() );
    }
}
