<?php

namespace StorePulse\ConversionTracking\REST;

use StorePulse\ConversionTracking\Dashboard\Metrics;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * The Dashboard's metrics route: last 24 hours' counts, the hourly series, platform cards, the last Doctor run and what to do next.
 */
class MetricsController extends Controller {

    /**
     * Route base.
     *
     * @var string
     */
    protected $rest_base = 'metrics';

    /**
     * Register the route.
     *
     * @return void
     */
    public function register_routes() {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_item' ),
                    'permission_callback' => array( $this, 'permissions_check' ),
                ),
            )
        );
    }

    /**
     * The metrics now.
     *
     * @param WP_REST_Request $request Request.
     *
     * @return WP_REST_Response
     */
    public function get_item( $request ) {
        return rest_ensure_response( Metrics::build() );
    }
}
