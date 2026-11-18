<?php
/**
 * Gives the checkout's cart the shipping and taxes a test asks for, and sets the value mode. Runs before the page, inside its rolled-back transaction.
 */

// phpcs:disable

$wcct_state = isset( $GLOBALS['wcct_job']['before_args'] ) ? $GLOBALS['wcct_job']['before_args'] : array();

// Totals as WooCommerce keeps them: shipping without its tax, every tax in the total tax, all of it in the total.
if ( isset( $wcct_state['shipping'] ) ) {
    add_action(
        'woocommerce_after_calculate_totals',
        function ( $cart ) use ( $wcct_state ) {
            $tax = (float) $wcct_state['tax'];

            $cart->set_total( (float) $cart->get_total( 'edit' ) + (float) $wcct_state['shipping'] + $tax );
            $cart->set_shipping_total( $wcct_state['shipping'] );
            $cart->set_total_tax( $tax );
        },
        999
    );
}

if ( ! empty( $wcct_state['value_mode'] ) ) {
    add_filter(
        'wcct_value_mode',
        function () use ( $wcct_state ) {
            return $wcct_state['value_mode'];
        }
    );
}
