<?php
/**
 * Minimal WordPress and WooCommerce functions for unit tests. Tests set $GLOBALS['wcct_test_options'] and $GLOBALS['wcct_test_filters'].
 */

if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $hook, $value, ...$args ) {
        return isset( $GLOBALS['wcct_test_filters'][ $hook ] ) ? call_user_func( $GLOBALS['wcct_test_filters'][ $hook ], $value, ...$args ) : $value;
    }
}

if ( ! function_exists( 'get_option' ) ) {
    function get_option( $name, $default = false ) {
        return isset( $GLOBALS['wcct_test_options'] ) && array_key_exists( $name, $GLOBALS['wcct_test_options'] ) ? $GLOBALS['wcct_test_options'][ $name ] : $default;
    }
}

if ( ! function_exists( 'wc_string_to_bool' ) ) {
    function wc_string_to_bool( $string ) {
        return is_bool( $string ) ? $string : ( 'yes' === strtolower( (string) $string ) || 1 === $string || 'true' === strtolower( (string) $string ) || '1' === $string );
    }
}

if ( ! function_exists( 'wp_load_alloptions' ) ) {
    function wp_load_alloptions() {
        return isset( $GLOBALS['wcct_test_options'] ) ? $GLOBALS['wcct_test_options'] : array();
    }
}

if ( ! function_exists( 'maybe_unserialize' ) ) {
    function maybe_unserialize( $data ) {
        return is_string( $data ) && is_serialized_for_test( $data ) ? unserialize( $data ) : $data; // phpcs:ignore
    }

    function is_serialized_for_test( $data ) {
        return 'a:' === substr( $data, 0, 2 ) || 'b:' === substr( $data, 0, 2 );
    }
}

if ( ! function_exists( 'get_user_meta' ) ) {
    function get_user_meta( $user_id, $key = '', $single = false ) {
        return isset( $GLOBALS['wcct_test_user_meta'][ $user_id ][ $key ] ) ? $GLOBALS['wcct_test_user_meta'][ $user_id ][ $key ] : ( $single ? '' : array() );
    }
}

if ( ! function_exists( 'update_user_meta' ) ) {
    function update_user_meta( $user_id, $key, $value ) {
        $GLOBALS['wcct_test_user_meta'][ $user_id ][ $key ] = $value;

        return true;
    }
}

if ( ! function_exists( 'delete_user_meta' ) ) {
    function delete_user_meta( $user_id, $key ) {
        if ( ! isset( $GLOBALS['wcct_test_user_meta'][ $user_id ][ $key ] ) ) {
            return false;
        }

        unset( $GLOBALS['wcct_test_user_meta'][ $user_id ][ $key ] );

        return true;
    }
}

if ( ! function_exists( 'is_admin' ) ) {
    function is_admin() {
        return ! empty( $GLOBALS['wcct_test_is_admin'] );
    }
}

if ( ! function_exists( 'wp_doing_ajax' ) ) {
    function wp_doing_ajax() {
        return ! empty( $GLOBALS['wcct_test_doing_ajax'] );
    }
}
