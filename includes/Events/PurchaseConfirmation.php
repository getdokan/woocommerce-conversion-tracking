<?php

namespace StorePulse\ConversionTracking\Events;

/**
 * The browser confirms each platform's Purchase once the platform library has taken it; only then is the sent flag written.
 */
class PurchaseConfirmation {

    /**
     * AJAX action the browser calls.
     */
    const ACTION = 'wcct_purchase_sent';

    /**
     * Give up waiting for a platform library after this many milliseconds; the next page view sends again.
     */
    const WAIT_MS = 30000;

    /**
     * Returns the active platforms.
     *
     * @var callable
     */
    private $platform_source;

    /**
     * Confirmation over the platforms a callable returns.
     *
     * @param callable $platform_source Returns the active platforms.
     */
    public function __construct( callable $platform_source ) {
        $this->platform_source = $platform_source;
    }

    /**
     * Hook the AJAX action, for guests too.
     *
     * @return void
     */
    public function init_hooks() {
        add_action( 'wp_ajax_' . self::ACTION, array( $this, 'handle' ) );
        add_action( 'wp_ajax_nopriv_' . self::ACTION, array( $this, 'handle' ) );
    }

    /**
     * Mark the platform's Purchase as sent; the order key is the credential, as on the thank-you page.
     *
     * @return void
     */
    public function handle() {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- the order key is checked instead; pages are cached.
        $platform  = isset( $_POST['platform'] ) && is_string( $_POST['platform'] ) ? sanitize_key( wp_unslash( $_POST['platform'] ) ) : '';
        $order_id  = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
        $order_key = isset( $_POST['key'] ) ? wp_unslash( $_POST['key'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in order_by_key()
        // phpcs:enable

        if ( ! $this->is_platform( $platform ) ) {
            wp_send_json_error( null, 400 );
        }

        $order = self::order_by_key( $order_id, $order_key );

        if ( ! $order ) {
            wp_send_json_error( null, 403 );
        }

        SentFlags::mark_sent( $order, $platform );

        wp_send_json_success();
    }

    /**
     * Order for an ID, only when the order key matches.
     *
     * @param int   $order_id  Order ID.
     * @param mixed $order_key Unsanitized key.
     *
     * @return \WC_Order|false
     */
    public static function order_by_key( $order_id, $order_key ) {
        $order_key = is_string( $order_key ) ? wc_clean( $order_key ) : '';
        $order     = $order_id ? wc_get_order( $order_id ) : false;

        if ( ! $order instanceof \WC_Order || '' === $order_key || ! hash_equals( $order->get_order_key(), $order_key ) ) {
            return false;
        }

        return $order;
    }

    /**
     * Defines window.wcctPurchaseSent(url, platform, orderId, key), which returns one confirm function per platform and order; confirm.when(check, category) waits for check() and consent.
     *
     * @return string
     */
    public static function helper() {
        return 'window.wcctPurchaseSent = window.wcctPurchaseSent || function (url, platform, orderId, key) {
    var sent = window.wcctPurchaseSent, all = sent.all = sent.all || {}, id = platform + ":" + orderId;
    if (all[id]) return all[id];
    var done = false;
    var confirm = all[id] = function () {
        if (done) return;
        done = true;
        var body = new FormData();
        body.append("action", ' . self::encode( self::ACTION ) . ');
        body.append("platform", platform);
        body.append("order_id", orderId);
        body.append("key", key);
        if (navigator.sendBeacon && navigator.sendBeacon(url, body)) return;
        var xhr = new XMLHttpRequest();
        xhr.open("POST", url);
        xhr.send(body);
    };
    confirm.when = function (check, category) {
        var consent = window.wcctConsent, tries = Math.ceil(' . (int) self::WAIT_MS . ' / 250);
        var allowed = function () { return !category || !consent || !consent.allows || consent.allows(category); };
        (function poll() {
            if (done) return;
            var ready = false;
            try { ready = allowed() && !!check(); } catch (e) {}
            if (ready) return confirm();
            if (!allowed() && consent.when) return consent.when(category, function () { tries = Math.ceil(' . (int) self::WAIT_MS . ' / 250); poll(); });
            if (--tries > 0) setTimeout(poll, 250);
        })();
    };
    return confirm;
};';
    }

    /**
     * JS expression for the order's confirm function; the helper must be on the page.
     *
     * @param string    $platform Platform ID.
     * @param \WC_Order $order    Order.
     *
     * @return string
     */
    public static function callback( $platform, $order ) {
        return sprintf(
            'window.wcctPurchaseSent(%s, %s, %s, %s)',
            self::encode( admin_url( 'admin-ajax.php' ) ),
            self::encode( (string) $platform ),
            self::encode( (int) $order->get_id() ),
            self::encode( (string) $order->get_order_key() )
        );
    }

    /**
     * Code that confirms once a JS check is true and the consent category is allowed, or at once when both are empty.
     *
     * @param string    $platform Platform ID.
     * @param \WC_Order $order    Order.
     * @param string    $check    JS expression, true once the platform library has taken the event.
     * @param string    $category Consent category the event needs (wcctConsent), empty for none.
     *
     * @return string
     */
    public static function confirm_script( $platform, $order, $check = '', $category = '' ) {
        $callback = self::callback( $platform, $order );
        $check    = trim( (string) $check );
        $category = (string) $category;

        if ( '' === $check && '' === $category ) {
            return $callback . '();';
        }

        return $callback . '.when(function () { return ' . ( '' !== $check ? $check : 'true' ) . '; }' . ( '' !== $category ? ', ' . self::encode( $category ) : '' ) . ');';
    }

    /**
     * Whether the ID is an active platform.
     *
     * @param string $platform Platform ID.
     *
     * @return bool
     */
    private function is_platform( $platform ) {
        if ( '' === $platform ) {
            return false;
        }

        foreach ( (array) call_user_func( $this->platform_source ) as $integration ) {
            if ( $integration instanceof \WCCT_Integration && $platform === $integration->get_id() ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Encode a value for an inline script.
     *
     * @param mixed $data Value.
     *
     * @return string
     */
    private static function encode( $data ) {
        return wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES );
    }
}
