<?php

require_once dirname( __DIR__ ) . '/Fakes/cache-plugins.php';

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Cache\PageCache;

/**
 * Tests for the page cache purge run after every settings save and pause
 */
class PageCacheTest extends TestCase {

    /**
     * Actions the cache plugins listen on, as they register them
     */
    const ACTIONS = array(
        'litespeed_purge_all',
        'wpfc_clear_all_cache',
        'cache_enabler_clear_complete_cache',
        'wphb_clear_page_cache',
        'breeze_clear_all_cache',
        'rt_nginx_helper_purge_all',
    );

    protected function setUp(): void {
        $GLOBALS['wcct_test_purges']  = array();
        $GLOBALS['wcct_test_actions'] = array();
        $GLOBALS['wcct_test_hooks']   = array();
    }

    protected function tearDown(): void {
        unset( $GLOBALS['wcct_test_purges'], $GLOBALS['wcct_test_actions'], $GLOBALS['wcct_test_hooks'], $GLOBALS['wcct_test_filters'], $GLOBALS['kinsta_cache'], $GLOBALS['cloudflareHooks'], $GLOBALS['wcct_test_multisite'], $GLOBALS['wcct_test_ext_object_cache'] );
    }

    /**
     * Hook a stand-in listener on each cache plugin action
     *
     * @return void
     */
    private function hook_cache_actions() {
        foreach ( self::ACTIONS as $action ) {
            $GLOBALS['wcct_test_hooks'][ $action ] = array(
                function () use ( $action ) {
                    $GLOBALS['wcct_test_purges'][] = array( $action, func_get_args() );
                },
            );
        }
    }

    public function test_every_known_cache_is_purged_when_its_plugin_is_active() {
        $this->hook_cache_actions();

        $GLOBALS['kinsta_cache']                     = new stdClass();
        $GLOBALS['kinsta_cache']->kinsta_cache_purge = new WCCT_Test_Fake_Kinsta_Purge();
        $GLOBALS['cloudflareHooks']                  = new WCCT_Test_Fake_Cloudflare_Hooks();

        $purged = PageCache::purge();

        $this->assertSame(
            array( 'litespeed', 'wp_rocket', 'w3_total_cache', 'wp_super_cache', 'autoptimize', 'wp_fastest_cache', 'cache_enabler', 'sg_optimizer', 'hummingbird', 'breeze', 'nginx_helper', 'comet_cache', 'swift_performance', 'wp_engine', 'kinsta', 'pantheon', 'cloudflare', 'object_cache' ),
            $purged
        );
        $this->assertSame(
            array(
                array( 'litespeed_purge_all', array() ),
                array( 'rocket_clean_domain', array() ),
                array( 'w3tc_flush_all', array() ),
                array( 'wp_cache_clear_cache', array( 0 ) ),
                array( 'autoptimizeCache::clearall', array() ),
                array( 'wpfc_clear_all_cache', array() ),
                array( 'cache_enabler_clear_complete_cache', array() ),
                array( 'sg_cachepress_purge_everything', array() ),
                array( 'wphb_clear_page_cache', array() ),
                array( 'breeze_clear_all_cache', array() ),
                array( 'rt_nginx_helper_purge_all', array() ),
                array( 'comet_cache::clear', array() ),
                array( 'Swift_Performance_Cache::clear_all_cache', array() ),
                array( 'WpeCommon::purge_varnish_cache', array() ),
                array( 'kinsta_cache->kinsta_cache_purge->purge_complete_caches', array() ),
                array( 'pantheon_wp_clear_edge_all', array() ),
                array( 'cloudflareHooks->purgeCacheEverything', array() ),
                array( 'wp_cache_flush', array() ),
            ),
            $GLOBALS['wcct_test_purges']
        );
    }

    public function test_our_action_fires_once_after_the_purge_with_what_was_purged() {
        $purged = PageCache::purge();

        $this->assertSame( array( array( 'wcct_purge_page_caches', array( $purged ) ) ), array_values( array_filter( $GLOBALS['wcct_test_actions'], function ( $call ) {
            return 'wcct_purge_page_caches' === $call[0];
        } ) ) );
        $this->assertSame( 'wcct_purge_page_caches', end( $GLOBALS['wcct_test_actions'] )[0] );
    }

    public function test_an_action_nobody_listens_on_is_not_fired() {
        PageCache::purge( array( 'litespeed' => array( 'action' => 'litespeed_purge_all' ) ) );

        $this->assertSame( array( array( 'wcct_purge_page_caches', array( array() ) ) ), $GLOBALS['wcct_test_actions'] );
    }

    public function test_missing_plugins_are_skipped_without_an_error() {
        $purged = PageCache::purge(
            array(
                'function' => array( 'function' => 'wcct_test_no_such_cache_function' ),
                'static'   => array( 'static' => array( 'WCCT_Test_No_Such_Cache', 'clear' ) ),
                'method'   => array( 'static' => array( 'WpeCommon', 'no_such_method' ) ),
                'global'   => array( 'global' => array( 'wcct_test_no_such_global' ), 'method' => 'purge' ),
                'path'     => array( 'global' => array( 'kinsta_cache', 'kinsta_cache_purge' ), 'method' => 'purge_complete_caches' ),
                'broken'   => 'not a purger',
                'empty'    => array(),
            )
        );

        $this->assertSame( array(), $purged );
        $this->assertSame( array(), $GLOBALS['wcct_test_purges'] );
    }

    public function test_a_failing_cache_does_not_stop_the_others() {
        $GLOBALS['wcct_test_hooks']['wcct_test_failing_cache'] = array(
            function () {
                throw new RuntimeException( 'Cache folder is not writable' );
            },
        );

        $purged = PageCache::purge(
            array(
                'failing'      => array( 'action' => 'wcct_test_failing_cache' ),
                'wp_rocket'    => array( 'function' => 'rocket_clean_domain' ),
                'object_cache' => array( 'function' => 'wp_cache_flush' ),
            )
        );

        $this->assertSame( array( 'wp_rocket', 'object_cache' ), $purged );
    }

    public function test_purgers_can_be_added_and_removed_with_the_filter() {
        $GLOBALS['wcct_test_filters']['wcct_page_cache_purgers'] = function ( $purgers ) {
            unset( $purgers['object_cache'] );
            $purgers['host'] = array( 'function' => 'pantheon_wp_clear_edge_all', 'args' => array( 'all' ) );

            return $purgers;
        };

        $purged = PageCache::purge();

        $this->assertNotContains( 'object_cache', $purged );
        $this->assertSame( 'host', end( $purged ) );
        $this->assertSame( array( 'pantheon_wp_clear_edge_all', array( 'all' ) ), end( $GLOBALS['wcct_test_purges'] ) );
    }

    public function test_a_network_with_a_persistent_object_cache_keeps_it() {
        $GLOBALS['wcct_test_multisite']         = true;
        $GLOBALS['wcct_test_ext_object_cache'] = true;

        $purgers = PageCache::purgers();

        $this->assertArrayNotHasKey( 'object_cache', $purgers );
        $this->assertSame( array( 3 ), $purgers['wp_super_cache']['args'] );
        $this->assertNotContains( array( 'wp_cache_flush', array() ), ( function () {
            PageCache::purge();

            return $GLOBALS['wcct_test_purges'];
        } )() );

        // A single site with one, or a network without one, is flushed.
        $GLOBALS['wcct_test_multisite'] = false;
        $this->assertArrayHasKey( 'object_cache', PageCache::purgers() );

        $GLOBALS['wcct_test_multisite']         = true;
        $GLOBALS['wcct_test_ext_object_cache'] = false;
        $this->assertArrayHasKey( 'object_cache', PageCache::purgers() );
    }

    public function test_the_filter_can_flush_a_network_object_cache_anyway() {
        $GLOBALS['wcct_test_multisite']                          = true;
        $GLOBALS['wcct_test_ext_object_cache']                  = true;
        $GLOBALS['wcct_test_filters']['wcct_page_cache_purgers'] = function ( $purgers ) {
            return $purgers + array( 'object_cache' => array( 'function' => 'wp_cache_flush' ) );
        };

        $this->assertContains( 'object_cache', PageCache::purge() );
    }

    public function test_a_filter_returning_garbage_purges_nothing() {
        $GLOBALS['wcct_test_filters']['wcct_page_cache_purgers'] = function () {
            return 'nothing';
        };

        $this->assertSame( array(), PageCache::purge() );
    }

    public function test_a_hold_turns_purges_into_one_at_release() {
        PageCache::hold();
        PageCache::hold();
        PageCache::purge();
        PageCache::purge();
        PageCache::release();

        $this->assertSame( array(), $GLOBALS['wcct_test_actions'], 'still held by the outer hold' );

        PageCache::release();
        $this->assertCount( 1, $GLOBALS['wcct_test_actions'] );

        // Nothing asked for: no purge at release.
        PageCache::hold();
        PageCache::release();
        $this->assertCount( 1, $GLOBALS['wcct_test_actions'] );
    }
}
