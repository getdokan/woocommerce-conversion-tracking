<?php
/**
 * WordPress functions the settings classes call, for unit tests. Tests set $GLOBALS['wcct_test_options'] and $GLOBALS['wcct_test_filters'].
 */

if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = 'default' ) {
        return $text;
    }
}

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

if ( ! function_exists( 'update_option' ) ) {
    function update_option( $name, $value, $autoload = null ) {
        $GLOBALS['wcct_test_options'][ $name ] = $value;

        return true;
    }
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $value ) {
        return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $value ) ) );
    }
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
    function sanitize_textarea_field( $value ) {
        return trim( strip_tags( (string) $value ) );
    }
}
