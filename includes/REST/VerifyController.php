<?php

namespace StorePulse\ConversionTracking\REST;

use StorePulse\ConversionTracking\Onboarding\Verify;
use WP_REST_Request;
use WP_REST_Server;

/**
 * The wizard's Verify step: POST opens a test link, GET says whether its page view checked in.
 */
class VerifyController extends Controller {

    /**
     * Route base.
     *
     * @var string
     */
    protected $rest_base = 'onboarding/verify';

    /**
     * Register the routes.
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
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'create_item' ),
                    'permission_callback' => array( $this, 'permissions_check' ),
                ),
            )
        );
    }

    /**
     * The open test: active, started_at, seen_at and platforms (integration ID => library loaded).
     *
     * @param WP_REST_Request $request Request.
     *
     * @return \WP_REST_Response
     */
    public function get_item( $request ) {
        return rest_ensure_response( Verify::state() );
    }

    /**
     * Open a new test link: url and started_at.
     *
     * @param WP_REST_Request $request Request.
     *
     * @return \WP_REST_Response
     */
    public function create_item( $request ) {
        return rest_ensure_response( Verify::start() );
    }
}
