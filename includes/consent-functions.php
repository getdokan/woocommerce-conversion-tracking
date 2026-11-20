<?php

use StorePulse\ConversionTracking\Consent\ConsentMode;

/**
 * Whether a consent category allows tracking: true with consent mode off or when no consent plugin sets a consent type
 *
 * For server-side sends only (CAPI, Measurement Protocol). It can't see consent types a banner sets only in
 * JavaScript, and it must not decide page output a cache may serve to other visitors; tags in the page are
 * held by the consent runtime instead.
 *
 * @param string $category WP Consent API category: marketing, statistics, preferences, functional or statistics-anonymous.
 *
 * @return bool
 */
function wcct_consent_allows( $category ) {
    /**
     * Whether a consent category allows tracking.
     *
     * @since 3.0.0
     *
     * @param bool   $allowed  What the WP Consent API says.
     * @param string $category Consent category.
     */
    return (bool) apply_filters( 'wcct_consent_allows', ConsentMode::allows( (string) $category ), (string) $category );
}
