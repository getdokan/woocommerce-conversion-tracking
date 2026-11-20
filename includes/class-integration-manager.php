<?php

use StorePulse\ConversionTracking\Platforms\Registry;

/**
 * Manager Class
 */
class WCCT_Integration_Manager {

    /**
     * All integrations
     *
     * @var array
     */
    private $integrations = array();

    /**
     * Constructor for WC_Conversion_Tracking_Integration_Manager Class
     */
    public function __construct() {
        $this->includes_integration();
    }

    /**
     * Keys the deprecated wcct_integrations filter used, per integration ID
     *
     * @var array
     */
    const LEGACY_KEYS = array(
        'adwords'  => 'google',
        'bing_ads' => 'bing-ads',
    );

    /**
     * Required all integration class
     *
     * Platforms register through wcct_platforms, keyed by integration ID.
     * The Custom integration is always last.
     *
     * @return void
     */
    public function includes_integration() {
        require_once WCCT_INCLUDES . '/integrations/class-integration-facebook.php';
        require_once WCCT_INCLUDES . '/integrations/class-integration-google.php';
        require_once WCCT_INCLUDES . '/integrations/class-integration-twitter.php';
        require_once WCCT_INCLUDES . '/integrations/class-integration-ga4.php';
        require_once WCCT_INCLUDES . '/integrations/class-integration-custom.php';

        $platforms = Registry::normalise(
            array(
                new WCCT_Integration_Facebook(),
                new WCCT_Integration_Google(),
                new WCCT_Integration_Twitter(),
                new WCCT_Integration_GA4(),
            )
        );

        $platforms = $this->apply_legacy_filter( $platforms );

        /**
         * Platforms, keyed by integration ID. Add or replace a WCCT_Integration under its ID; a third-party platform keeps its 2.x switch unless its schema marks required_to_send IDs or it overrides is_enabled().
         *
         * @since 3.0.0
         *
         * @param WCCT_Integration[] $platforms Platforms.
         */
        $platforms = Registry::normalise( apply_filters( 'wcct_platforms', $platforms ) );

        unset( $platforms['custom'] );
        $platforms['custom'] = new WCCT_Integration_Custom();

        $this->integrations = $platforms;
    }

    /**
     * Run the deprecated wcct_integrations filter, keyed as before 3.0
     *
     * @param WCCT_Integration[] $platforms Platforms keyed by ID.
     *
     * @return WCCT_Integration[]
     */
    private function apply_legacy_filter( $platforms ) {
        if ( ! has_filter( 'wcct_integrations' ) ) {
            return $platforms;
        }

        $legacy = array();

        foreach ( $platforms as $id => $platform ) {
            $legacy[ isset( self::LEGACY_KEYS[ $id ] ) ? self::LEGACY_KEYS[ $id ] : $id ] = $platform;
        }

        return Registry::normalise( apply_filters_deprecated( 'wcct_integrations', array( $legacy ), '3.0.0', 'wcct_platforms', __( 'Register platforms through wcct_platforms, keyed by integration ID. Each platform must extend WCCT_Integration; anything else is dropped.', 'woocommerce-conversion-tracking' ) ) );
    }

    /**
     * Get all active integrations: enabled and not paused
     *
     * @return array
     */
    public function get_active_integrations() {
        return array_values( Registry::active( $this->integrations ) );
    }

    /**
     * Get all integration
     *
     * @return array
     */
    public function get_integrations() {
        if ( empty( $this->integrations ) ) {
	        return array();
        }

        return $this->integrations;
    }

}
