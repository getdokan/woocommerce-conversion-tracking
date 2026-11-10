<?php

namespace StorePulse\ConversionTracking\Doctor\Checks;

use StorePulse\ConversionTracking\Doctor\AbstractCheck;
use StorePulse\ConversionTracking\Doctor\Context;
use StorePulse\ConversionTracking\Doctor\Finding;
use StorePulse\ConversionTracking\Doctor\Platforms;
use StorePulse\ConversionTracking\Doctor\Plugins;

/**
 * Another active plugin sends to a platform we send to, so each sale may count twice.
 */
class TrackingPluginsCheck extends AbstractCheck {

    /**
     * Check ID.
     *
     * @return string
     */
    public function id() {
        return 'tracking-plugins';
    }

    /**
     * Working line.
     *
     * @return string
     */
    public function label() {
        return __( 'No other tracking plugin sends to your platforms', 'woocommerce-conversion-tracking' );
    }

    /**
     * Tracking plugins we know, keyed by slug.
     *
     * @return array[] { name, plugins (basenames), constants, classes, platforms (settings platform IDs), sending: callable( $platform ) returning true, false or null (can't tell), replaces: slugs this one stands in for when both are found }.
     */
    public static function known() {
        $plugins = array(
            'facebook-for-woocommerce'         => array(
                /* translators: 1: plugin name, 2: its former name */
                'name'      => sprintf( __( '%1$s (formerly %2$s)', 'woocommerce-conversion-tracking' ), 'Meta for WooCommerce', 'Facebook for WooCommerce' ),
                'plugins'   => array( 'facebook-for-woocommerce/facebook-for-woocommerce.php' ),
                'classes'   => array( 'WC_Facebookcommerce' ),
                'platforms' => array( 'facebook' ),
                'sending'   => array( self::class, 'meta_for_woocommerce_sends' ),
            ),
            'official-facebook-pixel'          => array(
                'name'      => 'Meta Pixel for WordPress',
                'plugins'   => array( 'official-facebook-pixel/facebook-for-wordpress.php' ),
                'classes'   => array( 'FacebookPixelPlugin\FacebookForWordpress' ),
                'platforms' => array( 'facebook' ),
            ),
            // PixelYourSite Pro is told apart by its basename only: whether the free plugin also defines PYS_VERSION is unverified.
            'pixelyoursite'                    => array(
                'name'      => 'PixelYourSite',
                'plugins'   => array( 'pixelyoursite/pixelyoursite.php' ),
                'constants' => array( 'PYS_FREE_VERSION' ),
                'platforms' => array( 'facebook', 'google' ),
            ),
            'pixelyoursite-pro'                => array(
                'name'      => 'PixelYourSite Pro',
                'plugins'   => array( 'pixelyoursite-pro/pixelyoursite-pro.php' ),
                'platforms' => array( 'facebook', 'google', 'bing_ads' ),
                'replaces'  => array( 'pixelyoursite' ),
            ),
            'pixel-manager'                    => array(
                'name'      => 'Pixel Manager for WooCommerce',
                'plugins'   => array( 'woocommerce-google-adwords-conversion-tracking-tag/wgact.php', 'woocommerce-pixel-manager-pro/wgact.php' ),
                'constants' => array( 'PMW_CURRENT_VERSION' ),
                'platforms' => array( 'facebook', 'google', 'twitter', 'bing_ads' ),
                'sending'   => array( self::class, 'pixel_manager_sends' ),
            ),
            'google-site-kit'                  => array(
                'name'      => 'Site Kit by Google',
                'plugins'   => array( 'google-site-kit/google-site-kit.php' ),
                'constants' => array( 'GOOGLESITEKIT_VERSION' ),
                'platforms' => array( 'google' ),
                'sending'   => array( self::class, 'site_kit_sends' ),
            ),
            'google-listings-and-ads'          => array(
                'name'      => 'Google for WooCommerce',
                'plugins'   => array( 'google-listings-and-ads/google-listings-and-ads.php' ),
                'constants' => array( 'WC_GLA_VERSION' ),
                'platforms' => array( 'google' ),
                'sending'   => array( self::class, 'google_for_woocommerce_sends' ),
            ),
            'woocommerce-google-analytics'     => array(
                'name'      => 'Google Analytics for WooCommerce',
                'plugins'   => array( 'woocommerce-google-analytics-integration/woocommerce-google-analytics-integration.php' ),
                'classes'   => array( 'WC_Google_Analytics_Integration' ),
                'platforms' => array( 'google' ),
                'sending'   => array( self::class, 'ga_for_woocommerce_sends' ),
            ),
            'woocommerce-google-analytics-pro' => array(
                'name'      => 'Google Analytics Pro',
                'plugins'   => array( 'woocommerce-google-analytics-pro/woocommerce-google-analytics-pro.php' ),
                'platforms' => array( 'google' ),
            ),
            'monsterinsights'                  => array(
                'name'      => 'MonsterInsights',
                'plugins'   => array( 'google-analytics-for-wordpress/googleanalytics.php', 'google-analytics-premium/googleanalytics-premium.php' ),
                'classes'   => array( 'MonsterInsights_Lite', 'MonsterInsights' ),
                'platforms' => array( 'google' ),
            ),
            'exactmetrics'                     => array(
                'name'      => 'ExactMetrics',
                'plugins'   => array( 'google-analytics-dashboard-for-wp/gadwp.php', 'exactmetrics-premium/exactmetrics-premium.php' ),
                'classes'   => array( 'ExactMetrics_Lite', 'ExactMetrics' ),
                'platforms' => array( 'google' ),
            ),
            'conversios'                       => array(
                'name'      => 'Conversios',
                'plugins'   => array( 'enhanced-e-commerce-for-woocommerce-store/enhanced-ecommerce-google-analytics.php' ),
                'platforms' => array( 'google', 'facebook' ),
            ),
        );

        /**
         * Filter the tracking plugins the Doctor looks for.
         *
         * @since 3.0.0
         *
         * @param array $plugins Keyed by slug: { name, plugins, constants, classes, platforms, sending, replaces }.
         */
        $plugins = apply_filters( 'wcct_doctor_tracking_plugins', $plugins );

        return is_array( $plugins ) ? array_filter( $plugins, 'is_array' ) : array();
    }

    /**
     * A finding per active plugin and platform we also send to.
     *
     * @param Context $context Run context.
     *
     * @return Finding[]
     */
    public function run( Context $context ) {
        return $this->findings( self::known(), Platforms::from_context( $context ) );
    }

    /**
     * Findings for some plugins and platform states.
     *
     * @param array $plugins Known plugins.
     * @param array $states  Platform states.
     *
     * @return Finding[]
     */
    public function findings( array $plugins, array $states ) {
        $findings = array();
        $active   = array_filter( $plugins, array( Plugins::class, 'is_active' ) );
        $replaced = array();

        foreach ( $active as $plugin ) {
            $replaced = array_merge( $replaced, isset( $plugin['replaces'] ) && is_array( $plugin['replaces'] ) ? $plugin['replaces'] : array() );
        }

        foreach ( $active as $slug => $plugin ) {
            if ( in_array( $slug, $replaced, true ) ) {
                continue;
            }

            $name = isset( $plugin['name'] ) && is_string( $plugin['name'] ) ? $plugin['name'] : (string) $slug;

            foreach ( isset( $plugin['platforms'] ) && is_array( $plugin['platforms'] ) ? $plugin['platforms'] : array() as $platform ) {
                if ( ! is_string( $platform ) || empty( $states[ $platform ]['sends'] ) ) {
                    continue;
                }

                $sending = self::sending( $plugin, $platform );

                if ( false === $sending ) {
                    continue;
                }

                $findings[] = $this->overlap( (string) $slug, $name, $states[ $platform ], true === $sending );
            }
        }

        return $findings;
    }

    /**
     * Whether a plugin sends to a platform: true, false, or null when we can't read it.
     *
     * @param array  $plugin   Plugin entry.
     * @param string $platform Settings platform ID.
     *
     * @return bool|null
     */
    private static function sending( array $plugin, $platform ) {
        if ( ! isset( $plugin['sending'] ) || ! is_callable( $plugin['sending'] ) ) {
            return null;
        }

        try {
            $sending = call_user_func( $plugin['sending'], $platform );
        } catch ( \Throwable $e ) {
            return null;
        }

        return is_bool( $sending ) ? $sending : null;
    }

    /**
     * The finding for one plugin and platform.
     *
     * @param string $slug  Plugin slug.
     * @param string $name  Plugin name.
     * @param array  $state Platform state.
     * @param bool   $sure  Whether we read that it sends there.
     *
     * @return Finding
     */
    private function overlap( $slug, $name, array $state, $sure ) {
        $platform = $state['label'];

        return $this->finding(
            array(
                'key'        => $slug . ':' . $state['id'],
                'found'      => $sure
                    /* translators: 1: plugin name, 2: platform name, for example Meta */
                    ? sprintf( __( '%1$s also sends to %2$s', 'woocommerce-conversion-tracking' ), $name, $platform )
                    /* translators: 1: plugin name, 2: platform name, for example Meta */
                    : sprintf( __( '%1$s is also active and may send to %2$s', 'woocommerce-conversion-tracking' ), $name, $platform ),
                /* translators: %s: platform name, for example Meta */
                'why'        => sprintf( __( 'If both plugins send purchases to %s, each sale is counted twice and your ad results double.', 'woocommerce-conversion-tracking' ), $platform ),
                'label'      => __( 'Duplicate', 'woocommerce-conversion-tracking' ),
                'tags'       => array( $platform, $sure ? __( 'Double counting', 'woocommerce-conversion-tracking' ) : __( 'Not sure it sends purchases', 'woocommerce-conversion-tracking' ) ),
                'confidence' => $sure ? Finding::SURE : Finding::UNSURE,
                'steps'      => array(
                    /* translators: %s: platform name */
                    sprintf( __( 'Pick one plugin to send to %s.', 'woocommerce-conversion-tracking' ), $platform ),
                    /* translators: 1: platform name, 2: plugin name */
                    sprintf( __( 'To keep this one, turn off %1$s tracking in %2$s, or deactivate it.', 'woocommerce-conversion-tracking' ), $platform, $name ),
                    /* translators: 1: plugin name, 2: platform name */
                    sprintf( __( 'To keep %1$s, pause %2$s in Platforms.', 'woocommerce-conversion-tracking' ), $name, $platform ),
                    __( 'Then check again.', 'woocommerce-conversion-tracking' ),
                ),
                'action'     => array(
                    'type'  => Finding::ACTION_RECHECK,
                    'label' => __( 'Check again', 'woocommerce-conversion-tracking' ),
                ),
                'evidence'   => array(
                    'plugin'   => $slug,
                    'platform' => $state['id'],
                    'sure'     => $sure,
                ),
            )
        );
    }

    /**
     * Meta for WooCommerce: its pixel is on with a Pixel ID unless its own filter turns it off (spike 3).
     *
     * @param string $platform Settings platform ID.
     *
     * @return bool|null
     */
    public static function meta_for_woocommerce_sends( $platform ) {
        if ( 'facebook' !== $platform ) {
            return null;
        }

        $pixel = get_option( 'wc_facebook_pixel_id', '' );

        if ( ! is_scalar( $pixel ) || '' === trim( (string) $pixel ) ) {
            return false;
        }

        // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Meta for WooCommerce's own filter.
        return (bool) apply_filters( 'facebook_for_woocommerce_integration_pixel_enabled', true );
    }

    /**
     * Pixel Manager for WooCommerce: an ID in its settings per platform.
     *
     * @param string $platform Settings platform ID.
     *
     * @return bool|null
     */
    public static function pixel_manager_sends( $platform ) {
        $paths = array(
            'facebook' => array( array( 'facebook', 'pixel_id' ) ),
            'google'   => array( array( 'google', 'ads', 'conversion_id' ), array( 'google', 'analytics', 'ga4', 'measurement_id' ) ),
            'twitter'  => array( array( 'twitter', 'pixel_id' ) ),
            'bing_ads' => array( array( 'bing', 'uet_tag_id' ) ),
        );

        if ( ! isset( $paths[ $platform ] ) || ! is_array( get_option( 'wgact_plugin_options', null ) ) ) {
            return null;
        }

        $read = false;

        foreach ( $paths[ $platform ] as $path ) {
            $value = Plugins::option_value( 'wgact_plugin_options', $path );

            if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
                return true;
            }

            $read = $read || null !== $value;
        }

        // Read and empty: it doesn't send there. Not found: its settings changed shape, so we can't tell.
        return $read ? false : null;
    }

    /**
     * Google for WooCommerce: it sends purchases to Google Ads once it has an Ads conversion action.
     *
     * @param string $platform Settings platform ID.
     *
     * @return bool|null
     */
    public static function google_for_woocommerce_sends( $platform ) {
        if ( 'google' !== $platform ) {
            return null;
        }

        return (bool) get_option( 'gla_ads_conversion_action', null );
    }

    /**
     * Google Analytics for WooCommerce: sends when it has a GA4 ID and its purchase tracking is on.
     *
     * @param string $platform Settings platform ID.
     *
     * @return bool|null
     */
    public static function ga_for_woocommerce_sends( $platform ) {
        if ( 'google' !== $platform ) {
            return null;
        }

        $id = Plugins::option_value( 'woocommerce_google_analytics_settings', array( 'ga_id' ) );

        if ( 'no' === Plugins::option_value( 'woocommerce_google_analytics_settings', array( 'ga_ecommerce_tracking_enabled' ) ) ) {
            return false;
        }

        return is_scalar( $id ) && '' !== trim( (string) $id );
    }

    /**
     * Site Kit: no Google tag without its Analytics or Ads module; with one, we can't tell whether it sends purchases.
     *
     * @param string $platform Settings platform ID.
     *
     * @return bool|null
     */
    public static function site_kit_sends( $platform ) {
        if ( 'google' !== $platform ) {
            return null;
        }

        $modules = get_option( 'googlesitekit_active_modules', array() );

        return is_array( $modules ) && array_intersect( array( 'analytics-4', 'ads' ), $modules ) ? null : false;
    }
}
