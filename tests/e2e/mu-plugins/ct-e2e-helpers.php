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

// Lite's real Doctor checks against cookie-only store states (doctor-states.php, doctor-checks.spec.ts).
if ( isset( $_COOKIE['ct_e2e_doctor_checks'] ) || isset( $_COOKIE['ct_e2e_doctor_state'] ) ) {
    require __DIR__ . '/ct-e2e/doctor-states.php';
}

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

// Test-only routes for the check-in spec: the order log rows and sent flags of an order, and the Verify step's test link.
add_action(
    'rest_api_init',
    static function () {
        register_rest_route(
            'ct-e2e/v1',
            '/order-log/(?P<id>\d+)',
            array(
                'methods'             => 'GET',
                'permission_callback' => '__return_true',
                'callback'            => static function ( $request ) {
                    $order = wc_get_order( (int) $request['id'] );
                    $flags = array();

                    foreach ( array( 'facebook', 'adwords', 'ga4', 'twitter', 'bing_ads', 'custom' ) as $platform ) {
                        $flags[ $platform ] = $order ? \StorePulse\ConversionTracking\Events\SentFlags::is_sent( $order, $platform ) : false;
                    }

                    return array(
                        'rows'          => \StorePulse\ConversionTracking\Log\OrderLog::for_orders( array( (int) $request['id'] ) ),
                        'flags'         => $flags,
                        'thankyou_seen' => \StorePulse\ConversionTracking\Log\CheckIn::thankyou_seen( $order ),
                    );
                },
            )
        );
        register_rest_route(
            'ct-e2e/v1',
            '/verify',
            array(
                array(
                    'methods'             => 'POST',
                    'permission_callback' => '__return_true',
                    'callback'            => static function () {
                        return \StorePulse\ConversionTracking\Onboarding\Verify::start();
                    },
                ),
                array(
                    'methods'             => 'GET',
                    'permission_callback' => '__return_true',
                    'callback'            => static function () {
                        return \StorePulse\ConversionTracking\Onboarding\Verify::state();
                    },
                ),
            )
        );
    }
);

// ct-e2e/v1/state: lets the onboarding spec read, set and restore the setup state, and switch plugins without a page view.
add_action(
    'rest_api_init',
    static function () {
        $options = array( 'wcct_onboarding', 'wcct_onboarding_redirect', 'wcct_install_type', 'wcct_settings', 'wcct_settings_v3', 'wcct_consent_mode', 'wcct_verify' );

        register_rest_route(
            'ct-e2e/v1',
            '/state',
            array(
                array(
                    'methods'             => 'GET',
                    'permission_callback' => static function () {
                        return current_user_can( 'manage_options' );
                    },
                    'callback'            => static function () use ( $options ) {
                        $state = array();

                        foreach ( $options as $name ) {
                            $state[ $name ] = get_option( $name, null );
                        }

                        return array(
                            'options' => $state,
                            'active'  => array_values( (array) get_option( 'active_plugins', array() ) ),
                        );
                    },
                ),
                array(
                    'methods'             => 'POST',
                    'permission_callback' => static function () {
                        return current_user_can( 'manage_options' );
                    },
                    'callback'            => static function ( WP_REST_Request $request ) use ( $options ) {
                        require_once ABSPATH . 'wp-admin/includes/plugin.php';

                        foreach ( (array) $request->get_param( 'options' ) as $name => $value ) {
                            if ( ! in_array( $name, $options, true ) ) {
                                continue;
                            }

                            if ( null === $value ) {
                                delete_option( $name );
                            } else {
                                update_option( $name, $value );
                            }
                        }

                        // Pro needs Lite, so Pro goes first on the way down and last on the way up.
                        $deactivate = (array) $request->get_param( 'deactivate' );
                        $activate   = (array) $request->get_param( 'activate' );

                        if ( $deactivate ) {
                            deactivate_plugins( $deactivate, true );
                        }

                        foreach ( $activate as $plugin ) {
                            activate_plugin( $plugin, '', false, true );
                        }

                        return array( 'active' => array_values( (array) get_option( 'active_plugins', array() ) ) );
                    },
                ),
            )
        );
    }
);

// ct-e2e/v1/options: every Conversion Tracking option, so the onboarding spec can check that closing a re-run of the wizard changes none.
add_action(
    'rest_api_init',
    static function () {
        register_rest_route(
            'ct-e2e/v1',
            '/options',
            array(
                'methods'             => 'GET',
                'permission_callback' => static function () {
                    return current_user_can( 'manage_options' );
                },
                'callback'            => static function () {
                    global $wpdb;

                    $rows    = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'wcct\\_%' OR option_name LIKE 'woocommerce\\_wc\\_conv\\_%' ORDER BY option_name" );
                    $options = array();

                    foreach ( $rows as $row ) {
                        $options[ $row->option_name ] = maybe_unserialize( $row->option_value );
                    }

                    return (object) $options;
                },
            )
        );
    }
);

// Test-only routes for the Activity log spec (#113, #114): move an order and its log rows back in time, past the 10-minute settle time, and create an order in admin.
add_action(
    'rest_api_init',
    static function () {
        register_rest_route(
            'ct-e2e/v1',
            '/order-log/(?P<id>\d+)/age',
            array(
                'methods'             => 'POST',
                'permission_callback' => '__return_true',
                'callback'            => static function ( $request ) {
                    global $wpdb;

                    $seconds = max( 0, (int) $request['seconds'] );
                    $order   = wc_get_order( (int) $request['id'] );

                    if ( ! $order ) {
                        return new WP_Error( 'ct_e2e_no_order', 'No such order', array( 'status' => 404 ) );
                    }

                    $order->set_date_created( $order->get_date_created()->getTimestamp() - $seconds );

                    if ( $order->get_date_paid() ) {
                        $order->set_date_paid( $order->get_date_paid()->getTimestamp() - $seconds );
                    }

                    $seen = \StorePulse\ConversionTracking\Log\CheckIn::thankyou_seen( $order );

                    if ( $seen ) {
                        $order->update_meta_data( \StorePulse\ConversionTracking\Log\CheckIn::THANKYOU_META, $seen - $seconds );
                    }

                    $order->save();

                    // A fresh store's count-once cutoff is minutes old; keep the moved order after it.
                    $since = (int) get_option( 'wcct_count_once_since', 0 );

                    if ( $since > $order->get_date_created()->getTimestamp() ) {
                        update_option( 'wcct_count_once_since', $order->get_date_created()->getTimestamp() - 60 );
                    }

                    $table = \StorePulse\ConversionTracking\Log\OrderLog::table();

                    foreach ( array( 'thankyou_seen_at', 'sent_at', 'created_at', 'updated_at' ) as $column ) {
                        $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET {$column} = DATE_SUB({$column}, INTERVAL %d SECOND) WHERE order_id = %d AND {$column} IS NOT NULL", $seconds, $order->get_id() ) );
                    }

                    return array( 'id' => $order->get_id() );
                },
            )
        );
        register_rest_route(
            'ct-e2e/v1',
            '/admin-order',
            array(
                'methods'             => 'POST',
                'permission_callback' => '__return_true',
                'callback'            => static function () {
                    $products = wc_get_products( array( 'limit' => 1, 'status' => 'publish' ) );
                    $order    = new WC_Order();

                    $order->set_created_via( 'admin' );
                    $order->add_product( $products[0], 1 );
                    $order->calculate_totals( false );
                    $order->set_status( 'processing' );
                    $order->save();

                    return array( 'id' => $order->get_id() );
                },
            )
        );
    }
);

// The ct_e2e_block_rest cookie answers 403 on wcct/v1/events, like a security plugin that blocks REST for guests.
add_filter(
    'rest_pre_dispatch',
    static function ( $result, $server, $request ) {
        if ( isset( $_COOKIE['ct_e2e_block_rest'] ) && 0 === strpos( $request->get_route(), '/wcct/v1/events' ) ) {
            return new WP_Error( 'rest_forbidden', 'Blocked for the e2e test', array( 'status' => 403 ) );
        }

        return $result;
    },
    10,
    3
);

// Check-in specs: the ct_e2e_consent_api=optin cookie adds a minimal opt-in WP Consent API (a category is allowed only when its wp_consent_<category> cookie is 'allow'). Pair it with ct_e2e_consent=on. To merge with the Meta branch's ct-e2e-consent.php stub, whichever lands second.
if ( isset( $_COOKIE['ct_e2e_consent_api'] ) && 'optin' === $_COOKIE['ct_e2e_consent_api'] ) {
    if ( ! function_exists( 'wp_has_consent' ) ) {
        function wp_has_consent( $category ) {
            return isset( $_COOKIE[ 'wp_consent_' . $category ] ) && 'allow' === $_COOKIE[ 'wp_consent_' . $category ];
        }
    }

    if ( ! function_exists( 'wp_get_consent_type' ) ) {
        function wp_get_consent_type() {
            return 'optin';
        }
    }
}

// The ct_e2e_no_unfiltered_html cookie takes unfiltered_html away, as on a multisite site admin or with DISALLOW_UNFILTERED_HTML.
add_filter(
    'user_has_cap',
    static function ( $allcaps ) {
        if ( isset( $_COOKIE['ct_e2e_no_unfiltered_html'] ) && is_array( $allcaps ) ) {
            $allcaps['unfiltered_html'] = false;
        }

        return $allcaps;
    },
    99
);
