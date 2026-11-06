<?php

namespace StorePulse\ConversionTracking\Log;

use StorePulse\ConversionTracking\Events\PurchaseConfirmation;

/**
 * The admin-ajax fallback of the check-in, for hosts that block REST for guests; same checks as the wcct/v1/events route.
 */
class AjaxCheckIn {

    /**
     * AJAX action; neutral, like the route.
     */
    const ACTION = 'wcct_events';

    /**
     * Actions of the 2.x confirmations, answered as no-ops for one release.
     */
    const LEGACY_ACTIONS = array( 'wcct_purchase_sent', 'wcct_ga4_purchase_sent' );

    /**
     * Returns the active platform IDs.
     *
     * @var callable
     */
    private $platform_ids;

    /**
     * Receives ( order ID, key ), returns the order or false.
     *
     * @var callable
     */
    private $find_order;

    /**
     * Receives ( order, platform, fields ), returns a CheckIn result.
     *
     * @var callable
     */
    private $record;

    /**
     * Fallback over the platforms, order lookup and recorder given; the defaults are the real ones.
     *
     * @param callable|null $platform_ids Returns the active platform IDs.
     * @param callable|null $find_order   Order lookup by ID and key.
     * @param callable|null $record       Records the check-in.
     */
    public function __construct( $platform_ids = null, $find_order = null, $record = null ) {
        $this->platform_ids = is_callable( $platform_ids ) ? $platform_ids : array( CheckIn::class, 'platform_ids' );
        $this->find_order   = is_callable( $find_order ) ? $find_order : array( PurchaseConfirmation::class, 'order_by_key' );
        $this->record       = is_callable( $record ) ? $record : array( CheckIn::class, 'record' );
    }

    /**
     * Hook the AJAX actions, for guests too.
     *
     * @return void
     */
    public function init_hooks() {
        add_action( 'wp_ajax_' . self::ACTION, array( $this, 'handle' ) );
        add_action( 'wp_ajax_nopriv_' . self::ACTION, array( $this, 'handle' ) );

        foreach ( self::LEGACY_ACTIONS as $action ) {
            add_action( 'wp_ajax_' . $action, array( $this, 'legacy' ) );
            add_action( 'wp_ajax_nopriv_' . $action, array( $this, 'legacy' ) );
        }
    }

    /**
     * Record a check-in posted to admin-ajax.
     *
     * @return void
     */
    public function handle() {
        $params = array();

        foreach ( array( 'order_id', 'key', 'platform', 'loaded', 'consent', 'reason', 'event_id' ) as $name ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the order key is the credential; CheckIn::process() and normalise() validate every field.
            $params[ $name ] = isset( $_POST[ $name ] ) && is_string( $_POST[ $name ] ) ? wp_unslash( $_POST[ $name ] ) : '';
        }

        $result = CheckIn::process( $params, (array) call_user_func( $this->platform_ids ), $this->find_order, $this->record );

        if ( $result['code'] ) {
            wp_send_json_error( array( 'code' => $result['code'] ), $result['status'] );
        }

        wp_send_json_success( array( 'result' => $result['result'] ) );
    }

    /**
     * A 2.x thank-you page still open: answer success, write nothing.
     *
     * @return void
     */
    public function legacy() {
        _deprecated_function( current_action(), '3.0.0', 'wcct/v1/events' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- an action name, escaped by WordPress when shown.

        wp_send_json_success();
    }
}
