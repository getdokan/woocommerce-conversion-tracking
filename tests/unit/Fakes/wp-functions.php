<?php
/**
 * Minimal WordPress and WooCommerce functions for unit tests. Tests set $GLOBALS['wcct_test_options'], $GLOBALS['wcct_test_filters'] and $GLOBALS['wcct_test_hooks'] (hook => callables); do_action() logs to $GLOBALS['wcct_test_actions'].
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
        $options = isset( $GLOBALS['wcct_test_options'] ) ? $GLOBALS['wcct_test_options'] : array();

        // Options a test saved with autoload off.
        return array_diff_key( $options, array_flip( isset( $GLOBALS['wcct_test_not_autoloaded'] ) ? $GLOBALS['wcct_test_not_autoloaded'] : array() ) );
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

if ( ! function_exists( 'add_option' ) ) {
    function add_option( $name, $value = '' ) {
        if ( isset( $GLOBALS['wcct_test_options'] ) && array_key_exists( $name, $GLOBALS['wcct_test_options'] ) ) {
            return false;
        }

        $GLOBALS['wcct_test_options'][ $name ] = $value;

        return true;
    }
}

if ( ! function_exists( 'admin_url' ) ) {
    function admin_url( $path = '' ) {
        return 'https://shop.test/wp-admin/' . ltrim( $path, '/' );
    }
}

if ( ! function_exists( 'rest_url' ) ) {
    function rest_url( $path = '' ) {
        return 'https://shop.test/wp-json/' . ltrim( $path, '/' );
    }
}

if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $data, $flags = 0 ) {
        return json_encode( $data, $flags );
    }
}

if ( ! function_exists( 'wc_get_price_decimals' ) ) {
    function wc_get_price_decimals() {
        return isset( $GLOBALS['wcct_test_decimals'] ) ? $GLOBALS['wcct_test_decimals'] : 2;
    }
}

if ( ! function_exists( 'absint' ) ) {
    function absint( $maybeint ) {
        return abs( (int) $maybeint );
    }
}

if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = 'default' ) {
        return $text;
    }
}

if ( ! function_exists( 'esc_html__' ) ) {
    function esc_html__( $text, $domain = 'default' ) {
        return htmlspecialchars( $text, ENT_QUOTES );
    }
}

if ( ! function_exists( 'update_option' ) ) {
    function update_option( $name, $value ) {
        $changed = ! isset( $GLOBALS['wcct_test_options'] ) || ! array_key_exists( $name, $GLOBALS['wcct_test_options'] ) || $GLOBALS['wcct_test_options'][ $name ] !== $value;

        $GLOBALS['wcct_test_options'][ $name ] = $value;

        return $changed;
    }
}

if ( ! function_exists( 'has_filter' ) ) {
    function has_filter( $hook, $callback = false ) {
        return isset( $GLOBALS['wcct_test_filters'][ $hook ] );
    }
}

if ( ! function_exists( 'esc_attr' ) ) {
    function esc_attr( $text ) {
        return htmlspecialchars( (string) $text, ENT_QUOTES );
    }
}

if ( ! function_exists( 'has_action' ) ) {
    function has_action( $hook, $callback = false ) {
        return ! empty( $GLOBALS['wcct_test_hooks'][ $hook ] );
    }
}

if ( ! function_exists( 'do_action' ) ) {
    function do_action( $hook, ...$args ) {
        $GLOBALS['wcct_test_actions'][] = array( $hook, $args );

        foreach ( isset( $GLOBALS['wcct_test_hooks'][ $hook ] ) ? (array) $GLOBALS['wcct_test_hooks'][ $hook ] : array() as $callback ) {
            call_user_func_array( $callback, $args );
        }
    }
}
