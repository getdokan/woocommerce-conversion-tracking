<?php

namespace StorePulse\ConversionTracking\Events;

use StorePulse\ConversionTracking\Log\AjaxCheckIn;
use StorePulse\ConversionTracking\Log\CheckIn;

/**
 * The thank-you page checks in once per platform through the wcct/v1/events route; the route writes the sent flag and the log row.
 */
class PurchaseConfirmation {

    /**
     * Give up waiting for a platform library after this many milliseconds; the next page view sends again.
     */
    const WAIT_MS = 30000;

    /**
     * Check in as "not handed over" after this many milliseconds, or earlier when the page is hidden.
     */
    const CHECKIN_MS = 10000;

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
     * Defines window.wcctPurchaseSent(url, platform, orderId, key, eventId, category): one confirm function per platform and order, which checks in once handed over; confirm.when(check, category) waits for check() and consent.
     *
     * @return string
     */
    public static function helper() {
        return 'window.wcctPurchaseSent = window.wcctPurchaseSent || function (url, platform, orderId, key, eventId, category) {
    var sent = window.wcctPurchaseSent, all = sent.all = sent.all || {}, id = platform + ":" + orderId;
    if (all[id]) return all[id];
    var order = window.wcctOrder, done = false, missed = "";
    // One token per page load, sent with every check-in: the admin-ajax retry carries the same one, another load a new one.
    var load = sent.load = sent.load || (Math.random().toString(36).slice(2, 12) + Date.now().toString(36));
    // Funnel pages may not print the key; the head script kept it before it cleaned the address bar.
    if (!key && order && order.key && (!+order.id || String(order.id) === String(orderId))) key = order.key;
    var consentState = function () {
        var consent = window.wcctConsent;
        if (!consent || !consent.allows) return "off";
        try { return consent.allows(category || "marketing") ? "granted" : "denied"; } catch (e) { return "off"; }
    };
    var post = function (target, body, retry) {
        var fallback = function () { if (retry) post(' . self::encode( admin_url( 'admin-ajax.php' ) ) . ', "action=' . AjaxCheckIn::ACTION . '&" + body, false); };
        try {
            window.fetch(target, { method: "POST", body: body, keepalive: true, credentials: "same-origin", headers: { "Content-Type": "application/x-www-form-urlencoded" } })
                .then(function (response) { if (response.status == 401 || response.status == 403 || response.status == 404) fallback(); })
                .catch(fallback);
        } catch (e) { fallback(); }
    };
    // While the page is alive fetch can see a host that blocks guest REST and retry once on admin-ajax; an unloading page only has sendBeacon.
    var checkIn = function (loaded, reason, unloading) {
        if (!key) {
            if (window.console) console.warn("wcct: no order key on this page, the purchase check-in was skipped");
            return;
        }
        var body = "order_id=" + encodeURIComponent(orderId) + "&key=" + encodeURIComponent(key) + "&platform=" + encodeURIComponent(platform) + "&loaded=" + (loaded ? 1 : 0) + "&consent=" + consentState() + "&event_id=" + encodeURIComponent(eventId || "") + "&reason=" + reason + "&load=" + load;
        if (!unloading && window.fetch) return post(url, body, true);
        try {
            if (navigator.sendBeacon && navigator.sendBeacon(url, new Blob([body], { type: "application/x-www-form-urlencoded" }))) return;
        } catch (e) {}
        if (window.fetch) post(url, body, false);
    };
    var confirm = all[id] = function () {
        if (done) return;
        done = true;
        checkIn(true, "", false);
    };
    // Not handed over yet: check in anyway, so the server knows the page ran; a later hand-over still checks in, and a timeout still follows a hidden page.
    var miss = function (reason, unloading) {
        if (done || missed === reason || missed === "timeout") return;
        missed = reason;
        checkIn(false, reason, unloading);
    };
    setTimeout(function () { miss("timeout", false); }, ' . (int) self::CHECKIN_MS . ');
    window.addEventListener("pagehide", function () { miss("hidden", true); });
    document.addEventListener("visibilitychange", function () { if (document.visibilityState === "hidden") miss("hidden", true); });
    confirm.when = function (check, needs) {
        var consent = window.wcctConsent, tries = Math.ceil(' . (int) self::WAIT_MS . ' / 250);
        category = needs || category;
        var allowed = function () { return !needs || !consent || !consent.allows || consent.allows(needs); };
        (function poll() {
            if (done) return;
            var ready = false;
            try { ready = allowed() && !!check(); } catch (e) {}
            if (ready) return confirm();
            if (!allowed() && consent.when) return consent.when(needs, function () { tries = Math.ceil(' . (int) self::WAIT_MS . ' / 250); poll(); });
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
     * @param string    $category Consent category reported with the check-in, empty for marketing.
     *
     * @return string
     */
    public static function callback( $platform, $order, $category = '' ) {
        return sprintf(
            'window.wcctPurchaseSent(%s, %s, %s, %s, %s, %s)',
            self::encode( CheckIn::url() ),
            self::encode( (string) $platform ),
            self::encode( (int) $order->get_id() ),
            self::encode( (string) $order->get_order_key() ),
            self::encode( Event::new_event_id( Event::PURCHASE, (int) $order->get_id() ) ),
            self::encode( (string) $category )
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
        $callback = self::callback( $platform, $order, $category );
        $check    = trim( (string) $check );
        $category = (string) $category;

        if ( '' === $check && '' === $category ) {
            return $callback . '();';
        }

        return $callback . '.when(function () { return ' . ( '' !== $check ? $check : 'true' ) . '; }' . ( '' !== $category ? ', ' . self::encode( $category ) : '' ) . ');';
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
