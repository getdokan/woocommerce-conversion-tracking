<?php

namespace StorePulse\ConversionTracking\REST;

use StorePulse\ConversionTracking\Onboarding\State;
use StorePulse\ConversionTracking\Settings\Schema;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * The onboarding route: setup wizard progress. Settings are saved through the settings route; a finished wizard answers 409.
 */
class OnboardingController extends Controller {

    /**
     * Route base.
     *
     * @var string
     */
    protected $rest_base = 'onboarding';

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
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array( $this, 'update_item' ),
                    'permission_callback' => array( $this, 'permissions_check' ),
                    'args'                => array(
                        'action'    => array(
                            'description' => __( 'save stores a draft, skip stores it and marks the wizard skipped, finish marks it done.', 'woocommerce-conversion-tracking' ),
                            'type'        => 'string',
                            'enum'        => State::ACTIONS,
                            'default'     => 'save',
                        ),
                        'step'      => array(
                            'description' => __( 'Step reached; the furthest one is kept.', 'woocommerce-conversion-tracking' ),
                            'type'        => 'integer',
                            'minimum'     => 0,
                            'maximum'     => State::STEPS,
                        ),
                        'platforms' => array(
                            'description' => __( 'Picked platform IDs, as the settings route names them.', 'woocommerce-conversion-tracking' ),
                            'type'        => 'array',
                            'items'       => array( 'type' => 'string' ),
                        ),
                        'eu'        => array(
                            'description' => __( 'Whether the store sells to Europe.', 'woocommerce-conversion-tracking' ),
                            'type'        => 'string',
                            'enum'        => State::EU_ANSWERS,
                        ),
                    ),
                ),
                'schema' => array( $this, 'get_public_item_schema' ),
            )
        );
    }

    /**
     * The wizard state.
     *
     * @param WP_REST_Request $request Request.
     *
     * @return WP_REST_Response
     */
    public function get_item( $request ) {
        return rest_ensure_response( State::load( self::platform_ids() ) );
    }

    /**
     * Save a draft, skip or finish; never touches the settings.
     *
     * @param WP_REST_Request $request Request.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function update_item( $request ) {
        $ids   = self::platform_ids();
        $state = State::load( $ids );

        // A re-run works on a draft in the browser and saves through the settings route.
        if ( 'done' === $state['status'] ) {
            return new WP_Error(
                'wcct_rest_onboarding_done',
                __( 'The setup wizard is already finished.', 'woocommerce-conversion-tracking' ),
                array( 'status' => 409 )
            );
        }

        $params = array();

        foreach ( array( 'action', 'step', 'platforms', 'eu' ) as $key ) {
            if ( null !== $request->get_param( $key ) ) {
                $params[ $key ] = $request->get_param( $key );
            }
        }

        $result = State::apply( $state, $params, $ids );

        if ( $result['errors'] ) {
            return new WP_Error(
                'wcct_rest_invalid_onboarding',
                __( 'The wizard state is not valid.', 'woocommerce-conversion-tracking' ),
                array(
                    'status' => 400,
                    'errors' => $result['errors'],
                )
            );
        }

        State::save( $result['state'] );

        return rest_ensure_response( $result['state'] );
    }

    /**
     * Platform IDs the settings schema lists.
     *
     * @return string[]
     */
    private static function platform_ids(): array {
        return array_map( 'strval', array_keys( Schema::get()['platforms'] ) );
    }

    /**
     * JSON schema of the wizard state.
     *
     * @return array
     */
    public function get_item_schema() {
        if ( $this->schema ) {
            return $this->add_additional_fields_schema( $this->schema );
        }

        $this->schema = array(
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'wcct_onboarding',
            'type'       => 'object',
            'properties' => array(
                'status'    => array(
                    'description' => __( 'new, in_progress, skipped or done.', 'woocommerce-conversion-tracking' ),
                    'type'        => 'string',
                    'enum'        => State::STATUSES,
                    'context'     => array( 'view', 'edit' ),
                ),
                'step'      => array(
                    'description' => __( 'Furthest step reached, 0 before the wizard opens.', 'woocommerce-conversion-tracking' ),
                    'type'        => 'integer',
                    'context'     => array( 'view', 'edit' ),
                ),
                'platforms' => array(
                    'description' => __( 'Picked platform IDs.', 'woocommerce-conversion-tracking' ),
                    'type'        => 'array',
                    'items'       => array( 'type' => 'string' ),
                    'context'     => array( 'view', 'edit' ),
                ),
                'eu'        => array(
                    'description' => __( 'Europe answer: yes, no or empty.', 'woocommerce-conversion-tracking' ),
                    'type'        => 'string',
                    'enum'        => State::EU_ANSWERS,
                    'context'     => array( 'view', 'edit' ),
                ),
            ),
        );

        return $this->add_additional_fields_schema( $this->schema );
    }
}
