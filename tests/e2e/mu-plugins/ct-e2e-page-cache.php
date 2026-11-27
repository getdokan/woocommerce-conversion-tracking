<?php
/**
 * Plugin Name: CT e2e page cache
 * Description: Test site only. A worst-case full-page cache: with the ct_e2e_page_cache cookie, logged-out GET pages are cached per URL and served to every browser, whatever its cookies.
 */

// phpcs:disable

add_action(
    'init',
    static function () {
        if ( empty( $_COOKIE['ct_e2e_page_cache'] ) || 'GET' !== $_SERVER['REQUEST_METHOD'] || is_admin() || wp_doing_ajax() || is_user_logged_in() ) {
            return;
        }

        $uri = (string) $_SERVER['REQUEST_URI'];

        if ( isset( $_GET['ct_e2e_page_cache_flush'] ) ) {
            delete_option( 'ct_e2e_page_cache' );
            wp_die( 'Page cache flushed', '', array( 'response' => 200 ) );
        }

        // Like real caches: no REST, no WooCommerce AJAX, no add to cart links.
        if ( false !== strpos( $uri, '/wp-json/' ) || isset( $_GET['wc-ajax'] ) || isset( $_GET['add-to-cart'] ) || isset( $_GET['rest_route'] ) ) {
            return;
        }

        $cache = get_option( 'ct_e2e_page_cache', array() );

        if ( isset( $cache[ $uri ] ) ) {
            header( 'Content-Type: text/html; charset=UTF-8' );
            header( 'X-CT-Cache: HIT' );
            echo $cache[ $uri ];
            exit;
        }

        header( 'X-CT-Cache: MISS' );
        ob_start(
            static function ( $html ) use ( $uri ) {
                if ( 200 === http_response_code() && false !== stripos( $html, '</html>' ) ) {
                    $cache          = get_option( 'ct_e2e_page_cache', array() );
                    $cache[ $uri ] = $html;
                    update_option( 'ct_e2e_page_cache', $cache, false );
                }

                return $html;
            }
        );
    },
    -1000
);

// Purged the way LiteSpeed Cache listens for it, so a settings save or a pause empties this cache (#103).
add_action(
    'litespeed_purge_all',
    static function () {
        delete_option( 'ct_e2e_page_cache' );
    }
);
