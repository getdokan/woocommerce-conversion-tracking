<?php
/**
 * Prints order 3001's thank-you page flag at the end of the page, after template_redirect wrote it. See PurchaseOnceTest.
 */

// phpcs:disable

add_action(
    'wp_footer',
    function () {
        $order = wc_get_order( 3001 );

        echo '<!--wcct-thankyou-seen:' . (int) $order->get_meta( '_wcct_thankyou_seen', true ) . '-->';
    },
    PHP_INT_MAX
);
