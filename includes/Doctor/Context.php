<?php

namespace StorePulse\ConversionTracking\Doctor;

use StorePulse\ConversionTracking\Platforms\Pause;
use StorePulse\ConversionTracking\Settings\Store;

/**
 * What every check of one run shares: the saved settings, the time budget and a cookie-less loopback request.
 */
class Context {

    /**
     * Seconds a single loopback request may take by default.
     */
    const REQUEST_TIMEOUT = 5;

    /**
     * Seconds every remote check of one run may take together, by default.
     */
    const BUDGET = 20;

    /**
     * When the run started (microtime).
     *
     * @var float
     */
    private $started;

    /**
     * Budget in seconds.
     *
     * @var float
     */
    private $budget;

    /**
     * HTTP transport: callable( string $url, array $args ) returning what wp_remote_request() returns.
     *
     * @var callable
     */
    private $http;

    /**
     * Settings, read once.
     *
     * @var array|null
     */
    private $settings = null;

    /**
     * Start a run.
     *
     * @param float|null    $budget Seconds for every remote check together; null for the filtered default.
     * @param callable|null $http   HTTP transport; wp_remote_request() by default.
     */
    public function __construct( $budget = null, $http = null ) {
        if ( null === $budget ) {
            /**
             * Seconds every remote Doctor check of one run may take together.
             *
             * @since 3.0.0
             *
             * @param int $budget Seconds.
             */
            $budget = apply_filters( 'wcct_doctor_time_budget', self::BUDGET );
        }

        $this->started = microtime( true );
        $this->budget  = max( 0.0, (float) $budget );
        $this->http    = is_callable( $http ) ? $http : 'wp_remote_request';
    }

    /**
     * Seconds left in the budget.
     *
     * @return float
     */
    public function remaining() {
        return max( 0.0, $this->budget - ( microtime( true ) - $this->started ) );
    }

    /**
     * Timeout for one request: what the check wants, capped by the budget left.
     *
     * @param float $wanted Seconds.
     *
     * @return float 0 when the budget is spent.
     */
    public function timeout( $wanted = self::REQUEST_TIMEOUT ) {
        return min( max( 0.0, (float) $wanted ), $this->remaining() );
    }

    /**
     * The saved 3.0 settings (the 2.x settings read as 3.0 before the migration).
     *
     * @return array
     */
    public function settings() {
        if ( null === $this->settings ) {
            $this->settings = Store::load();
        }

        return $this->settings;
    }

    /**
     * One platform's saved values.
     *
     * @param string $platform Settings platform ID, for example facebook or google.
     *
     * @return array
     */
    public function platform( $platform ) {
        $settings = $this->settings();

        return isset( $settings['platforms'][ $platform ] ) && is_array( $settings['platforms'][ $platform ] ) ? $settings['platforms'][ $platform ] : array();
    }

    /**
     * Whether an integration or platform is paused (Platforms\Pause).
     *
     * @param string $integration Integration ID, for example facebook or ga4.
     *
     * @return bool
     */
    public function is_paused( $integration ) {
        return Pause::is_paused( (string) $integration, $this->settings() );
    }

    /**
     * Request a route of this site the way a logged-out shopper would: no cookies, no nonce.
     *
     * @param string $method HTTP method.
     * @param string $path   REST route, for example /wcct/v1/events.
     * @param array  $args   { body (array, sent as JSON), headers, timeout (seconds, capped by the budget) }.
     *
     * @return array { code: HTTP status (0 on error), body: string, error: error code, '' on success }.
     */
    public function loopback( $method, $path, array $args = array() ) {
        $timeout = $this->timeout( isset( $args['timeout'] ) ? $args['timeout'] : self::REQUEST_TIMEOUT );

        if ( $timeout <= 0 ) {
            return array(
                'code'  => 0,
                'body'  => '',
                'error' => 'wcct_doctor_timeout',
            );
        }

        $headers = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array();
        $request = array(
            'method'      => strtoupper( (string) $method ),
            'timeout'     => $timeout,
            'redirection' => 0,
            'cookies'     => array(),
            'headers'     => $headers,
            // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- core's filter for requests to this site.
            'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
        );

        if ( isset( $args['body'] ) ) {
            $request['headers']['Content-Type'] = 'application/json';
            $request['body']                    = wp_json_encode( $args['body'] );
        }

        $response = call_user_func( $this->http, rest_url( ltrim( (string) $path, '/' ) ), $request );

        if ( is_wp_error( $response ) ) {
            return array(
                'code'  => 0,
                'body'  => '',
                'error' => (string) $response->get_error_code(),
            );
        }

        return array(
            'code'  => (int) wp_remote_retrieve_response_code( $response ),
            'body'  => (string) wp_remote_retrieve_body( $response ),
            'error' => '',
        );
    }
}
