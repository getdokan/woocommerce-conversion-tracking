<?php

/**
 * GA4 data helpers
 *
 * Pure functions that only work on plain values and arrays, so they can be
 * unit tested without loading WordPress or WooCommerce.
 */
class WCCT_GA4_Data {

    /**
     * Order statuses that are never sent as a purchase
     *
     * @var array
     */
    const SKIPPED_STATUSES = array( 'failed', 'cancelled' );

    /**
     * Clean a measurement ID and return it only if it is a valid GA4 ID
     *
     * @param  mixed $measurement_id
     *
     * @return string Upper-cased ID, or an empty string when invalid
     */
    public static function sanitize_measurement_id( $measurement_id ) {
        if ( ! is_string( $measurement_id ) ) {
            return '';
        }

        $measurement_id = strtoupper( trim( $measurement_id ) );

        if ( ! preg_match( '/^G-[A-Z0-9]{4,20}$/', $measurement_id ) ) {
            return '';
        }

        return $measurement_id;
    }

    /**
     * Unit price and unit discount for a line, from line totals
     *
     * Both totals exclude tax. The subtotal is before discounts, the total after.
     *
     * @param  float $line_subtotal
     * @param  float $line_total
     * @param  int   $quantity
     * @param  int   $decimals
     *
     * @return array {price, discount}
     */
    public static function line_item_values( $line_subtotal, $line_total, $quantity, $decimals = 2 ) {
        $quantity = max( 1, (int) $quantity );
        $discount = ( (float) $line_subtotal - (float) $line_total ) / $quantity;

        return array(
            'price'    => round( (float) $line_total / $quantity, $decimals ),
            'discount' => $discount > 0 ? round( $discount, $decimals ) : 0,
        );
    }

    /**
     * Build one GA4 item
     *
     * @param  array $product  {id, name, variant, categories}
     * @param  int   $quantity
     * @param  float $price    Unit price after discount, excluding tax
     * @param  float $discount Unit discount
     * @param  int   $decimals
     *
     * @return array
     */
    public static function build_item( array $product, $quantity, $price, $discount = 0, $decimals = 2 ) {
        $item = array(
            'item_id'   => isset( $product['id'] ) ? (string) $product['id'] : '',
            'item_name' => isset( $product['name'] ) ? (string) $product['name'] : '',
        );

        if ( ! empty( $product['variant'] ) ) {
            $item['item_variant'] = (string) $product['variant'];
        }

        $categories = isset( $product['categories'] ) ? (array) $product['categories'] : array();
        $categories = array_values( array_unique( array_filter( array_map( 'strval', $categories ), 'strlen' ) ) );

        foreach ( array_slice( $categories, 0, 5 ) as $index => $category ) {
            $item[ 0 === $index ? 'item_category' : 'item_category' . ( $index + 1 ) ] = $category;
        }

        $item['price'] = round( (float) $price, $decimals );

        if ( (float) $discount > 0 ) {
            $item['discount'] = round( (float) $discount, $decimals );
        }

        $item['quantity'] = max( 1, (int) $quantity );

        return $item;
    }

    /**
     * Sum of price x quantity for all items, which is what GA4 expects as value
     *
     * @param  array $items
     * @param  int   $decimals
     *
     * @return float
     */
    public static function items_value( array $items, $decimals = 2 ) {
        $value = 0;

        foreach ( $items as $item ) {
            $price    = isset( $item['price'] ) ? (float) $item['price'] : 0;
            $quantity = isset( $item['quantity'] ) ? (int) $item['quantity'] : 1;
            $value   += $price * $quantity;
        }

        return round( $value, $decimals );
    }

    /**
     * Parameters for an item event (view_item, add_to_cart, begin_checkout)
     *
     * @param  string $send_to
     * @param  string $currency
     * @param  array  $items
     * @param  int    $decimals
     * @param  array  $coupons
     * @param  float  $value    Total from line totals. Defaults to price x quantity of the items,
     *                          which drifts from the line totals when unit prices are rounded.
     *
     * @return array
     */
    public static function event_params( $send_to, $currency, array $items, $decimals = 2, array $coupons = array(), $value = null ) {
        $params = array(
            'send_to'  => (string) $send_to,
            'currency' => (string) $currency,
            'value'    => null === $value ? self::items_value( $items, $decimals ) : round( (float) $value, $decimals ),
            'items'    => array_values( $items ),
        );

        $coupon = self::coupon_string( $coupons );

        if ( '' !== $coupon ) {
            $params['coupon'] = $coupon;
        }

        return $params;
    }

    /**
     * Parameters for the purchase event
     *
     * Value is the item total after discounts. Tax and shipping are sent
     * separately and are not part of value.
     *
     * @param  string $send_to
     * @param  string $transaction_id
     * @param  string $currency
     * @param  array  $items
     * @param  float  $tax
     * @param  float  $shipping
     * @param  array  $coupons
     * @param  int    $decimals
     * @param  float  $value    Item total after discounts, see event_params()
     *
     * @return array
     */
    public static function purchase_params( $send_to, $transaction_id, $currency, array $items, $tax, $shipping, array $coupons = array(), $decimals = 2, $value = null ) {
        $params = self::event_params( $send_to, $currency, $items, $decimals, $coupons, $value );

        $params['transaction_id'] = (string) $transaction_id;
        $params['tax']            = round( (float) $tax, $decimals );
        $params['shipping']       = round( (float) $shipping, $decimals );

        return $params;
    }

    /**
     * Transaction ID for an order, never empty
     *
     * @param  mixed $order_number
     * @param  int   $order_id
     *
     * @return string
     */
    public static function transaction_id( $order_number, $order_id ) {
        $transaction_id = is_scalar( $order_number ) ? trim( (string) $order_number ) : '';

        return '' !== $transaction_id ? $transaction_id : (string) abs( (int) $order_id );
    }

    /**
     * Whether an order status counts as a purchase
     *
     * @param  string $status Status without the wc- prefix
     *
     * @return boolean
     */
    public static function is_trackable_status( $status ) {
        return ! in_array( (string) $status, self::SKIPPED_STATUSES, true );
    }

    /**
     * Join coupon codes into one string
     *
     * @param  array $coupons
     *
     * @return string
     */
    public static function coupon_string( array $coupons ) {
        $coupons = array_filter( array_map( 'trim', array_map( 'strval', $coupons ) ), 'strlen' );

        return implode( ',', array_values( array_unique( $coupons ) ) );
    }
}
