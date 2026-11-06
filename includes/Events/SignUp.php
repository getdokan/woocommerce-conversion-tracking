<?php

namespace StorePulse\ConversionTracking\Events;

/**
 * One sign-up detector for every platform: user_register flags the user, their next page view sends the event.
 */
class SignUp {

    /**
     * User meta flag, the Unix time the account was created.
     */
    const META_KEY = '_wcct_sign_up_pending';

    /**
     * Seconds a pending sign-up stays sendable.
     */
    const TTL = 604800;

    /**
     * Hook into WordPress.
     *
     * @return void
     */
    public static function init_hooks() {
        add_action( 'user_register', array( __CLASS__, 'flag' ), 10, 1 );
    }

    /**
     * Flag a new account from the storefront, checkout, wp-login, AJAX or REST; not accounts made in wp-admin or WP-CLI.
     *
     * @param int $user_id New user's ID.
     *
     * @return void
     */
    public static function flag( $user_id ) {
        $user_id = is_numeric( $user_id ) ? (int) $user_id : 0;
        $track   = ! ( defined( 'WP_CLI' ) && WP_CLI ) && ! ( is_admin() && ! wp_doing_ajax() );

        /**
         * Whether a new account sends a sign-up event.
         *
         * @since 3.0.0
         *
         * @param bool $track   Whether to track it; false for accounts made in wp-admin or WP-CLI.
         * @param int  $user_id New user's ID.
         */
        if ( $user_id <= 0 || ! apply_filters( 'wcct_track_sign_up', $track, $user_id ) ) {
            return;
        }

        update_user_meta( $user_id, self::META_KEY, time() );
    }

    /**
     * Whether the user has a sign-up waiting to be sent.
     *
     * @param int $user_id User ID.
     *
     * @return bool
     */
    public static function is_pending( $user_id ) {
        $user_id = is_numeric( $user_id ) ? (int) $user_id : 0;

        return $user_id > 0 && '' !== (string) get_user_meta( $user_id, self::META_KEY, true );
    }

    /**
     * Clear the user's flag; true only for the call that cleared a flag younger than TTL, so the event is sent once.
     *
     * @param int $user_id User ID.
     *
     * @return bool
     */
    public static function take( $user_id ) {
        if ( ! self::is_pending( $user_id ) ) {
            return false;
        }

        $flagged = get_user_meta( (int) $user_id, self::META_KEY, true );
        $fresh   = is_numeric( $flagged ) && (int) $flagged >= time() - self::TTL;

        return (bool) delete_user_meta( (int) $user_id, self::META_KEY ) && $fresh;
    }
}
