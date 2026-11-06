<?php
/**
 * WordPress functions the Doctor classes call, for unit tests. Requests made through rest_url() go to the Context's injected transport.
 */

if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        private $code;

        public function __construct( $code = '', $message = '' ) {
            $this->code = $code;
        }

        public function get_error_code() {
            return $this->code;
        }
    }
}

if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $thing ) {
        return $thing instanceof WP_Error;
    }
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
    function wp_strip_all_tags( $text ) {
        return trim( strip_tags( (string) $text ) );
    }
}

if ( ! function_exists( 'esc_url_raw' ) ) {
    function esc_url_raw( $url, $protocols = null ) {
        return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : '';
    }
}

if ( ! function_exists( 'wp_date' ) ) {
    function wp_date( $format, $timestamp = null ) {
        return gmdate( $format, null === $timestamp ? time() : $timestamp );
    }
}

if ( ! function_exists( 'human_time_diff' ) ) {
    function human_time_diff( $from, $to = 0 ) {
        return round( ( ( $to ? $to : time() ) - $from ) / 3600 ) . ' hours';
    }
}

if ( ! function_exists( 'rest_url' ) ) {
    function rest_url( $path = '' ) {
        return 'https://shop.test/wp-json/' . ltrim( $path, '/' );
    }
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
    function wp_remote_retrieve_response_code( $response ) {
        return isset( $response['response']['code'] ) ? $response['response']['code'] : '';
    }
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
    function wp_remote_retrieve_body( $response ) {
        return isset( $response['body'] ) ? $response['body'] : '';
    }
}
