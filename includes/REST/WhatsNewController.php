<?php

namespace StorePulse\ConversionTracking\REST;

use StorePulse\ConversionTracking\Migration\WhatsNew;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * The What's new routes: the page's data, "seen" for the one-time redirect, dismiss for the dashboard banner and "Looks right" for an item.
 */
class WhatsNewController extends Controller {

    /**
     * Route base.
     *
     * @var string
     */
    protected $rest_base = 'whats-new';

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

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/confirm',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'confirm' ),
                    'permission_callback' => array( $this, 'permissions_check' ),
                    'args'                => array(
                        'id' => array(
                            'description' => __( 'Flag ID of the item that looks right.', 'woocommerce-conversion-tracking' ),
                            'type'        => 'string',
                            'pattern'     => '^[a-z0-9_]+$',
                            'required'    => true,
                        ),
                    ),
                ),
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
                ),
            )
        );
    }

    /**
     * The page's data; 404 on a store that didn't upgrade from 2.x.
     *
     * @param WP_REST_Request $request Request.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function get_item( $request ) {
        if ( ! WhatsNew::is_available() ) {
            return self::not_available();
        }

        return rest_ensure_response( WhatsNew::data() );
    }

    /**
     * The page was opened: drop the queued redirect.
     *
     * @param WP_REST_Request $request Request.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function seen( $request ) {
        if ( ! WhatsNew::is_available() ) {
            return self::not_available();
        }

        WhatsNew::seen();

        return rest_ensure_response( WhatsNew::data() );
    }

    /**
     * "Done, show me the dashboard": hide the dashboard banner.
     *
     * @param WP_REST_Request $request Request.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function dismiss( $request ) {
        if ( ! WhatsNew::is_available() ) {
            return self::not_available();
        }

        WhatsNew::dismiss( time() );

        return rest_ensure_response( WhatsNew::data() );
    }

    /**
     * "Looks right": close an open item that offers it.
     *
     * @param WP_REST_Request $request Request.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function confirm( $request ) {
        if ( ! WhatsNew::is_available() ) {
            return self::not_available();
        }

        $id   = (string) $request->get_param( 'id' );
        $open = wp_list_filter( WhatsNew::data()['attention'], array( 'confirmable' => true ) );

        if ( ! in_array( $id, wp_list_pluck( $open, 'id' ), true ) ) {
            return new WP_Error(
                'wcct_rest_whats_new_item',
                __( 'That item is not open, or it has to be fixed rather than confirmed.', 'woocommerce-conversion-tracking' ),
                array( 'status' => 400 )
            );
        }

        WhatsNew::confirm( $id );

        return rest_ensure_response( WhatsNew::data() );
    }

    /**
     * The error for a store with nothing from before 3.0.
     *
     * @return WP_Error
     */
    private static function not_available() {
        return new WP_Error(
            'wcct_rest_no_whats_new',
            __( 'This store started on 3.0, so there is nothing new to show.', 'woocommerce-conversion-tracking' ),
            array( 'status' => 404 )
        );
    }
}
