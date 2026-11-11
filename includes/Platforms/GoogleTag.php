<?php

namespace StorePulse\ConversionTracking\Platforms;

use StorePulse\ConversionTracking\Privacy\OrderUrl;
use StorePulse\ConversionTracking\Settings\Google;
use StorePulse\ConversionTracking\Settings\Store;

/**
 * The one Google tag shared by the ga4 and adwords integrations: their settings from the google platform and one gtag.js head block.
 */
class GoogleTag {

    /**
     * Fired once the shared head block is printed, with its config IDs.
     */
    const PRINTED_ACTION = 'wcct_google_tag_printed';

    /**
     * The google platform's values for this request, null until read.
     *
     * @var array|null
     */
    private static $platform = null;

    /**
     * Integration settings read from $platform, by integration ID.
     *
     * @var array
     */
    private static $settings = array();

    /**
     * The google platform's values: wcct_settings_v3, or the 2.x settings read as 3.0 until the migration has written them.
     *
     * @return array
     */
    public static function platform(): array {
        if ( null === self::$platform ) {
            $doc = Store::load();

            // Once the 3.0 settings exist, with the schema defaults as the settings page shows them (a save keeps only what changed); before, the 2.x view.
            self::$platform = PlatformSettings::values( 'google' ) ?? ( isset( $doc['platforms']['google'] ) && is_array( $doc['platforms']['google'] ) ? $doc['platforms']['google'] : array() );

            // A save later in the same request is read again.
            if ( function_exists( 'add_action' ) ) {
                foreach ( array( Store::OPTION, Store::V1_OPTION, Pause::OPTION ) as $option ) {
                    add_action( 'add_option_' . $option, array( self::class, 'reset' ) );
                    add_action( 'update_option_' . $option, array( self::class, 'reset' ) );
                    add_action( 'delete_option_' . $option, array( self::class, 'reset' ) );
                }
            }
        }

        return self::$platform;
    }

    /**
     * Forget the values read in this request.
     *
     * @return void
     */
    public static function reset() {
        self::$platform = null;
        self::$settings = array();
    }

    /**
     * Settings of the ga4 or adwords integration (see Google::integrations()), switched off while it is paused (see Pause).
     *
     * @param string     $integration ga4 or adwords.
     * @param array|null $google      Google platform values; read from the settings when null.
     *
     * @return array
     */
    public static function settings( string $integration, ?array $google = null ): array {
        if ( null === $google ) {
            if ( ! isset( self::$settings[ $integration ] ) ) {
                self::$settings[ $integration ] = self::build( $integration, self::platform(), Pause::is_paused( $integration ) );
            }

            return self::$settings[ $integration ];
        }

        return self::build( $integration, $google, Pause::is_paused( $integration, array( 'platforms' => array( 'google' => $google ) ) ) );
    }

    /**
     * Settings of one integration from the google platform values.
     *
     * @param string $integration ga4 or adwords.
     * @param array  $google      Google platform values.
     * @param bool   $paused      Whether the integration is paused.
     *
     * @return array
     */
    private static function build( string $integration, array $google, bool $paused ): array {
        $all      = Google::integrations( $google );
        $settings = isset( $all[ $integration ] ) ? $all[ $integration ] : array();

        $settings['enabled'] = ! empty( $settings['enabled'] ) && ! $paused;
        $settings['events']  = isset( $settings['events'] ) ? $settings['events'] : array();

        return $settings;
    }

    /**
     * A Google tag ID (GT-), upper-cased, or an empty string.
     *
     * @param mixed $tag_id Saved value.
     *
     * @return string
     */
    public static function tag_id( $tag_id ): string {
        $tag_id = is_string( $tag_id ) ? strtoupper( trim( $tag_id ) ) : '';

        return preg_match( '/^GT-[A-Z0-9]{4,20}$/', $tag_id ) ? $tag_id : '';
    }

    /**
     * Config IDs with the Analytics ones (G-, GT-) first; the loader uses the first.
     *
     * @param array $ids IDs.
     *
     * @return string[]
     */
    public static function ordered( array $ids ): array {
        $ids       = array_values( array_unique( array_filter( $ids, 'is_string' ) ) );
        $analytics = array_filter(
            $ids,
            function ( $id ) {
                return 0 === strpos( $id, 'G-' ) || 0 === strpos( $id, 'GT-' );
            }
        );

        return array_values( array_merge( $analytics, array_diff( $ids, $analytics ) ) );
    }

    /**
     * The shared head block: the gtag.js loader and gtag('js') unless a loader is already on the page, then one config per ID.
     *
     * @param array $ids         Config IDs.
     * @param bool  $with_loader Print the loader and gtag('js').
     * @param array $params      Extra config parameters by config ID.
     *
     * @return string HTML, empty without IDs.
     */
    public static function head_html( array $ids, bool $with_loader = true, array $params = array() ): string {
        $ids = self::ordered( array_filter( array_map( 'trim', array_filter( $ids, 'is_string' ) ) ) );

        if ( ! $ids ) {
            return '';
        }

        $html = '';

        if ( $with_loader ) {
            $html .= '<script async data-no-optimize="1" data-cfasync="false" src="https://www.googletagmanager.com/gtag/js?id=' . esc_attr( rawurlencode( $ids[0] ) ) . '"></script>' . "\n";
        }

        // wcctGtag keeps working when another plugin redefines gtag; gtag stays defined for scripts that call it, as in 2.x.
        $js  = "window.dataLayer = window.dataLayer || [];\n";
        $js .= "window.wcctGtag = window.wcctGtag || function () { window.dataLayer.push(arguments); };\n";
        $js .= "window.gtag = window.gtag || window.wcctGtag;\n";
        $js .= $with_loader ? "wcctGtag('js', new Date());\n" : '';
        $js .= "(function (pageConfig) {\n";

        foreach ( $ids as $id ) {
            $extra = isset( $params[ $id ] ) && is_array( $params[ $id ] ) ? $params[ $id ] : array();
            $js   .= '    wcctGtag(\'config\', ' . self::encode( $id ) . ', ' . ( $extra ? self::merge_js( 'pageConfig()', $extra ) : 'pageConfig()' ) . ");\n";
        }

        $js .= '})(function () { return ' . OrderUrl::page_config_js() . "; });\n";

        return $html . '<script data-no-optimize="1" data-cfasync="false">' . "\n" . $js . "</script>\n";
    }

    /**
     * Print the shared head block once per page, with the config IDs of every given integration.
     *
     * @param \WCCT_Integration[] $integrations Active integrations.
     *
     * @return void
     */
    public static function print_once( array $integrations ) {
        if ( did_action( self::PRINTED_ACTION ) ) {
            return;
        }

        $ids = array();

        foreach ( $integrations as $integration ) {
            if ( $integration instanceof \WCCT_Integration ) {
                $ids = array_merge( $ids, (array) $integration->get_gtag_ids() );
            }
        }

        $ids = self::ordered( $ids );

        if ( ! $ids ) {
            return;
        }

        $params = array();

        foreach ( $integrations as $integration ) {
            if ( $integration instanceof \WCCT_Integration ) {
                $params = array_merge( $params, (array) $integration->get_gtag_config() );
            }
        }

        // Code that prints its own loader fires wcct_gtag_loader_printed, see WCCT_Integration::print_gtag_loader().
        $with_loader = ! did_action( 'wcct_gtag_loader_printed' );

        echo self::head_html( $ids, $with_loader, $params ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- IDs are attribute-escaped and JSON-encoded.

        if ( $with_loader ) {
            do_action( 'wcct_gtag_loader_printed', $ids[0] );
        }

        /**
         * Fires once the shared Google tag (loader and configs) is printed.
         *
         * @since 3.0.0
         *
         * @param string[] $ids Config IDs, Analytics first.
         */
        do_action( self::PRINTED_ACTION, $ids );
    }

    /**
     * JavaScript adding parameters to a config object (ES5).
     *
     * @param string $config JavaScript expression of the config object.
     * @param array  $extra  Parameters.
     *
     * @return string
     */
    private static function merge_js( string $config, array $extra ): string {
        return '(function (config, extra) { for (var key in extra) { config[key] = extra[key]; } return config; })(' . $config . ', ' . self::encode( (object) $extra ) . ')';
    }

    /**
     * Encode a value for an inline script.
     *
     * @param mixed $data Value.
     *
     * @return string
     */
    private static function encode( $data ): string {
        return (string) wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
    }
}
