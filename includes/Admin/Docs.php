<?php

namespace StorePulse\ConversionTracking\Admin;

// don't call the file directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Docs links for the admin Help menu and the docs tiles
 *
 * @since PLUGIN_SINCE
 */
class Docs {

    /**
     * Docs home
     *
     * @var string
     */
    const BASE_URL = 'https://wedevs.com/docs/woocommerce-conversion-tracking/';

    /**
     * Tile id => docs path under BASE_URL (2.x docs until the 3.0 docs exist), logo in assets/images/platforms/, Pro-only flag
     *
     * @var array
     */
    const TILES = array(
        'meta'      => array(
            'path' => 'facebook/',
            'icon' => 'meta.svg',
            'pro'  => false,
        ),
        'google'    => array(
            'path' => 'google-adwords/',
            'icon' => 'google-analytics.svg',
            'pro'  => false,
        ),
        'x'         => array(
            'path' => 'twitter/',
            'icon' => 'x.svg',
            'pro'  => false,
        ),
        'microsoft' => array(
            'path' => 'bing-ads/',
            'icon' => 'microsoft.svg',
            'pro'  => true,
        ),
        'custom'    => array(
            'path' => 'custom-channel-integration/custom/',
            'icon' => 'custom.svg',
            'pro'  => false,
        ),
        'catalog'   => array(
            'path' => 'facebook/facebook-product-catalog/',
            'icon' => 'meta.svg',
            'pro'  => true,
        ),
    );

    /**
     * Docs home URL for the Help menu
     *
     * @return string
     */
    public static function home_url() {
        return self::with_utm( self::BASE_URL, 'docs' );
    }

    /**
     * Docs tiles, one per platform plus the catalog
     *
     * @return array[] Each with id, title, url, icon and pro.
     */
    public static function tiles() {
        $titles = array(
            'meta'      => __( 'Meta', 'woocommerce-conversion-tracking' ),
            'google'    => __( 'Google Analytics and Ads', 'woocommerce-conversion-tracking' ),
            'x'         => __( 'X (formerly Twitter)', 'woocommerce-conversion-tracking' ),
            'microsoft' => __( 'Microsoft Advertising', 'woocommerce-conversion-tracking' ),
            'custom'    => __( 'Custom script', 'woocommerce-conversion-tracking' ),
            'catalog'   => __( 'Meta catalog feed', 'woocommerce-conversion-tracking' ),
        );

        $tiles = array();

        foreach ( self::TILES as $id => $tile ) {
            $tiles[] = array(
                'id'    => $id,
                'title' => $titles[ $id ],
                'url'   => self::with_utm( self::BASE_URL . $tile['path'], $id ),
                'icon'  => WCCT_ASSETS . '/images/platforms/' . $tile['icon'],
                'pro'   => $tile['pro'],
            );
        }

        return $tiles;
    }

    /**
     * Add the wp-admin docs campaign parameters to a docs URL
     *
     * @param string $url     Docs URL.
     * @param string $content Value for utm_content.
     *
     * @return string
     */
    private static function with_utm( $url, $content ) {
        return add_query_arg(
            array(
                'utm_source'   => 'wp-admin',
                'utm_medium'   => 'docs-link',
                'utm_campaign' => 'wcct_docs',
                'utm_content'  => $content,
            ),
            $url
        );
    }
}
