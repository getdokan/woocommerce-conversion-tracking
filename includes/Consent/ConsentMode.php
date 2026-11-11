<?php

namespace StorePulse\ConversionTracking\Consent;

use StorePulse\ConversionTracking\Settings\Store;

/**
 * The "Respect cookie choices" switch: Google consent defaults, consent tool detection, the WP Consent API bridge and tag gating.
 */
class ConsentMode {

    /**
     * Option holding the switch, 'on' or 'off'; missing reads as off.
     */
    const OPTION = 'wcct_consent_mode';

    /**
     * Platforms whose browser hits Google consent mode covers, so they are never held.
     */
    const GOOGLE_PLATFORMS = array( 'ga4', 'adwords' );

    /**
     * Consent API reader; replaced in tests.
     *
     * @var WpConsentApi|null
     */
    private static $api = null;

    /**
     * Consent tool signals; replaced in tests.
     *
     * @var array|null
     */
    private static $signals = null;

    /**
     * Runtime JavaScript, read once.
     *
     * @var string|null
     */
    private static $runtime = null;

    /**
     * Hook into WordPress.
     *
     * @return void
     */
    public function register() {
        add_action( 'wp_head', array( $this, 'print_head' ), 5 );
        add_action( 'wcct_settings_saved', array( self::class, 'sync_from_settings' ), 10, 2 );

        if ( self::is_enabled() ) {
            add_filter( 'wp_consent_api_registered_' . plugin_basename( WCCT_FILE ), '__return_true' );
        }
    }

    /**
     * Whether consent mode is on.
     *
     * @return bool
     */
    public static function is_enabled() {
        /**
         * Whether "Respect cookie choices" is on, overriding the wcct_consent_mode option.
         *
         * @since 3.0.0
         *
         * @param bool $enabled Whether the option is 'on'.
         */
        return (bool) apply_filters( 'wcct_consent_mode_enabled', 'on' === get_option( self::OPTION, 'off' ) );
    }

    /**
     * Turn consent mode on or off: the 3.0 settings' consent_mode, which is the source, and its mirror.
     *
     * @param bool $enabled On or off.
     *
     * @return bool Whether the mirror changed.
     */
    public static function set_enabled( $enabled ) {
        // While the 2.x settings wait for the migration, it owns the first 3.0 write.
        if ( ! Store::migration_pending() ) {
            $doc                 = Store::load();
            $doc['consent_mode'] = (bool) $enabled;

            Store::save( $doc );
        }

        return self::mirror( (bool) $enabled );
    }

    /**
     * Mirror a settings save that changed consent_mode; other saves leave the switch alone.
     *
     * @param mixed $new Saved 3.0 settings.
     * @param mixed $old Settings before the save.
     *
     * @return void
     */
    public static function sync_from_settings( $new, $old = array() ) {
        if ( ! is_array( $new ) || ! array_key_exists( 'consent_mode', $new ) ) {
            return;
        }

        $was = is_array( $old ) && ! empty( $old['consent_mode'] );

        if ( (bool) $new['consent_mode'] !== $was ) {
            self::mirror( (bool) $new['consent_mode'] );
        }
    }

    /**
     * Write the option the storefront reads.
     *
     * @param bool $enabled On or off.
     *
     * @return bool Whether it changed.
     */
    private static function mirror( bool $enabled ): bool {
        return update_option( self::OPTION, $enabled ? 'on' : 'off' );
    }

    /**
     * The consent tool that owns Google consent mode on this site.
     *
     * @return string|false Tool slug (see ConsentData::SOURCES), or false when none does.
     */
    public static function external_source() {
        $signals = self::signals();

        /**
         * The consent tool that owns Google consent mode; when one does, no Google default and no bridge are printed.
         *
         * @since 3.0.0
         *
         * @param string|false $source  Detected tool slug, or false.
         * @param array        $signals Settings read per tool, null for inactive tools.
         */
        $source = apply_filters( 'wcct_consent_mode_external_source', ConsentData::external_source( $signals ), $signals );

        $source = is_string( $source ) ? strtolower( (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', $source ) ) : '';

        return '' !== $source ? $source : false;
    }

    /**
     * Whether we set the Google default and run the bridge: consent mode on and no consent tool owns it.
     *
     * @return bool
     */
    public static function owns_google_consent() {
        return self::is_enabled() && false === self::external_source();
    }

    /**
     * Consent state for the settings UI and the Doctor.
     *
     * @return array {
     *     @type bool         $enabled                 Whether consent mode is on.
     *     @type string|false $external_source         Slug of the tool that owns Google consent mode, or false.
     *     @type string       $external_source_name    Its name, empty when none.
     *     @type bool         $owns_google_consent     Whether we print the Google default and the bridge.
     *     @type bool         $wp_consent_api          Whether the WP Consent API is active.
     *     @type bool         $complianz_blocks_google Whether Complianz holds back Google tags until consent.
     *     @type string       $message                 "Consent signals are handled by <tool>" message, empty when none.
     * }
     */
    public static function status() {
        $enabled = self::is_enabled();
        $source  = self::external_source();
        $name    = $source ? ConsentData::source_name( $source ) : '';
        $signals = self::signals();

        return array(
            'enabled'                 => $enabled,
            'external_source'         => $source,
            'external_source_name'    => $name,
            'owns_google_consent'     => $enabled && false === $source,
            'wp_consent_api'          => self::api()->is_available(),
            // Complianz blocks gtag.js as statistics whenever it adds Google Analytics, also with consent mode on.
            'complianz_blocks_google' => isset( $signals['complianz']['statistics'] ) && 'google-analytics' === $signals['complianz']['statistics'],
            'message'                 => $source ? sprintf(
                /* translators: %s: consent tool name, for example Complianz */
                __( 'Consent signals are handled by %s. Conversion Tracking will not set its own defaults.', 'woocommerce-conversion-tracking' ),
                $name
            ) : '',
        );
    }

    /**
     * Whether a consent category is allowed: always with consent mode off or no consent type set, else what the WP Consent API says.
     *
     * For server-side sends only (CAPI, Measurement Protocol): it reads the consent cookies of this request, can't see
     * consent types a banner sets only in JavaScript, and must not decide page output that a cache may serve to others.
     *
     * @param string $category Consent category: marketing, statistics, ...
     *
     * @return bool
     */
    public static function allows( $category ) {
        if ( ! self::is_enabled() ) {
            return true;
        }

        $api = self::api();

        if ( ! $api->is_available() ) {
            return true;
        }

        $type = $api->get_consent_type();

        return ConsentData::allows( $type, '' === $type ? true : $api->has_consent( $category ) );
    }

    /**
     * Settings of every consent tool that can own Google consent mode, null for inactive tools.
     *
     * @return array
     */
    public static function signals() {
        if ( null !== self::$signals ) {
            return self::$signals;
        }

        $site_kit = defined( 'GOOGLESITEKIT_VERSION' ) && ! empty( self::option_value( 'googlesitekit_consent_mode', array( 'enabled' ) ) );

        return array(
            'complianz'          => defined( 'CMPLZ_VERSION' ) ? array(
                'consent_mode' => function_exists( 'cmplz_consent_mode' ) && cmplz_consent_mode(),
                'statistics'   => function_exists( 'cmplz_get_option' ) ? (string) cmplz_get_option( 'compile_statistics' ) : '',
                'configured'   => function_exists( 'cmplz_get_option' ) ? (string) cmplz_get_option( 'configuration_by_complianz' ) : '',
            ) : null,
            'cookieyes'          => defined( 'CLI_VERSION' ) ? array(
                'gcm_status' => self::option_value( 'cky_gcm_settings', array( 'status' ) ),
                'connected'  => self::option_value( 'cky_settings', array( 'account', 'connected' ) ),
                'legacy'     => ! function_exists( 'cky_is_legacy' ) || cky_is_legacy(),
            ) : null,
            'cookiebot'          => defined( 'CYBOT_COOKIEBOT_PLUGIN_DIR' ) ? array(
                'cbid'         => get_option( 'cookiebot-cbid', '' ),
                // Multisite: the network CBID applies when the site has none.
                'network_cbid' => function_exists( 'get_site_option' ) ? get_site_option( 'cookiebot-cbid', '' ) : '',
                'gcm'          => get_option( 'cookiebot-gcm', false ),
            ) : null,
            'cookie-notice'      => class_exists( 'Cookie_Notice' ) ? array(
                'gcm' => self::option_value( 'cookie_notice_app_blocking', array( 'google_consent_default' ) ),
            ) : null,
            'cookieadmin'        => defined( 'COOKIEADMIN_PRO_VERSION' ) ? array(
                'gcm' => self::option_value( 'cookieadmin_settings', array( 'google_consent_mode_v2' ) ),
            ) : null,
            'real-cookie-banner' => defined( 'RCB_FILE' ) ? array(
                'gcm' => get_option( 'rcb-gcm', false ),
            ) : null,
            'ga-for-woocommerce' => class_exists( 'WC_Google_Gtag_JS' ) ? array(
                'ga_id' => self::option_value( 'woocommerce_google_analytics_settings', array( 'ga_id' ) ),
                // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- GA for WooCommerce's own filter; an empty array turns its default off.
                'modes' => apply_filters( 'woocommerce_ga_gtag_consent_modes', array( array( 'analytics_storage' => 'denied' ) ) ),
            ) : null,
            'site-kit'           => array(
                // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Site Kit's own filter.
                'status' => apply_filters( 'googlesitekit_consent_mode_status', $site_kit ? 'enabled' : 'disabled' ),
            ),
        );
    }

    /**
     * Print the consent block in <head>, before every Google config.
     *
     * @return void
     */
    public function print_head() {
        if ( is_admin() || ! function_exists( 'WC' ) ) {
            return;
        }

        $js = self::head_script();

        if ( '' !== $js ) {
            wp_print_inline_script_tag(
                $js,
                array(
                    'id'               => 'wcct-consent-mode',
                    'data-no-optimize' => '1',
                )
            );
        }
    }

    /**
     * The consent block's JavaScript: our Google default when we own it, then the runtime. Empty with consent mode off.
     *
     * @return string
     */
    public static function head_script() {
        if ( ! self::is_enabled() ) {
            return '';
        }

        $api    = self::api();
        $owns   = false === self::external_source();
        $type   = $api->is_available() ? $api->get_consent_type() : '';
        $config = array(
            'api'          => $api->is_available(),
            'bridge'       => $owns && $api->is_available(),
            'cookiePrefix' => $api->get_cookie_prefix(),
            'type'         => $type,
            'typeExpected' => ConsentData::type_expected( $api->is_available(), $type, self::signals() ),
        );

        $js = $owns ? self::google_default_script() : '';

        return $js . 'window.wcctConsentConfig = ' . self::json( $config ) . ";\n" . self::runtime();
    }

    /**
     * Whether a consent tool that sets a WP Consent API type in the browser is active.
     *
     * @return bool
     */
    public static function consent_tool_active() {
        return array() !== ConsentData::type_tools( self::signals() );
    }

    /**
     * Active consent banners that report no consent type to us (none do without the WP Consent API), so our defaults apply.
     *
     * @return string[] Tool slugs.
     */
    public static function silent_tools() {
        $signals  = self::signals();
        $detected = ConsentData::detected_tools( $signals );

        return self::api()->is_available() ? array_values( array_diff( $detected, ConsentData::type_tools( $signals ) ) ) : $detected;
    }

    /**
     * Our Google consent default, skipped in the browser when a default is already in the dataLayer.
     *
     * @return string
     */
    public static function google_default_script() {
        /**
         * Google consent default; return an empty array to print none.
         *
         * @since 3.0.0
         *
         * @param array $defaults Consent types, region and wait_for_update.
         */
        $defaults = apply_filters( 'wcct_consent_defaults', ConsentData::google_default() );

        $json = is_array( $defaults ) && ! empty( $defaults ) ? self::json( $defaults ) : '';

        if ( '' === $json ) {
            return '';
        }

        return "window.dataLayer = window.dataLayer || [];\n"
            . "(function () {\n"
            . "    var dl = window.dataLayer;\n"
            . "    for (var i = 0; i < dl.length; i++) {\n"
            . "        if (dl[i] && dl[i][0] === 'consent' && dl[i][1] === 'default') return;\n"
            . "    }\n"
            . "    var g = function () { dl.push(arguments); };\n"
            . "    g('consent', 'default', " . $json . ");\n"
            . "    g('set', 'ads_data_redaction', true);\n"
            . "})();\n";
    }

    /**
     * JavaScript that calls a callback with (granted, first) now and on every consent change; empty with consent mode off.
     *
     * @param string $category Consent category.
     * @param string $body     Callback body; granted and first are in scope.
     *
     * @return string
     */
    public static function sync_js( $category, $body ) {
        return self::runtime_call( 'sync', $category, 'function (granted, first) { ' . $body . ' }' );
    }

    /**
     * JavaScript that runs code once the category is allowed; empty with consent mode off.
     *
     * @param string $category Consent category.
     * @param string $body     Code to run.
     *
     * @return string
     */
    public static function when_js( $category, $body ) {
        return self::runtime_call( 'when', $category, 'function () { ' . $body . ' }' );
    }

    /**
     * JavaScript that loads a script once the category is allowed, then runs code; empty with consent mode off.
     *
     * @param string $category Consent category.
     * @param string $src      Script URL.
     * @param string $body     Code to run after it loaded.
     *
     * @return string
     */
    public static function script_js( $category, $src, $body = '' ) {
        return self::runtime_call( 'script', $category, self::json( $src ) . ( '' !== $body ? ', function () { ' . $body . ' }' : '' ) );
    }

    /**
     * Markup with its JavaScript held until the category is allowed; unchanged with consent mode off.
     *
     * @param string $html     Markup.
     * @param string $category Consent category.
     *
     * @return string
     */
    public static function hold_markup( $html, $category ) {
        return self::is_enabled() ? ConsentData::hold_scripts( $html, $category ) : $html;
    }

    /**
     * The pending events runner with each platform's queued code held until its category is allowed; unchanged with consent mode off.
     *
     * Google platforms run at once, like their other hits. Empty when the runner's script insertion isn't found, so queued code never runs ungated.
     *
     * @param string $runner     Runner JavaScript (Pipeline::runner_script()).
     * @param array  $categories Consent category per platform ID; others need marketing.
     *
     * @return string
     */
    public static function gate_runner( $runner, array $categories ) {
        if ( ! self::is_enabled() ) {
            return $runner;
        }

        // An empty category runs at once.
        foreach ( self::GOOGLE_PLATFORMS as $id ) {
            if ( isset( $categories[ $id ] ) ) {
                $categories[ $id ] = '';
            }
        }

        $gated = preg_replace_callback(
            '/var script = document\.createElement\(\'script\'\);\s*script\.setAttribute\(\'data-no-optimize\', \'1\'\);\s*script\.text = entries\[i\]\.scripts\[platform\];\s*document\.body\.appendChild\(script\);/',
            function ( $match ) use ( $categories ) {
                return 'var category = (' . self::json( (object) $categories ) . ')[platform]; '
                    . 'if (category === \'\') { ' . $match[0] . ' } '
                    . 'else if (window.wcctConsent) { window.wcctConsent.inline(category || \'marketing\', entries[i].scripts[platform]); }';
            },
            (string) $runner,
            1,
            $count
        );

        return 1 === $count ? (string) $gated : '';
    }

    /**
     * JavaScript that holds a global function's calls until the category is allowed; empty with consent mode off.
     *
     * @param string $category Consent category.
     * @param string $name     Global function name.
     *
     * @return string
     */
    public static function wrap_js( $category, $name ) {
        return self::runtime_call( 'wrap', $category, '', self::json( $name ) );
    }

    /**
     * Replace the consent API reader; null restores the WP Consent API.
     *
     * @param WpConsentApi|null $api Reader.
     *
     * @return void
     */
    public static function use_api( $api = null ) {
        self::$api = $api;
    }

    /**
     * Replace the consent tool signals; null reads the site again.
     *
     * @param array|null $signals Signals as signals() returns them.
     *
     * @return void
     */
    public static function use_signals( $signals = null ) {
        self::$signals = is_array( $signals ) ? $signals : null;
    }

    /**
     * Consent API reader.
     *
     * @return WpConsentApi
     */
    public static function api() {
        if ( ! self::$api instanceof WpConsentApi ) {
            self::$api = new WpConsentApi();
        }

        return self::$api;
    }

    /**
     * A runtime call guarded so a missing runtime never throws; no "&&", so it survives wp_kses.
     *
     * @param string $method   Runtime method.
     * @param string $category Consent category.
     * @param string $args     Arguments after the category.
     * @param string $first    Argument before the category, for wrap().
     *
     * @return string
     */
    private static function runtime_call( $method, $category, $args, $first = '' ) {
        if ( ! self::is_enabled() ) {
            return '';
        }

        $params = ( '' !== $first ? $first . ', ' : '' ) . self::json( (string) $category ) . ( '' !== $args ? ', ' . $args : '' );

        return 'if (window.wcctConsent) { window.wcctConsent.' . $method . '(' . $params . '); }';
    }

    /**
     * The runtime JavaScript (assets/js/consent-mode.js).
     *
     * @return string
     */
    private static function runtime() {
        if ( null === self::$runtime ) {
            $file          = dirname( __DIR__, 2 ) . '/assets/js/consent-mode.js';
            self::$runtime = is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
        }

        return self::$runtime;
    }

    /**
     * JSON for inline JavaScript.
     *
     * @param mixed $value Value.
     *
     * @return string
     */
    private static function json( $value ) {
        return (string) wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP );
    }

    /**
     * A nested value of an array option, null when missing.
     *
     * @param string   $option Option name.
     * @param string[] $keys   Keys, outermost first.
     *
     * @return mixed
     */
    private static function option_value( $option, array $keys ) {
        $value = get_option( $option, null );

        foreach ( $keys as $key ) {
            if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) {
                return null;
            }

            $value = $value[ $key ];
        }

        return $value;
    }
}
