<?php

namespace StorePulse\ConversionTracking\REST;

use StorePulse\ConversionTracking\Doctor\Check;
use StorePulse\ConversionTracking\Doctor\Doctor;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * The doctor routes: the last report, a run, dismiss with undo, and "seen" for the dashboard badge.
 */
class DoctorController extends Controller {

    /**
     * Route base.
     *
     * @var string
     */
    protected $rest_base = 'doctor';

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
                    'callback'            => array( $this, 'run' ),
                    'permission_callback' => array( $this, 'permissions_check' ),
                    'args'                => array(
                        'checks' => array(
                            'description' => __( 'IDs of the checks to run again; all of them when empty.', 'woocommerce-conversion-tracking' ),
                            'type'        => 'array',
                            'items'       => array(
                                'type'    => 'string',
                                'pattern' => '^[a-z0-9_-]+$',
                            ),
                            'default'     => array(),
                        ),
                        'scope'  => array(
                            'description' => __( 'all, or only the local or remote checks.', 'woocommerce-conversion-tracking' ),
                            'type'        => 'string',
                            'enum'        => array( Doctor::SCOPE_ALL, Check::LOCAL, Check::REMOTE ),
                            'default'     => Doctor::SCOPE_ALL,
                        ),
                    ),
                ),
                'schema' => array( $this, 'get_public_item_schema' ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/dismiss',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'dismiss' ),
                    'permission_callback' => array( $this, 'permissions_check' ),
                    'args'                => array(
                        'hash'      => array(
                            'description' => __( 'Hash of the finding.', 'woocommerce-conversion-tracking' ),
                            'type'        => 'string',
                            'pattern'     => '^[a-f0-9]{32}$',
                            'required'    => true,
                        ),
                        'dismissed' => array(
                            'description' => __( 'False brings a dismissed finding back (Undo).', 'woocommerce-conversion-tracking' ),
                            'type'        => 'boolean',
                            'default'     => true,
                        ),
                    ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/seen',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'seen' ),
                    'permission_callback' => array( $this, 'permissions_check' ),
                ),
            )
        );
    }

    /**
     * The last report; nothing runs.
     *
     * @param WP_REST_Request $request Request.
     *
     * @return WP_REST_Response
     */
    public function get_item( $request ) {
        return rest_ensure_response( Doctor::report() );
    }

    /**
     * Run the checks.
     *
     * @param WP_REST_Request $request Request.
     *
     * @return WP_REST_Response
     */
    public function run( $request ) {
        return rest_ensure_response( Doctor::run( array_map( 'strval', (array) $request->get_param( 'checks' ) ), (string) $request->get_param( 'scope' ) ) );
    }

    /**
     * Dismiss a finding or undo it.
     *
     * @param WP_REST_Request $request Request.
     *
     * @return WP_REST_Response
     */
    public function dismiss( $request ) {
        Doctor::dismiss( (string) $request->get_param( 'hash' ), (bool) $request->get_param( 'dismissed' ) );

        return rest_ensure_response( Doctor::report() );
    }

    /**
     * Clear the dashboard badge.
     *
     * @param WP_REST_Request $request Request.
     *
     * @return WP_REST_Response
     */
    public function seen( $request ) {
        Doctor::seen();

        return rest_ensure_response( Doctor::report() );
    }

    /**
     * JSON schema of the report.
     *
     * @return array
     */
    public function get_item_schema() {
        if ( $this->schema ) {
            return $this->add_additional_fields_schema( $this->schema );
        }

        $text  = array( 'type' => 'string' );
        $texts = array(
            'type'  => 'array',
            'items' => $text,
        );
        $line  = array(
            'type'       => 'object',
            'properties' => array(
                'check'  => $text,
                'label'  => $text,
                'reason' => $text,
            ),
        );

        $this->schema = array(
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'wcct_doctor',
            'type'       => 'object',
            'properties' => array(
                'ran_at'       => array(
                    'description' => __( 'When the checks last ran (ISO 8601, UTC), null before the first run.', 'woocommerce-conversion-tracking' ),
                    'type'        => array( 'string', 'null' ),
                ),
                'ran_at_label' => $text,
                'ran_ago'      => $text,
                'total'        => array(
                    'description' => __( 'Checks that apply to this store.', 'woocommerce-conversion-tracking' ),
                    'type'        => 'integer',
                ),
                'checked'      => array(
                    'description' => __( 'Checks that ran: "We checked N things".', 'woocommerce-conversion-tracking' ),
                    'type'        => 'integer',
                ),
                'findings'     => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'       => 'object',
                        'properties' => array(
                            'hash'       => $text,
                            'check'      => $text,
                            'key'        => $text,
                            'found'      => $text,
                            'why'        => $text,
                            'action'     => array(
                                'type'       => 'object',
                                'properties' => array(
                                    'type'     => array(
                                        'type' => 'string',
                                        'enum' => array( 'link', 'recheck' ),
                                    ),
                                    'label'    => $text,
                                    'url'      => $text,
                                    'external' => array( 'type' => 'boolean' ),
                                    'confirm'  => $text,
                                ),
                            ),
                            'confidence' => array(
                                'type' => 'string',
                                'enum' => array( 'sure', 'unsure' ),
                            ),
                            'severity'   => array(
                                'type' => 'string',
                                'enum' => array( 'warning', 'info' ),
                            ),
                            'label'      => $text,
                            'tags'       => $texts,
                            'steps'      => $texts,
                            'dismissed'  => array( 'type' => 'boolean' ),
                            'new'        => array( 'type' => 'boolean' ),
                        ),
                    ),
                ),
                'working'      => array(
                    'type'  => 'array',
                    'items' => $line,
                ),
                'skipped'      => array(
                    'type'  => 'array',
                    'items' => $line,
                ),
                'new'          => array(
                    'description' => __( 'New findings from the quiet re-check that the merchant has not seen.', 'woocommerce-conversion-tracking' ),
                    'type'        => 'integer',
                ),
            ),
        );

        return $this->add_additional_fields_schema( $this->schema );
    }
}
