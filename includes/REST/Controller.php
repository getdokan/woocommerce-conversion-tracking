<?php

namespace StorePulse\ConversionTracking\REST;

use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;

/**
 * Base for every wcct/v1 controller: routes are open to users with the manage capability, unless a controller says it is public (EventsController).
 */
abstract class Controller extends WP_REST_Controller {

    /**
     * Route namespace.
     *
     * @var string
     */
    protected $namespace = 'wcct/v1';

    /**
     * Allow users who can manage Conversion Tracking.
     *
     * @param WP_REST_Request $request Request.
     *
     * @return true|WP_Error
     */
    public function permissions_check( $request ) {
        if ( current_user_can( wcct_manage_cap() ) ) {
            return true;
        }

        return new WP_Error(
            'rest_forbidden',
            __( 'Sorry, you are not allowed to manage Conversion Tracking.', 'woocommerce-conversion-tracking' ),
            array( 'status' => rest_authorization_required_code() )
        );
    }
}
