<?php

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
     * Required all integration class
     *
     * @return void
     */
    public function includes_integration() {
        $this->integrations['facebook']     = require_once WCCT_INCLUDES . '/integrations/class-integration-facebook.php';
        $this->integrations['google']       = require_once WCCT_INCLUDES . '/integrations/class-integration-google.php';
        $this->integrations['twitter']      = require_once WCCT_INCLUDES . '/integrations/class-integration-twitter.php';


        $this->integrations     = apply_filters( 'wcct_integrations', $this->integrations );

        $this->integrations['custom']       = require_once WCCT_INCLUDES . '/integrations/class-integration-custom.php';
    }

    /**
     * Get all active integrations
     *
     * @return array
     */
    public function get_active_integrations() {
        $integrations = $this->integrations;
        $active       = array();

        foreach ( $integrations as $integration ) {
            if ( $integration->is_enabled() ) {
                $active[] = $integration;
            }
        }

        return $active;
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
