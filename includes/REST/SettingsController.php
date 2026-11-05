<?php

namespace StorePulse\ConversionTracking\REST;

use StorePulse\ConversionTracking\Settings\Schema;
use StorePulse\ConversionTracking\Settings\Store;
use StorePulse\ConversionTracking\Settings\Values;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * The settings and settings/schema routes.
 */
class SettingsController extends Controller {

    /**
     * Route base.
     *
     * @var string
     */
    protected $rest_base = 'settings';

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
                ),
                'schema' => array( $this, 'get_public_item_schema' ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/schema',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_settings_schema' ),
                    'permission_callback' => array( $this, 'permissions_check' ),
                ),
            )
        );
    }

    /**
     * Every platform's values.
     *
     * @param WP_REST_Request $request Request.
     *
     * @return WP_REST_Response
     */
    public function get_item( $request ) {
        return rest_ensure_response( Values::get( Schema::get(), Store::load() ) );
    }

    /**
     * Save the posted values; only the keys posted change.
     *
     * @param WP_REST_Request $request Request.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function update_item( $request ) {
        // Runs or failed on this request's plugins_loaded; a save now would stop it for good.
        if ( Store::migration_pending() ) {
            return new WP_Error(
                'wcct_rest_migration_pending',
                __( 'Your settings are being updated to the new version. Try again in a minute.', 'woocommerce-conversion-tracking' ),
                array( 'status' => 409 )
            );
        }

        $schema = Schema::get();
        $old    = Store::load();
        $result = Values::apply( $schema, $old, $request->get_params(), 'current_user_can' );

        if ( $result['forbidden'] ) {
            return new WP_Error(
                'wcct_rest_forbidden_fields',
                __( 'Only administrators can change custom scripts.', 'woocommerce-conversion-tracking' ),
                array(
                    'status' => 403,
                    'fields' => $result['forbidden'],
                )
            );
        }

        if ( $result['errors'] ) {
            return new WP_Error(
                'wcct_rest_invalid_settings',
                __( 'Some settings are not valid.', 'woocommerce-conversion-tracking' ),
                array(
                    'status' => 400,
                    'errors' => $result['errors'],
                )
            );
        }

        Store::save( $result['doc'] );

        /**
         * Fires after the settings are saved through the REST API.
         *
         * @since 3.0.0
         *
         * @param array $new Saved settings.
         * @param array $old Settings before the save.
         */
        do_action( 'wcct_settings_saved', $result['doc'], $old );

        return rest_ensure_response( Values::get( $schema, $result['doc'] ) );
    }

    /**
     * The schema per platform, in the shape plugin-ui renders.
     *
     * @param WP_REST_Request $request Request.
     *
     * @return WP_REST_Response
     */
    public function get_settings_schema( $request ) {
        return rest_ensure_response( Schema::for_output( Schema::get(), 'current_user_can' ) );
    }

    /**
     * JSON schema of the settings resource.
     *
     * @return array
     */
    public function get_item_schema() {
        if ( $this->schema ) {
            return $this->add_additional_fields_schema( $this->schema );
        }

        $this->schema = array(
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'wcct_settings',
            'type'       => 'object',
            'properties' => array(
                'consent_mode' => array(
                    'description' => __( 'Respect cookie choices on every platform.', 'woocommerce-conversion-tracking' ),
                    'type'        => 'boolean',
                    'context'     => array( 'view', 'edit' ),
                ),
                'platforms'    => array(
                    'description' => __( 'Values per platform, keyed by platform ID; the fields are listed by settings/schema.', 'woocommerce-conversion-tracking' ),
                    'type'        => 'object',
                    'context'     => array( 'view', 'edit' ),
                ),
            ),
        );

        return $this->add_additional_fields_schema( $this->schema );
    }
}
