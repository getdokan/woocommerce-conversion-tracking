<?php

namespace StorePulse\ConversionTracking\Consent;

/**
 * Pure consent data: Google consent regions and default, consent tool detection and the allow rule.
 */
class ConsentData {

    /**
     * EU27, Iceland, Liechtenstein, Norway, the UK and Switzerland; the list Site Kit and Google Analytics for WooCommerce use.
     */
    const REGIONS = array( 'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE', 'IS', 'LI', 'NO', 'GB', 'CH' );

    /**
     * Consent tools that can own Google consent mode, slug => name, in detection order.
     */
    const SOURCES = array(
        'complianz'          => 'Complianz',
        'cookieyes'          => 'CookieYes',
        'cookiebot'          => 'Cookiebot',
        'cookie-notice'      => 'Cookie Notice',
        'cookieadmin'        => 'CookieAdmin',
        'real-cookie-banner' => 'Real Cookie Banner',
        'site-kit'           => 'Site Kit by Google',
        'ga-for-woocommerce' => 'Google Analytics for WooCommerce',
    );

    /**
     * Script types that run as JavaScript; empty means no type attribute.
     */
    const JS_TYPES = array( '', 'text/javascript', 'application/javascript', 'text/ecmascript', 'application/ecmascript', 'module' );

    /**
     * Our region-scoped Google consent default.
     *
     * @return array
     */
    public static function google_default() {
        return array(
            'ad_storage'         => 'denied',
            'analytics_storage'  => 'denied',
            'ad_user_data'       => 'denied',
            'ad_personalization' => 'denied',
            'region'             => self::REGIONS,
            'wait_for_update'    => 500,
        );
    }

    /**
     * The consent tool that owns Google consent mode, from plain site signals.
     *
     * @param array $signals Per tool slug: null when the tool is not active, else its settings (see ConsentMode::signals()).
     *
     * @return string|false Tool slug, or false when none does.
     */
    public static function external_source( array $signals ) {
        foreach ( array_keys( self::SOURCES ) as $slug ) {
            if ( isset( $signals[ $slug ] ) && is_array( $signals[ $slug ] ) && self::owns_google_consent( $slug, $signals[ $slug ] ) ) {
                return $slug;
            }
        }

        return false;
    }

    /**
     * Whether one active consent tool sets its own Google consent default.
     *
     * @param string $slug   Tool slug.
     * @param array  $signal Tool settings.
     *
     * @return bool
     */
    public static function owns_google_consent( $slug, array $signal ) {
        $get = function ( $key ) use ( $signal ) {
            return isset( $signal[ $key ] ) ? $signal[ $key ] : null;
        };

        switch ( $slug ) {
            case 'complianz':
                // Premium consent mode, or statistics through Google Tag Gateway.
                return (bool) $get( 'consent_mode' ) || 'gtg' === $get( 'statistics' );

            case 'cookieyes':
                // Only when connected to the CookieYes web app.
                return self::truthy( $get( 'gcm_status' ) ) && self::truthy( $get( 'connected' ) );

            case 'cookiebot':
                // Prints whenever a CBID is set, also when toggled off ('0').
                $gcm = $get( 'gcm' );

                return '' !== trim( (string) $get( 'cbid' ) ) && false !== $gcm && '' !== $gcm && null !== $gcm;

            case 'cookie-notice':
            case 'cookieadmin':
            case 'real-cookie-banner':
                return self::truthy( $get( 'gcm' ) );

            case 'site-kit':
                return 'enabled' === $get( 'status' );

            case 'ga-for-woocommerce':
                // Prints its region-scoped default in <head> after ours, whenever a GA ID is set.
                return '' !== trim( (string) $get( 'ga_id' ) ) && self::truthy( $get( 'modes' ) );
        }

        return false;
    }

    /**
     * Name of a consent tool slug; unknown slugs are shown as given.
     *
     * @param string $slug Tool slug.
     *
     * @return string
     */
    public static function source_name( $slug ) {
        return isset( self::SOURCES[ $slug ] ) ? self::SOURCES[ $slug ] : (string) $slug;
    }

    /**
     * Hold the JavaScript <script> tags of raw markup until consent: type="text/plain" data-wcct-consent="<category>"; the runtime runs them on consent.
     *
     * @param string $html     Markup.
     * @param string $category Consent category.
     *
     * @return string
     */
    public static function hold_scripts( $html, $category ) {
        if ( ! is_string( $html ) || false === stripos( $html, '<script' ) ) {
            return $html;
        }

        $result = preg_replace_callback(
            '/<script\b([^>]*)>/i',
            function ( $match ) use ( $category ) {
                $attributes = $match[1];
                $type       = '';

                if ( preg_match( '/\stype\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $attributes, $found ) ) {
                    $type       = strtolower( trim( isset( $found[3] ) ? $found[3] : ( isset( $found[2] ) && '' !== $found[2] ? $found[2] : $found[1] ) ) );
                    $attributes = str_replace( $found[0], '', $attributes );
                }

                if ( ! in_array( $type, self::JS_TYPES, true ) ) {
                    return $match[0];
                }

                $held = ' type="text/plain" data-wcct-consent="' . htmlspecialchars( (string) $category, ENT_QUOTES ) . '"';
                $held .= 'module' === $type ? ' data-wcct-type="module"' : '';

                return '<script' . $held . $attributes . '>';
            },
            $html
        );

        return null === $result ? $html : $result;
    }

    /**
     * Whether a category is allowed: always without a consent type, else what the consent API says.
     *
     * @param string $consent_type Consent type set by a consent plugin, empty when none.
     * @param bool   $has_consent  Whether the consent API reports consent for the category.
     *
     * @return bool
     */
    public static function allows( $consent_type, $has_consent ) {
        if ( ! is_string( $consent_type ) || '' === $consent_type ) {
            return true;
        }

        return (bool) $has_consent;
    }

    /**
     * Whether a stored setting means "on": true, 1, '1', 'yes', 'on', 'true' or a non-empty array.
     *
     * @param mixed $value Value.
     *
     * @return bool
     */
    private static function truthy( $value ) {
        if ( is_array( $value ) ) {
            return ! empty( $value );
        }

        if ( is_string( $value ) ) {
            return in_array( strtolower( trim( $value ) ), array( '1', 'yes', 'on', 'true', 'enabled' ), true );
        }

        return (bool) $value;
    }
}
