<?php
/**
 * Runs one job in its own PHP process: a database reset, a PHP file, or one front-end request.
 *
 * Usage: php run.php <job.json>. Started by WCCT_Test_WP::run(); the JSON result is written to $job['result'].
 */

// phpcs:disable

ob_start();

$wcct_job    = json_decode( file_get_contents( $argv[1] ), true );
$wcct_result = array( 'error' => null );
$wcct_done   = false;

require_once dirname( __DIR__ ) . '/includes/class-recorder.php';

/**
 * Thrown instead of a redirect or wp_die(), which would end the process
 */
class WCCT_Test_Exit extends Exception {}

/**
 * Write the result file once
 *
 * @return void
 */
function wcct_test_write_result() {
    global $wcct_job, $wcct_result, $wcct_done;

    if ( $wcct_done ) {
        return;
    }

    $wcct_done = true;
    file_put_contents( $wcct_job['result'], json_encode( $wcct_result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR ) );
}

// A fatal error or an exit still leaves a result that says what happened.
register_shutdown_function(
    function () {
        global $wcct_result, $wcct_done;

        if ( ! $wcct_done ) {
            $error                = error_get_last();
            $wcct_result['error'] = 'The process ended early' . ( $error ? ': ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line'] : '' );
            wcct_test_write_result();
        }
    }
);

require $wcct_job['config'];

// Database reset: drop every table with the test prefix.
if ( 'reset' === $wcct_job['type'] ) {
    define( 'SHORTINIT', true );
    require ABSPATH . 'wp-settings.php';

    $wpdb->query( "SET foreign_key_checks = 0" );

    foreach ( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_prefix ) . '%' ) ) as $wcct_table ) {
        $wpdb->query( "DROP TABLE IF EXISTS `{$wcct_table}`" );
    }

    wcct_test_write_result();
    exit( 0 );
}

$wcct_home = defined( 'WP_HOME' ) ? WP_HOME : 'http://' . ( defined( 'WP_TESTS_DOMAIN' ) ? WP_TESTS_DOMAIN : 'example.org' );
$wcct_path = isset( $wcct_job['request']['path'] ) ? $wcct_job['request']['path'] : '/';
$wcct_host = parse_url( $wcct_home, PHP_URL_HOST ) . ( parse_url( $wcct_home, PHP_URL_PORT ) ? ':' . parse_url( $wcct_home, PHP_URL_PORT ) : '' );

// The request as a web server would hand it to WordPress.
$_SERVER['HTTP_HOST']       = $wcct_host;
$_SERVER['SERVER_NAME']     = parse_url( $wcct_home, PHP_URL_HOST );
$_SERVER['SERVER_PORT']     = parse_url( $wcct_home, PHP_URL_PORT ) ? parse_url( $wcct_home, PHP_URL_PORT ) : 80;
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['REQUEST_URI']     = $wcct_path;
$_SERVER['QUERY_STRING']    = (string) parse_url( $wcct_path, PHP_URL_QUERY );
$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['PHP_SELF']        = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = ABSPATH . 'index.php';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'WCCT characterisation tests';
$_SERVER['HTTPS']           = 'https' === parse_url( $wcct_home, PHP_URL_SCHEME ) ? 'on' : '';
unset( $_SERVER['argv'], $_SERVER['argc'] );

parse_str( $_SERVER['QUERY_STRING'], $_GET );
$_POST    = array();
$_COOKIE  = array();
$_REQUEST = $_GET;

defined( 'WP_USE_THEMES' ) || define( 'WP_USE_THEMES', true );
defined( 'WP_DEBUG_DISPLAY' ) || define( 'WP_DEBUG_DISPLAY', false );
defined( 'WC_USE_TRANSACTIONS' ) || define( 'WC_USE_TRANSACTIONS', false );

$wcct_recorder = new WCCT_Test_Recorder( $wcct_job['roots'] );

/**
 * Add a hook before WordPress has loaded its plugin API
 *
 * @param string   $hook          Hook name.
 * @param callable $callback      Callback.
 * @param int      $priority      Priority.
 * @param int      $accepted_args Accepted arguments.
 *
 * @return void
 */
function wcct_test_early_hook( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
    $GLOBALS['wp_filter'][ $hook ][ $priority ][] = array(
        'function'      => $callback,
        'accepted_args' => $accepted_args,
    );
}

wcct_test_early_hook( 'all', array( $wcct_recorder, 'on_hook' ) );

// Load the plugins under test the way WordPress loads active plugins, after the request's options are in place.
wcct_test_early_hook(
    'muplugins_loaded',
    function () {
        global $wcct_job, $wpdb;

        // Everything the request writes is rolled back, so every request sees the seeded store.
        if ( ! empty( $wcct_job['rollback'] ) ) {
            $wpdb->query( 'SET autocommit = 0' );
            $wpdb->query( 'START TRANSACTION' );

            // Roll back after everything WordPress and WooCommerce write on shutdown, too.
            add_action(
                'shutdown',
                function () use ( $wpdb ) {
                    $wpdb->query( 'ROLLBACK' );
                },
                PHP_INT_MAX
            );
        }

        foreach ( $wcct_job['options'] as $name => $value ) {
            if ( null === $value ) {
                delete_option( $name );
            } else {
                update_option( $name, $value );
            }
        }

        // User meta from earlier requests, e.g. the sign-up flag. Written to the table directly: the user API would load the current user too early.
        foreach ( $wcct_job['user_meta'] as $user_id => $meta ) {
            foreach ( $meta as $key => $value ) {
                $wpdb->insert( $wpdb->usermeta, array( 'user_id' => (int) $user_id, 'meta_key' => $key, 'meta_value' => maybe_serialize( $value ) ) );
            }
        }

        register_theme_directory( $wcct_job['theme_root'] );

        add_filter(
            'determine_current_user',
            function () {
                return (int) $GLOBALS['wcct_job']['user_id'];
            },
            99
        );

        // No network calls and no mail from the test store.
        add_filter(
            'pre_http_request',
            function () {
                return new WP_Error( 'wcct_test_http_blocked', 'HTTP requests are disabled in the characterisation tests' );
            }
        );
        add_filter( 'pre_wp_mail', '__return_true' );

        // Redirects and wp_die() end the request without exiting the process.
        add_filter(
            'wp_redirect',
            function ( $location, $status ) {
                throw new WCCT_Test_Exit( 'Redirect (' . $status . ') to ' . $location );
            },
            PHP_INT_MAX,
            2
        );
        $die_handler = function () {
            return function ( $message ) {
                throw new WCCT_Test_Exit( 'wp_die: ' . ( is_wp_error( $message ) ? $message->get_error_message() : wp_strip_all_tags( (string) $message ) ) );
            };
        };
        add_filter( 'wp_die_handler', $die_handler );
        add_filter( 'wp_die_ajax_handler', $die_handler );

        // The thank-you page is viewed right after checkout, inside WooCommerce's email verification grace period.
        add_filter(
            'woocommerce_order_email_verification_grace_period',
            function () {
                return 100 * YEAR_IN_SECONDS;
            }
        );

        foreach ( $wcct_job['plugins'] as $wcct_plugin_file ) {
            require $wcct_plugin_file;
        }
    },
    0,
    0
);

// A job's own set-up file (for example an order status), run before the page inside the request's transaction.
wcct_test_early_hook(
    'wp_loaded',
    function () {
        global $wcct_job, $wcct_recorder;

        if ( empty( $wcct_job['before'] ) ) {
            return;
        }

        $wcct_recorder->pause();
        include $wcct_job['before'];
        $wcct_recorder->resume();
    },
    5,
    0
);

// The cart from earlier requests, filled without add to cart events.
wcct_test_early_hook(
    'wp_loaded',
    function () {
        global $wcct_job, $wcct_recorder, $wp_filter;

        if ( ! isset( $wcct_job['cart'] ) || ! function_exists( 'WC' ) || ! WC()->cart ) {
            return;
        }

        $wcct_recorder->pause();
        $saved = isset( $wp_filter['woocommerce_add_to_cart'] ) ? $wp_filter['woocommerce_add_to_cart'] : null;
        unset( $wp_filter['woocommerce_add_to_cart'] );

        WC()->cart->empty_cart();

        foreach ( $wcct_job['cart'] as $item ) {
            WC()->cart->add_to_cart( $item['product_id'], $item['quantity'], isset( $item['variation_id'] ) ? $item['variation_id'] : 0, isset( $item['variation'] ) ? $item['variation'] : array() );
        }

        if ( $saved ) {
            $wp_filter['woocommerce_add_to_cart'] = $saved;
        }

        $wcct_recorder->resume();
    },
    15,
    0
);

try {
    require ABSPATH . 'wp-settings.php';

    if ( 'request' === $wcct_job['type'] ) {
        wp();
        require ABSPATH . WPINC . '/template-loader.php';

        $wcct_result['page'] = array();

        foreach ( array( 'is_front_page', 'is_home', 'is_shop', 'is_product_category', 'is_product', 'is_search', 'is_cart', 'is_checkout', 'is_order_received_page', 'is_account_page', 'is_user_logged_in', 'is_404' ) as $wcct_conditional ) {
            if ( function_exists( $wcct_conditional ) && call_user_func( $wcct_conditional ) ) {
                $wcct_result['page'][] = $wcct_conditional;
            }
        }
    } elseif ( 'file' === $wcct_job['type'] ) {
        $wcct_result['value'] = include $wcct_job['file'];
    }
} catch ( WCCT_Test_Exit $e ) {
    $wcct_result['error'] = $e->getMessage();
} catch ( Throwable $e ) {
    $wcct_result['error'] = get_class( $e ) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
}

$wcct_html = ob_get_clean();

// Self-check: everything recorded must be on the page as sent.
$wcct_result['not_on_page'] = array();

if ( 'request' === $wcct_job['type'] && ! $wcct_result['error'] ) {
    foreach ( $wcct_recorder->chunks as $wcct_chunk ) {
        if ( false === strpos( $wcct_html, $wcct_chunk[1] ) ) {
            $wcct_result['not_on_page'][] = $wcct_chunk[1];
        }
    }

    if ( $wcct_recorder->queued_js && false === strpos( $wcct_html, implode( '', $wcct_recorder->queued_js ) ) ) {
        $wcct_result['not_on_page'][] = implode( '', $wcct_recorder->queued_js );
    }
}

$wcct_result['home']      = function_exists( 'home_url' ) ? home_url() : $wcct_home;
$wcct_result['chunks']    = $wcct_recorder->chunks;
$wcct_result['queued_js'] = $wcct_recorder->queued_js;
$wcct_result['scripts']   = $wcct_recorder->describe_scripts();

if ( 'request' === $wcct_job['type'] ) {
    $wcct_result['html'] = $wcct_html;
}

wcct_test_write_result();
exit( 0 );
