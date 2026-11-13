<?php
/**
 * Plugin Name: CT e2e consent
 * Description: Test site only. The ct_e2e_consent=optin cookie turns on Respect cookie choices with a minimal WP Consent API (opt-in, read from wp_consent_<category> cookies).
 */

// phpcs:disable

if ( isset( $_COOKIE['ct_e2e_consent'] ) && 'optin' === $_COOKIE['ct_e2e_consent'] ) {
    add_filter( 'wcct_consent_mode_enabled', '__return_true' );

    if ( ! function_exists( 'wp_has_consent' ) ) {
        function wp_get_consent_type() {
            return 'optin';
        }

        function wp_has_consent( $category ) {
            return isset( $_COOKIE[ 'wp_consent_' . $category ] ) && 'allow' === $_COOKIE[ 'wp_consent_' . $category ];
        }
    }

    // The browser side of the API, as a banner would set it; wp_set_consent() is the shopper clicking Accept.
    add_action(
        'wp_head',
        static function () {
            ?>
            <script>
                window.wp_consent_type = 'optin';
                window.wp_has_consent = function (category) {
                    return document.cookie.indexOf('wp_consent_' + category + '=allow') > -1;
                };
                window.wp_set_consent = function (category, value) {
                    var detail = {};
                    detail[category] = value;
                    document.cookie = 'wp_consent_' + category + '=' + value + '; path=/';
                    document.dispatchEvent(new CustomEvent('wp_listen_for_consent_change', { detail: detail }));
                };
            </script>
            <?php
        },
        0
    );
}
