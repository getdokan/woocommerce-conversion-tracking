<?php

namespace StorePulse\ConversionTracking\REST;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * The catalog route. The catalog feed is a Pro feature, so Free only answers that it is locked; Pro replaces this class.
 */
class CatalogController extends Controller {

    /**
     * Route base.
     *
     * @var string
     */
    protected $rest_base = 'catalog';

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
                'schema' => array( $this, 'get_public_item_schema' ),
            )
        );
    }

    /**
     * Locked: the page shows the upgrade card.
     *
     * @param WP_REST_Request $request Request.
     *
     * @return WP_REST_Response
     */
    public function get_item( $request ) {
        return rest_ensure_response( array( 'locked' => true ) );
    }

    /**
     * JSON schema of the catalog resource on Free.
     *
     * @return array
     */
    public function get_item_schema() {
        if ( $this->schema ) {
            return $this->add_additional_fields_schema( $this->schema );
        }

        $this->schema = array(
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'wcct_catalog',
            'type'       => 'object',
            'properties' => array(
                'locked' => array(
                    'description' => __( 'Whether the feature needs Pro.', 'woocommerce-conversion-tracking' ),
                    'type'        => 'boolean',
                    'context'     => array( 'view' ),
                ),
            ),
        );

        return $this->add_additional_fields_schema( $this->schema );
    }
}
