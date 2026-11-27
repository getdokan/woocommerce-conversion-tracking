<?php

namespace StorePulse\ConversionTracking\Consent;

/**
 * Reads the WP Consent API (the wp-consent-api plugin); every method is safe when it is not active.
 */
class WpConsentApi {

    /**
     * Whether the WP Consent API is active.
     *
     * @return bool
     */
    public function is_available() {
        return function_exists( 'wp_has_consent' );
    }

    /**
     * Consent type a consent plugin set (optin, optout, ...), empty when none.
     *
     * @return string
     */
    public function get_consent_type() {
        $type = function_exists( 'wp_get_consent_type' ) ? wp_get_consent_type() : '';

        return is_string( $type ) ? $type : '';
    }

    /**
     * Whether the visitor consented to a category.
     *
     * @param string $category Consent category, for example marketing or statistics.
     *
     * @return bool
     */
    public function has_consent( $category ) {
        return $this->is_available() ? (bool) wp_has_consent( $category ) : true;
    }

    /**
     * Prefix of the API's consent cookies (wp_consent_<category>).
     *
     * @return string
     */
    public function get_cookie_prefix() {
        // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- the WP Consent API's own filter.
        $prefix = apply_filters( 'wp_consent_cookie_prefix', 'wp_consent' );

        return is_string( $prefix ) && '' !== $prefix ? $prefix : 'wp_consent';
    }
}
