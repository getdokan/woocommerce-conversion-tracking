<?php

namespace StorePulse\ConversionTracking\Onboarding;

/**
 * Setup wizard progress: status, furthest step, picked platforms and the Europe answer; never settings.
 */
class State {

    /**
     * Option holding the wizard progress.
     */
    const OPTION = 'wcct_onboarding';

    /**
     * Wizard steps: Welcome, Platforms, Connect, Europe, Verify, Done.
     */
    const STEPS = 6;

    /**
     * new: never opened; in_progress: a draft was saved; skipped: left with "Skip for now"; done: finished.
     */
    const STATUSES = array( 'new', 'in_progress', 'skipped', 'done' );

    /**
     * Europe answer; empty until answered.
     */
    const EU_ANSWERS = array( '', 'yes', 'no' );

    /**
     * save: store a draft; skip: store a draft and mark it skipped; finish: mark it done.
     */
    const ACTIONS = array( 'save', 'skip', 'finish' );

    /**
     * State before the wizard is opened.
     *
     * @return array
     */
    public static function defaults(): array {
        return array(
            'status'    => 'new',
            'step'      => 0,
            'platforms' => array(),
            'eu'        => '',
        );
    }

    /**
     * The stored state, cleaned.
     *
     * @param string[] $platform_ids Platform IDs the settings schema lists.
     *
     * @return array
     */
    public static function load( array $platform_ids ): array {
        return self::normalize( get_option( self::OPTION, array() ), $platform_ids );
    }

    /**
     * Save the state; not autoloaded, only the admin reads it.
     *
     * @param array $state State.
     *
     * @return void
     */
    public static function save( array $state ) {
        update_option( self::OPTION, $state, false );
    }

    /**
     * Clean a stored state; anything malformed falls back to the default.
     *
     * @param mixed    $raw          Stored value.
     * @param string[] $platform_ids Platform IDs the settings schema lists.
     *
     * @return array
     */
    public static function normalize( $raw, array $platform_ids ): array {
        $raw   = is_array( $raw ) ? $raw : array();
        $state = self::defaults();

        if ( isset( $raw['status'] ) && in_array( $raw['status'], self::STATUSES, true ) ) {
            $state['status'] = $raw['status'];
        }

        if ( isset( $raw['step'] ) && is_numeric( $raw['step'] ) ) {
            $state['step'] = max( 0, min( self::STEPS, (int) $raw['step'] ) );
        }

        if ( isset( $raw['platforms'] ) && is_array( $raw['platforms'] ) ) {
            // A platform that is gone (say, Pro's after Pro is removed) drops out.
            $state['platforms'] = array_values( array_intersect( array_unique( array_filter( $raw['platforms'], 'is_string' ) ), $platform_ids ) );
        }

        if ( isset( $raw['eu'] ) && in_array( $raw['eu'], self::EU_ANSWERS, true ) ) {
            $state['eu'] = $raw['eu'];
        }

        return $state;
    }

    /**
     * Apply a wizard request to the state.
     *
     * @param array    $state        Current state.
     * @param array    $params       Request: action, step, platforms, eu.
     * @param string[] $platform_ids Platform IDs the settings schema lists.
     *
     * @return array { state: new state, errors: param => message }
     */
    public static function apply( array $state, array $params, array $platform_ids ): array {
        $errors = array();
        $action = isset( $params['action'] ) ? $params['action'] : 'save';

        if ( ! in_array( $action, self::ACTIONS, true ) ) {
            $errors['action'] = __( 'Unknown wizard action.', 'woocommerce-conversion-tracking' );
        }

        if ( isset( $params['step'] ) && ( ! is_int( $params['step'] ) || $params['step'] < 0 || $params['step'] > self::STEPS ) ) {
            $errors['step'] = __( 'Unknown wizard step.', 'woocommerce-conversion-tracking' );
        }

        if ( isset( $params['platforms'] ) && ! self::known_ids( $params['platforms'], $platform_ids ) ) {
            $errors['platforms'] = __( 'Unknown platform.', 'woocommerce-conversion-tracking' );
        }

        if ( isset( $params['eu'] ) && ! in_array( $params['eu'], self::EU_ANSWERS, true ) ) {
            $errors['eu'] = __( 'The Europe answer must be yes or no.', 'woocommerce-conversion-tracking' );
        }

        // A finished wizard stays finished; a re-run works on a draft in the browser.
        if ( $errors || 'done' === $state['status'] ) {
            return array(
                'state'  => $state,
                'errors' => $errors,
            );
        }

        if ( isset( $params['step'] ) ) {
            // Resume at the furthest step reached.
            $state['step'] = max( $state['step'], $params['step'] );
        }

        if ( isset( $params['platforms'] ) ) {
            $state['platforms'] = array_values( array_unique( $params['platforms'] ) );
        }

        if ( isset( $params['eu'] ) ) {
            $state['eu'] = $params['eu'];
        }

        if ( 'finish' === $action ) {
            $state['status'] = 'done';
            $state['step']   = self::STEPS;
        } else {
            $state['status'] = 'skip' === $action ? 'skipped' : 'in_progress';
        }

        return array(
            'state'  => $state,
            'errors' => array(),
        );
    }

    /**
     * Whether the value is a list of known platform IDs.
     *
     * @param mixed    $ids          Posted IDs.
     * @param string[] $platform_ids Known IDs.
     *
     * @return bool
     */
    private static function known_ids( $ids, array $platform_ids ): bool {
        if ( ! is_array( $ids ) ) {
            return false;
        }

        foreach ( $ids as $id ) {
            if ( ! is_string( $id ) || ! in_array( $id, $platform_ids, true ) ) {
                return false;
            }
        }

        return true;
    }
}
