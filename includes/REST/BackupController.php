<?php

namespace StorePulse\ConversionTracking\REST;

use StorePulse\ConversionTracking\Backup\Snapshot;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * The backup route: the pre-3.0 settings as a JSON download, read-only.
 */
class BackupController extends Controller {

    /**
     * Route base.
     *
     * @var string
     */
    protected $rest_base = 'backup';

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
    }

    /**
     * The pre-3.0 settings, secrets removed; 404 when the store has none.
     *
     * @param WP_REST_Request $request Request.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function get_item( $request ) {
        $backup = Snapshot::load( array( self::class, 'read_option' ) );

        if ( ! $backup ) {
            return new WP_Error(
                'wcct_rest_no_backup',
                __( 'There are no settings from before 3.0 on this store.', 'woocommerce-conversion-tracking' ),
                array( 'status' => 404 )
            );
        }

        $time     = time();
        $response = rest_ensure_response( Snapshot::document( $backup, defined( 'WCCT_VERSION' ) ? WCCT_VERSION : '', $time ) );

        // Opened as a link (with _wpnonce), the browser saves it as a file.
        $response->header( 'Content-Disposition', 'attachment; filename="' . Snapshot::filename( $time ) . '"' );

        return $response;
    }

    /**
     * An option's value, null when it doesn't exist.
     *
     * @param string $option Option name.
     *
     * @return mixed
     */
    public static function read_option( $option ) {
        $missing = new \stdClass();
        $value   = get_option( $option, $missing );

        return $missing === $value ? null : $value;
    }
}
