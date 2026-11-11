<?php

namespace StorePulse\ConversionTracking\Doctor\Checks;

use StorePulse\ConversionTracking\Doctor\AbstractCheck;
use StorePulse\ConversionTracking\Doctor\Context;
use StorePulse\ConversionTracking\Doctor\Finding;
use StorePulse\ConversionTracking\Doctor\Plugins;

/**
 * A Google Tag Manager plugin is active: we can't see what its container sends, so purchases may count twice.
 */
class TagManagerCheck extends AbstractCheck {

    /**
     * Google's guide to previewing a container, where its purchase tags show.
     */
    const HOW_TO_URL = 'https://support.google.com/tagmanager/answer/6107056';

    /**
     * Check ID.
     *
     * @return string
     */
    public function id() {
        return 'tag-manager';
    }

    /**
     * Working line.
     *
     * @return string
     */
    public function label() {
        return __( 'No Google Tag Manager plugin is active', 'woocommerce-conversion-tracking' );
    }

    /**
     * Tag Manager plugins we know, keyed by slug.
     *
     * @return array[] { name, plugins, constants, classes, active: optional callable returning whether it loads a container }.
     */
    public static function known() {
        $plugins = array(
            'gtm4wp'           => array(
                'name'      => 'GTM4WP',
                'plugins'   => array( 'duracelltomi-google-tag-manager/duracelltomi-google-tag-manager-for-wordpress.php' ),
                'constants' => array( 'GTM4WP_VERSION' ),
                'active'    => array( self::class, 'gtm4wp_container' ),
            ),
            'gtm-ecommerce'    => array(
                'name'    => 'Tag Pilot (GTM for WooCommerce)',
                'plugins' => array( 'gtm-ecommerce-woo/gtm-ecommerce-woo.php', 'gtm-ecommerce-woo-pro/gtm-ecommerce-woo-pro.php' ),
            ),
            'metronet-gtm'     => array(
                'name'    => 'Metronet Tag Manager',
                'plugins' => array( 'metronet-tag-manager/metronet-tag-manager.php' ),
            ),
            'site-kit-gtm'     => array(
                /* translators: %s: plugin name */
                'name'      => sprintf( __( '%s (Tag Manager)', 'woocommerce-conversion-tracking' ), 'Site Kit by Google' ),
                'plugins'   => array( 'google-site-kit/google-site-kit.php' ),
                'constants' => array( 'GOOGLESITEKIT_VERSION' ),
                'active'    => array( self::class, 'site_kit_container' ),
            ),
        );

        /**
         * Filter the Google Tag Manager plugins the Doctor looks for.
         *
         * @since 3.0.0
         *
         * @param array $plugins Keyed by slug: { name, plugins, constants, classes, active }.
         */
        $plugins = apply_filters( 'wcct_doctor_tag_manager_plugins', $plugins );

        return is_array( $plugins ) ? array_filter( $plugins, 'is_array' ) : array();
    }

    /**
     * One "not sure" finding naming the active Tag Manager plugins.
     *
     * @param Context $context Run context.
     *
     * @return Finding[]
     */
    public function run( Context $context ) {
        $active = array();

        foreach ( self::known() as $slug => $plugin ) {
            if ( ! Plugins::is_active( $plugin ) ) {
                continue;
            }

            if ( isset( $plugin['active'] ) && is_callable( $plugin['active'] ) && ! call_user_func( $plugin['active'] ) ) {
                continue;
            }

            $active[ (string) $slug ] = isset( $plugin['name'] ) && is_string( $plugin['name'] ) ? $plugin['name'] : (string) $slug;
        }

        if ( ! $active ) {
            return array();
        }

        ksort( $active );

        return array(
            $this->finding(
                array(
                    'found'      => __( 'A Google Tag Manager plugin is active', 'woocommerce-conversion-tracking' ),
                    'why'        => __( 'If your container also has a purchase tag, Google counts each sale twice.', 'woocommerce-conversion-tracking' ),
                    'tags'       => array_merge( array( __( 'Google', 'woocommerce-conversion-tracking' ), __( 'Can\'t see inside', 'woocommerce-conversion-tracking' ) ), array_values( $active ) ),
                    'confidence' => Finding::UNSURE,
                    'steps'      => array(
                        __( 'Open your container in Tag Manager and preview it.', 'woocommerce-conversion-tracking' ),
                        __( 'Place a test order and look for tags that fire on the purchase.', 'woocommerce-conversion-tracking' ),
                        __( 'If one sends to a platform this plugin also sends to, remove it there or pause that platform here.', 'woocommerce-conversion-tracking' ),
                    ),
                    'action'     => array(
                        'label'    => __( 'How to check', 'woocommerce-conversion-tracking' ),
                        'url'      => self::HOW_TO_URL,
                        'external' => true,
                    ),
                    'evidence'   => array( 'plugins' => array_keys( $active ) ),
                )
            ),
        );
    }

    /**
     * GTM4WP counts only with a container ID; without one it prints nothing.
     *
     * @return bool
     */
    public static function gtm4wp_container() {
        // A wp-config.php constant overrides the saved containers.
        if ( defined( 'GTM4WP_HARDCODED_GTM_ID' ) ) {
            return true;
        }

        // 2.x saves container rows; 1.x only gtm-code, which 2.x reads until the rows are saved.
        $rows = Plugins::option_value( 'gtm4wp-options', array( 'gtm-containers' ) );

        if ( null !== $rows ) {
            foreach ( is_array( $rows ) ? $rows : array() as $row ) {
                if ( is_array( $row ) && isset( $row['id'] ) && is_scalar( $row['id'] ) && '' !== trim( (string) $row['id'] ) ) {
                    return true;
                }
            }

            return false;
        }

        $container = Plugins::option_value( 'gtm4wp-options', array( 'gtm-code' ) );

        return is_scalar( $container ) && '' !== trim( (string) $container );
    }

    /**
     * Site Kit counts only when its Tag Manager module has a container.
     *
     * @return bool
     */
    public static function site_kit_container() {
        $container = Plugins::option_value( 'googlesitekit_tagmanager_settings', array( 'containerID' ) );

        return is_scalar( $container ) && '' !== trim( (string) $container );
    }
}
