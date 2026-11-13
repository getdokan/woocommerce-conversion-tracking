<?php
/**
 * Puts order 3001 in the state a test asks for (status, sent flags, fees, taxes, currency, value mode). Runs before the page, inside its rolled-back transaction.
 */

// phpcs:disable

$wcct_state = isset( $GLOBALS['wcct_job']['before_args'] ) ? $GLOBALS['wcct_job']['before_args'] : array();
$wcct_order = wc_get_order( 3001 );

if ( ! empty( $wcct_state['status'] ) ) {
    $wcct_order->set_status( $wcct_state['status'] );
    $wcct_order->save();
}

foreach ( isset( $wcct_state['sent'] ) ? $wcct_state['sent'] : array() as $wcct_platform ) {
    \StorePulse\ConversionTracking\Events\SentFlags::mark_sent( $wcct_order, $wcct_platform );
}

// Fee lines (negative ones act as discounts), each with its tax.
foreach ( isset( $wcct_state['fees'] ) ? $wcct_state['fees'] : array() as $wcct_fee ) {
    $wcct_item = new WC_Order_Item_Fee();
    $wcct_item->set_props(
        array(
            'name'      => $wcct_fee[0],
            'total'     => $wcct_fee[1],
            'total_tax' => isset( $wcct_fee[2] ) ? $wcct_fee[2] : 0,
        )
    );
    $wcct_order->add_item( $wcct_item );
}

// Totals as WooCommerce stores them: lines and fees without tax, every tax in cart and shipping tax.
if ( isset( $wcct_state['totals'] ) ) {
    $wcct_order->set_prices_include_tax( ! empty( $wcct_state['totals']['prices_include_tax'] ) );
    $wcct_order->set_cart_tax( $wcct_state['totals']['cart_tax'] );
    $wcct_order->set_shipping_tax( $wcct_state['totals']['shipping_tax'] );
    $wcct_order->set_total( $wcct_state['totals']['total'] );
}

if ( ! empty( $wcct_state['currency'] ) ) {
    $wcct_order->set_currency( $wcct_state['currency'] );
}

$wcct_order->save();

if ( ! empty( $wcct_state['value_mode'] ) ) {
    add_filter(
        'wcct_value_mode',
        function () use ( $wcct_state ) {
            return $wcct_state['value_mode'];
        }
    );
}
