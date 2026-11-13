<?php

namespace StorePulse\ConversionTracking\Onboarding;

use StorePulse\ConversionTracking\Log\AjaxCheckIn;
use StorePulse\ConversionTracking\Log\CheckIn;

/**
 * The wizard's Verify step: a test link opens the store, and that page sends one throttled "seen" ping per test link.
 */
class Verify {

    /**
     * Option holding the open test: token, started, seen and platforms.
     */
    const OPTION = 'wcct_verify';

    /**
     * Query parameter of the test link.
     */
    const PARAM = 'wcct_verify';

    /**
     * A test link works this many seconds after it was made.
     */
    const TTL = 3600;

    /**
     * Seen pings for one test link are recorded at most once per this many seconds.
     */
    const THROTTLE = 10;

    /**
     * Results of accept() and record_seen().
     */
    const OK        = 'ok';
    const INVALID   = 'invalid';
    const EXPIRED   = 'expired';
    const THROTTLED = 'throttled';

    /**
     * Returns the active platforms.
     *
     * @var callable
     */
    private $platform_source;

    /**
     * Storefront ping over the platforms a callable returns.
     *
     * @param callable $platform_source Returns the active platforms.
     */
    public function __construct( callable $platform_source ) {
        $this->platform_source = $platform_source;
    }

    /**
     * Hook into WordPress.
     *
     * @return void
     */
    public function init_hooks() {
        add_action( 'wp_footer', array( $this, 'print_ping' ), 20 );
    }

    /**
     * Open a new test and return its link; any earlier link stops working.
     *
     * @param int|null $now Unix time, default now.
     *
     * @return array url, test and started_at.
     */
    public static function start( $now = null ) {
        $state = array(
            'token'     => wp_generate_password( 32, false ),
            'started'   => null === $now ? time() : (int) $now,
            'seen'      => 0,
            'platforms' => array(),
        );

        update_option( self::OPTION, $state, false );

        return array(
            'url'        => self::url( $state['token'] ),
            'test'       => self::test_id( $state['token'] ),
            'started_at' => $state['started'],
        );
    }

    /**
     * A non-secret ID of a test link, so the wizard can tell its own test from a newer one.
     *
     * @param string $token Token.
     *
     * @return string
     */
    public static function test_id( $token ) {
        return '' === $token ? '' : substr( hash( 'sha256', $token ), 0, 8 );
    }

    /**
     * The open test's link, or an empty string once it expired; for the wizard to pick up a test after a reload.
     *
     * @param int|null $now Unix time, default now.
     *
     * @return string
     */
    public static function current_url( $now = null ) {
        $state = self::stored();
        $now   = null === $now ? time() : (int) $now;

        return '' !== $state['token'] && $now - $state['started'] < self::TTL ? self::url( $state['token'] ) : '';
    }

    /**
     * The open test without its token: active, test, started_at, seen_at and platforms (ID => library loaded).
     *
     * @param int|null $now Unix time, default now.
     *
     * @return array
     */
    public static function state( $now = null ) {
        $state = self::stored();
        $now   = null === $now ? time() : (int) $now;

        return array(
            'active'     => '' !== $state['token'] && $now - $state['started'] < self::TTL,
            'test'       => self::test_id( $state['token'] ),
            'started_at' => $state['started'],
            'seen_at'    => $state['seen'],
            'platforms'  => $state['platforms'],
        );
    }

    /**
     * Whether a ping with this token is recorded now.
     *
     * @param array  $state Stored test, see stored().
     * @param string $token Token from the ping.
     * @param int    $now   Unix time.
     *
     * @return string One of the result constants.
     */
    public static function accept( array $state, $token, $now ) {
        if ( empty( $state['token'] ) || ! is_string( $token ) || ! hash_equals( (string) $state['token'], $token ) ) {
            return self::INVALID;
        }

        if ( $now - (int) $state['started'] >= self::TTL ) {
            return self::EXPIRED;
        }

        if ( ! empty( $state['seen'] ) && $now - (int) $state['seen'] < self::THROTTLE ) {
            return self::THROTTLED;
        }

        return self::OK;
    }

    /**
     * Record a seen ping; platforms maps known platform IDs to whether their library loaded, and a library seen once stays loaded while the test is open.
     *
     * @param string   $token     Token from the ping.
     * @param array    $platforms Platform ID => library loaded.
     * @param string[] $known     Active platform IDs; others are dropped.
     * @param int|null $now       Unix time, default now.
     *
     * @return string One of the result constants.
     */
    public static function record_seen( $token, array $platforms, array $known, $now = null ) {
        $now    = null === $now ? time() : (int) $now;
        $state  = self::stored();
        $result = self::accept( $state, $token, $now );

        if ( self::OK !== $result ) {
            return $result;
        }

        $loaded = array();

        foreach ( $known as $id ) {
            $loaded[ $id ] = ( isset( $platforms[ $id ] ) && in_array( $platforms[ $id ], array( true, 1, '1', 'true' ), true ) ) || ! empty( $state['platforms'][ $id ] );
        }

        $state['seen']      = $now;
        $state['platforms'] = $loaded;

        update_option( self::OPTION, $state, false );

        return self::OK;
    }

    /**
     * The test link for a token.
     *
     * @param string $token Token.
     *
     * @return string
     */
    public static function url( $token ) {
        return add_query_arg( self::PARAM, rawurlencode( $token ), home_url( '/' ) );
    }

    /**
     * The stored test, with every key.
     *
     * @return array
     */
    public static function stored() {
        $state = get_option( self::OPTION, array() );
        $state = is_array( $state ) ? $state : array();

        return array(
            'token'     => isset( $state['token'] ) && is_string( $state['token'] ) ? $state['token'] : '',
            'started'   => isset( $state['started'] ) ? (int) $state['started'] : 0,
            'seen'      => isset( $state['seen'] ) ? (int) $state['seen'] : 0,
            'platforms' => isset( $state['platforms'] ) && is_array( $state['platforms'] ) ? $state['platforms'] : array(),
        );
    }

    /**
     * The token in this request's URL when it opens the current test, else an empty string.
     *
     * @return string
     */
    public static function request_token() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the token is the credential.
        $token = isset( $_GET[ self::PARAM ] ) && is_string( $_GET[ self::PARAM ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::PARAM ] ) ) : '';

        if ( '' === $token || ! preg_match( '/^[A-Za-z0-9]{20,64}$/', $token ) ) {
            return '';
        }

        $state = self::stored();

        return '' !== $state['token'] && hash_equals( $state['token'], $token ) && time() - $state['started'] < self::TTL ? $token : '';
    }

    /**
     * Print the seen ping on a page opened through the test link; other pages get nothing.
     *
     * @return void
     */
    public function print_ping() {
        if ( is_admin() ) {
            return;
        }

        $token = self::request_token();

        if ( '' === $token ) {
            return;
        }

        $checks = array();

        foreach ( (array) call_user_func( $this->platform_source ) as $platform ) {
            if ( $platform instanceof \WCCT_Integration ) {
                $checks[] = wp_json_encode( (string) $platform->get_id() ) . ': function () { return ' . $platform->library_loaded_check() . '; }';
            }
        }

        if ( ! $checks ) {
            return;
        }

        wp_print_inline_script_tag( self::ping_script( CheckIn::url(), admin_url( 'admin-ajax.php' ), $token, $checks ), array( 'data-no-optimize' => '1' ) );
    }

    /**
     * The ping: waits up to 8 seconds for every library, then sends once per test link and tab every THROTTLE seconds; fetch first, admin-ajax when REST is blocked, sendBeacon only when the page goes away first.
     *
     * @param string   $url      Check-in URL.
     * @param string   $ajax_url admin-ajax.php URL.
     * @param string   $token    Token.
     * @param string[] $checks   JS object members, platform ID => function.
     *
     * @return string
     */
    public static function ping_script( $url, $ajax_url, $token, array $checks ) {
        $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES;

        return '(function (window) {
    var url = ' . wp_json_encode( $url, $flags ) . ', ajax = ' . wp_json_encode( $ajax_url, $flags ) . ', token = ' . wp_json_encode( $token, $flags ) . ', checks = {' . implode( ', ', $checks ) . '};
    var memo = "wcct_verify_" + token, started = new Date().getTime(), sent = false, timer;
    try {
        var last = +window.sessionStorage.getItem(memo);
        if (last && started - last < ' . (int) self::THROTTLE * 1000 . ') return;
    } catch (e) {}
    var body = function () {
        var out = "type=seen&token=" + encodeURIComponent(token), all = true;
        for (var id in checks) {
            var loaded = false;
            try { loaded = !!checks[id](); } catch (e) {}
            all = all && loaded;
            out += "&platforms[" + encodeURIComponent(id) + "]=" + (loaded ? 1 : 0);
        }
        return { text: out, all: all };
    };
    var post = function (target, text, retry) {
        var fallback = function () { if (retry) post(ajax, "action=' . AjaxCheckIn::ACTION . '&" + text, false); };
        try {
            window.fetch(target, { method: "POST", body: text, keepalive: true, credentials: "same-origin", headers: { "Content-Type": "application/x-www-form-urlencoded" } })
                .then(function (response) { if (response.status == 401 || response.status == 403 || response.status == 404) fallback(); })
                .catch(fallback);
        } catch (e) { fallback(); }
    };
    var send = function (unloading) {
        if (sent) return;
        sent = true;
        clearTimeout(timer);
        var text = body().text;
        try { window.sessionStorage.setItem(memo, String(new Date().getTime())); } catch (e) {}
        if (!unloading && window.fetch) return post(url, text, true);
        try {
            if (navigator.sendBeacon && navigator.sendBeacon(url, new Blob([text], { type: "application/x-www-form-urlencoded" }))) return;
        } catch (e) {}
        if (window.fetch) post(url, text, false);
    };
    window.addEventListener("pagehide", function () { send(true); });
    (function poll() {
        if (sent) return;
        if (!body().all && new Date().getTime() - started < 8000) {
            timer = setTimeout(poll, 250);
            return;
        }
        send(false);
    })();
})(window);';
    }
}
