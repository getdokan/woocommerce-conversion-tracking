<?php

namespace StorePulse\ConversionTracking\REST;

use StorePulse\ConversionTracking\Consent\ConsentMode;
use StorePulse\ConversionTracking\Log\LastPurchase;
use StorePulse\ConversionTracking\Migration\Flags;
use StorePulse\ConversionTracking\Migration\WhatsNew;
use StorePulse\ConversionTracking\Platforms\Pause;
use StorePulse\ConversionTracking\Settings\Schema;
use StorePulse\ConversionTracking\Settings\Store;
use StorePulse\ConversionTracking\Settings\Values;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * The settings, settings/pause and settings/schema routes.
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
            '/' . $this->rest_base . '/pause',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'pause' ),
                    'permission_callback' => array( $this, 'permissions_check' ),
                    'args'                => array(
                        'platform' => array(
                            'description' => __( 'Platform ID.', 'woocommerce-conversion-tracking' ),
                            'type'        => 'string',
                            'required'    => true,
                        ),
                        'paused'   => array(
                            'description' => __( 'Pause (true) or resume (false) tracking on the platform.', 'woocommerce-conversion-tracking' ),
                            'type'        => 'boolean',
                            'required'    => true,
                        ),
                    ),
                ),
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
        $schema = Schema::get();

        return rest_ensure_response( self::values( $schema, Store::load() ) );
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

        return rest_ensure_response( self::values( $schema, $result['doc'] ) );
    }

    /**
     * Pause or resume tracking on one platform; takes effect at once, without a settings save.
     *
     * @param WP_REST_Request $request Request.
     *
     * @return WP_REST_Response|WP_Error Every platform's values, as the settings route returns them.
     */
    public function pause( $request ) {
        $schema   = Schema::get();
        $platform = $request->get_param( 'platform' );

        if ( ! is_string( $platform ) || ! isset( $schema['platforms'][ $platform ] ) ) {
            return new WP_Error(
                'wcct_rest_unknown_platform',
                __( 'Unknown platform.', 'woocommerce-conversion-tracking' ),
                array( 'status' => 400 )
            );
        }

        Pause::set( $platform, (bool) $request->get_param( 'paused' ) );

        return rest_ensure_response( self::values( $schema, Store::load() ) );
    }

    /**
     * Every platform's values, plus (read-only) its last checked-in purchase for Last seen and Google's open Ads ID split.
     *
     * @param array $schema Normalized schema.
     * @param array $doc    Stored settings.
     *
     * @return array
     */
    public static function values( array $schema, array $doc ): array {
        $values = Values::get( $schema, $doc );

        foreach ( $schema['platforms'] as $id => $platform ) {
            $values['platforms'][ $id ]['last_purchase'] = self::last_purchase( array_merge( array( (string) $id ), (array) $platform['integrations'] ) );
        }

        // "We split your Google Ads ID" on the Google page, until the merchant changes the split fields.
        if ( isset( $values['platforms']['google'] ) ) {
            $google = isset( $doc['platforms']['google'] ) && is_array( $doc['platforms']['google'] ) ? $doc['platforms']['google'] : array();

            // "Looks right" on What's new closes it here too.
            $values['platforms']['google']['ads_id_split'] = in_array( Flags::GOOGLE_ADS_ID_SPLIT, WhatsNew::confirmed(), true ) ? null : Flags::google_split( Flags::all(), $google );
        }

        return $values;
    }

    /**
     * The latest purchase any of these IDs checked in: { at (Unix time), order_number }, or null.
     *
     * @param string[] $ids Platform and integration IDs.
     *
     * @return array|null
     */
    private static function last_purchase( array $ids ) {
        $last = null;

        foreach ( array_unique( $ids ) as $id ) {
            $purchase = LastPurchase::get( $id );

            if ( $purchase && ( null === $last || $purchase['last_purchase_at'] > $last['at'] ) ) {
                $last = array(
                    'at'           => $purchase['last_purchase_at'],
                    'order_number' => $purchase['order_number'],
                );
            }
        }

        return $last;
    }

    /**
     * The schema per platform, in the shape plugin-ui renders.
     *
     * @param WP_REST_Request $request Request.
     *
     * @return WP_REST_Response
     */
    public function get_settings_schema( $request ) {
        $output = Schema::for_output( Schema::get(), 'current_user_can' );
        $notice = ConsentMode::status()['message'];

        // "Consent signals are handled by <tool>" beside the Respect cookie choices switch.
        foreach ( $output['global'] as $index => $element ) {
            if ( isset( $element['id'] ) && 'consent_mode' === $element['id'] ) {
                $output['global'][ $index ]['notice'] = $notice;
            }
        }

        return rest_ensure_response( $output );
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
