<?php
/**
 * Plugin Name: CT e2e helpers
 * Description: Test site only. Lets a test pick the checkout page and stops calls to wordpress.org.
 */

// phpcs:disable

// The ct_e2e_checkout=classic cookie makes the shortcode page the checkout page, so the order lands on its thank-you page.
add_filter(
    'woocommerce_get_checkout_page_id',
    static function ( $page_id ) {
        if ( isset( $_COOKIE['ct_e2e_checkout'] ) && 'classic' === $_COOKIE['ct_e2e_checkout'] ) {
            $page = get_page_by_path( 'classic-checkout' );

            if ( $page ) {
                return $page->ID;
            }
        }

        return $page_id;
    }
);

// wp-env has no mail server; report mail as sent instead of failing on it.
add_filter( 'pre_wp_mail', '__return_true' );

// No update checks: they slow down a fresh site and nothing here needs wordpress.org.
add_filter( 'automatic_updater_disabled', '__return_true' );
remove_action( 'admin_init', '_maybe_update_core' );
remove_action( 'admin_init', '_maybe_update_plugins' );
remove_action( 'admin_init', '_maybe_update_themes' );

add_filter(
    'pre_http_request',
    static function ( $pre, $args, $url ) {
        $host = (string) wp_parse_url( $url, PHP_URL_HOST );

        if ( '' !== $host && false !== strpos( $host, 'wordpress.org' ) ) {
            return new WP_Error( 'ct_e2e_http_blocked', 'wordpress.org HTTP disabled on the e2e site' );
        }

        return $pre;
    },
    10,
    3
);

// The ct_e2e_notice cookie prints a core-style admin notice, so the admin specs can check where notices land.
add_action(
    'admin_notices',
    static function () {
        if ( isset( $_COOKIE['ct_e2e_notice'] ) ) {
            echo '<div class="notice notice-info" id="ct-e2e-notice"><p>CT e2e admin notice</p></div>';
        }
    }
);

// The ct_e2e_custom_scripts cookie turns on the Custom script's three placements (3.0 settings); each script sends a hit to custom.ct-e2e.test.
add_filter(
    'pre_option_wcct_settings_v3',
    static function ( $pre ) {
        if ( ! isset( $_COOKIE['ct_e2e_custom_scripts'] ) ) {
            return $pre;
        }

        $hit = static function ( $placement, $query ) {
            return "<script>(new Image()).src = 'https://custom.ct-e2e.test/{$placement}?{$query}&page=' + encodeURIComponent(location.pathname);</script>";
        };

        return array(
            'platforms' => array(
                'custom' => array(
                    'thankyou_enabled' => true,
                    'thankyou_script'  => $hit( 'thankyou', 'order={order_number}&id={order_id}&total={order_total}&currency={currency}&payment={payment_method}&coupons={used_coupons}&email={customer_email}' ),
                    'header_enabled'   => true,
                    'header_script'    => $hit( 'header', 'order={order_number}&email={customer_email}' ),
                    'signup_enabled'   => true,
                    'signup_script'    => $hit( 'signup', 'customer={customer_id}&email={customer_email}' ),
                ),
            ),
        );
    }
);

// Tracking Doctor: the ct_e2e_doctor cookie registers a sample check that finds a duplicate plugin named by the cookie
// (the name is its evidence) and one whose action asks for confirmation; ct_e2e_consent=on turns consent mode on.
add_filter(
    'wcct_doctor_checks',
    static function ( $checks ) {
        if ( ! isset( $_COOKIE['ct_e2e_doctor'] ) || ! class_exists( '\StorePulse\ConversionTracking\Doctor\AbstractCheck' ) ) {
            return $checks;
        }

        if ( ! class_exists( 'CT_E2E_Doctor_Check' ) ) {
            require __DIR__ . '/ct-e2e/doctor-checks.php';
        }

        $checks[] = CT_E2E_Doctor_Check::class;
        $checks[] = CT_E2E_Doctor_Confirm_Check::class;

        return $checks;
    }
);

add_filter(
    'wcct_consent_mode_enabled',
    static function ( $enabled ) {
        return isset( $_COOKIE['ct_e2e_consent'] ) ? 'on' === $_COOKIE['ct_e2e_consent'] : $enabled;
    }
);

// /wp-admin/?ct_e2e_doctor_reset=1 forgets every Doctor run, dismissal and badge.
add_action(
    'admin_init',
    static function () {
        if ( isset( $_GET['ct_e2e_doctor_reset'] ) && current_user_can( 'manage_options' ) ) {
            delete_option( 'wcct_doctor_results' );
            delete_option( 'wcct_doctor_dismissed' );
            delete_option( 'wcct_doctor_new' );
        }
    }
);
