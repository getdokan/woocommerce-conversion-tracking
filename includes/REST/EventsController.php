<?php

namespace StorePulse\ConversionTracking\REST;

use StorePulse\ConversionTracking\Events\PurchaseConfirmation;
use StorePulse\ConversionTracking\Log\CheckIn;
use StorePulse\ConversionTracking\Onboarding\Verify;
use StorePulse\ConversionTracking\Platforms\Registry;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

/**
 * The public events route: the thank-you page checks in per platform with the order ID and key, and the test link sends its seen ping.
 */
class EventsController extends Controller {

    /**
     * Route base; neutral, no platform or tracking words, so blocklists don't match it.
     *
     * @var string
     */
    protected $rest_base = 'events';

    /**
     * Returns the active platforms; null reads them from the integration manager.
     *
     * @var callable|null
     */
    private $platform_source;

    /**
     * Route over the platforms a callable returns.
     *
     * @param callable|null $platform_source Returns the active platforms.
     */
    public function __construct( $platform_source = null ) {
        $this->platform_source = is_callable( $platform_source ) ? $platform_source : null;
    }

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
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'create_item' ),
                    // Public: shoppers check in; the order key (or the test link token) is the credential.
                    'permission_callback' => '__return_true',
                    'args'                => array(
                        'type'      => array(
                            'description' => __( 'purchase: a thank-you page check-in; seen: the setup wizard test link.', 'woocommerce-conversion-tracking' ),
                            'type'        => 'string',
                            'enum'        => array( 'purchase', 'seen' ),
                            'default'     => 'purchase',
                        ),
                        'order_id'  => array(
                            'description' => __( 'Order ID.', 'woocommerce-conversion-tracking' ),
                            'type'        => 'integer',
                            'minimum'     => 1,
                        ),
                        'key'       => array(
                            'description' => __( 'Order key.', 'woocommerce-conversion-tracking' ),
                            'type'        => 'string',
                            'maxLength'   => 64,
                        ),
                        'platform'  => array(
                            'description' => __( 'Platform ID.', 'woocommerce-conversion-tracking' ),
                            'type'        => 'string',
                            'pattern'     => '^[A-Za-z0-9_\-]{1,32}$',
                        ),
                        'loaded'    => array(
                            'description' => __( 'Whether the platform library took the Purchase.', 'woocommerce-conversion-tracking' ),
                            'type'        => 'boolean',
                            'default'     => false,
                        ),
                        'consent'   => array(
                            'description' => __( 'Consent state on the page.', 'woocommerce-conversion-tracking' ),
                            'type'        => 'string',
                            'enum'        => CheckIn::CONSENT,
                            'default'     => 'off',
                        ),
                        'reason'    => array(
                            'description' => __( 'Why the Purchase wasn\'t handed over yet.', 'woocommerce-conversion-tracking' ),
                            'type'        => 'string',
                            'enum'        => array_merge( array( '' ), CheckIn::REASONS ),
                            'default'     => '',
                        ),
                        'event_id'  => array(
                            'description' => __( 'Event ID of the Purchase.', 'woocommerce-conversion-tracking' ),
                            'type'        => 'string',
                            'pattern'     => '^[A-Za-z0-9_\-]{0,64}$',
                            'default'     => '',
                        ),
                        'load'      => array(
                            'description' => __( 'Token of the page load that checks in.', 'woocommerce-conversion-tracking' ),
                            'type'        => 'string',
                            'pattern'     => '^[A-Za-z0-9]{0,32}$',
                            'default'     => '',
                        ),
                        'token'     => array(
                            'description' => __( 'Test link token.', 'woocommerce-conversion-tracking' ),
                            'type'        => 'string',
                            'maxLength'   => 64,
                        ),
                        'platforms' => array(
                            'description' => __( 'Platform ID => whether its library loaded, for the test link.', 'woocommerce-conversion-tracking' ),
                            'type'        => 'object',
                            'default'     => array(),
                        ),
                    ),
                ),
            )
        );
    }

    /**
     * Record a check-in or a seen ping.
     *
     * @param WP_REST_Request $request Request.
     *
     * @return \WP_REST_Response|WP_Error
     */
    public function create_item( $request ) {
        if ( 'seen' === $request->get_param( 'type' ) ) {
            return $this->seen( $request );
        }

        $params = array();

        foreach ( array( 'order_id', 'key', 'platform', 'loaded', 'consent', 'reason', 'event_id', 'load' ) as $name ) {
            $params[ $name ] = $request->get_param( $name );
        }

        $result = CheckIn::process( $params, $this->platform_ids(), array( PurchaseConfirmation::class, 'order_by_key' ), array( CheckIn::class, 'record' ) );

        if ( $result['code'] ) {
            $messages = array(
                'wcct_rest_unknown_platform' => __( 'Unknown platform.', 'woocommerce-conversion-tracking' ),
                'wcct_rest_invalid_order'    => __( 'Unknown order or key.', 'woocommerce-conversion-tracking' ),
            );

            return new WP_Error( $result['code'], $messages[ $result['code'] ], array( 'status' => $result['status'] ) );
        }

        return rest_ensure_response( array( 'result' => $result['result'] ) );
    }

    /**
     * Record the test link's seen ping.
     *
     * @param WP_REST_Request $request Request.
     *
     * @return \WP_REST_Response|WP_Error
     */
    private function seen( $request ) {
        $platforms = $request->get_param( 'platforms' );
        $result    = Verify::record_seen( (string) $request->get_param( 'token' ), is_array( $platforms ) ? $platforms : array(), $this->platform_ids() );

        if ( Verify::THROTTLED === $result ) {
            return new WP_Error( 'wcct_rest_throttled', __( 'Already received.', 'woocommerce-conversion-tracking' ), array( 'status' => 429 ) );
        }

        if ( Verify::OK !== $result ) {
            return new WP_Error( 'wcct_rest_invalid_token', __( 'Unknown or expired test link.', 'woocommerce-conversion-tracking' ), array( 'status' => 403 ) );
        }

        return rest_ensure_response( array( 'result' => $result ) );
    }

    /**
     * IDs of the active browser platforms.
     *
     * @return string[]
     */
    private function platform_ids() {
        if ( ! $this->platform_source ) {
            return CheckIn::platform_ids();
        }

        return array_map( 'strval', array_keys( Registry::browser( Registry::active( Registry::normalise( call_user_func( $this->platform_source ) ) ) ) ) );
    }
}
