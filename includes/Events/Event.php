<?php

namespace StorePulse\ConversionTracking\Events;

/**
 * One tracking event, shared by every platform: name, event ID, normalised value fields and its order or product data.
 */
class Event {

    const VIEW_PRODUCT    = 'view_product';
    const VIEW_CATEGORY   = 'view_category';
    const SEARCH          = 'search';
    const ADD_TO_CART     = 'add_to_cart';
    const BEGIN_CHECKOUT  = 'begin_checkout';
    const PURCHASE        = 'purchase';
    const SIGN_UP         = 'sign_up';
    const ADD_TO_WISHLIST = 'add_to_wishlist';

    /**
     * Event name, one of the constants above.
     *
     * @var string
     */
    private $name;

    /**
     * ID shared by every send of this event (browser now, server later).
     *
     * @var string
     */
    private $event_id;

    /**
     * Value sent with the event, in $currency; the order total for Purchase.
     *
     * @var float
     */
    private $value;

    /**
     * Tax included in the value.
     *
     * @var float
     */
    private $tax;

    /**
     * Shipping included in the value.
     *
     * @var float
     */
    private $shipping;

    /**
     * ISO 4217 currency code, or an empty string.
     *
     * @var string
     */
    private $currency;

    /**
     * Product line totals after discounts, without tax, fees or shipping.
     *
     * @var float
     */
    private $items_total;

    /**
     * Price decimals the amounts are rounded to.
     *
     * @var int
     */
    private $decimals;

    /**
     * The order, for order events.
     *
     * @var \WC_Order|null
     */
    private $order;

    /**
     * The order ID, for order events.
     *
     * @var int
     */
    private $order_id;

    /**
     * The product, for product events.
     *
     * @var \WC_Product|null
     */
    private $product;

    /**
     * IDs of the products the event is about.
     *
     * @var int[]
     */
    private $product_ids;

    /**
     * Extra data, for example the search string.
     *
     * @var array
     */
    private $data;

    /**
     * Hashed customer data (UserData), for platforms that send it with the event.
     *
     * @var array
     */
    private $user_data;

    /**
     * Build an event.
     *
     * @param string $name Event name.
     * @param array  $args event_id, value, tax, shipping, items_total, currency, decimals, order, order_id, product, product_ids, data, user_data.
     */
    public function __construct( string $name, array $args = array() ) {
        $decimals = isset( $args['decimals'] ) && is_numeric( $args['decimals'] ) ? max( 0, (int) $args['decimals'] ) : 2;

        $this->name        = $name;
        $this->decimals    = $decimals;
        $this->value       = self::amount( isset( $args['value'] ) ? $args['value'] : 0, $decimals );
        $this->tax         = self::amount( isset( $args['tax'] ) ? $args['tax'] : 0, $decimals );
        $this->shipping    = self::amount( isset( $args['shipping'] ) ? $args['shipping'] : 0, $decimals );
        $this->items_total = self::amount( isset( $args['items_total'] ) ? $args['items_total'] : 0, $decimals );
        $this->currency    = self::currency_code( isset( $args['currency'] ) ? $args['currency'] : '' );
        $this->order       = isset( $args['order'] ) && is_object( $args['order'] ) ? $args['order'] : null;
        $this->product     = isset( $args['product'] ) && is_object( $args['product'] ) ? $args['product'] : null;
        $this->product_ids = self::ids( isset( $args['product_ids'] ) ? $args['product_ids'] : array() );
        $this->data        = isset( $args['data'] ) && is_array( $args['data'] ) ? $args['data'] : array();
        $this->user_data   = isset( $args['user_data'] ) && is_array( $args['user_data'] ) ? $args['user_data'] : array();
        $this->order_id    = isset( $args['order_id'] ) && is_numeric( $args['order_id'] ) ? max( 0, (int) $args['order_id'] ) : 0;

        if ( ! $this->order_id && $this->order && method_exists( $this->order, 'get_id' ) ) {
            $this->order_id = (int) $this->order->get_id();
        }

        $this->event_id = isset( $args['event_id'] ) && is_string( $args['event_id'] ) && '' !== $args['event_id'] ? $args['event_id'] : self::new_event_id( $name, $this->order_id );
    }

    /**
     * Event name.
     *
     * @return string
     */
    public function get_name() {
        return $this->name;
    }

    /**
     * Event ID: order-derived for Purchase, unique per event otherwise.
     *
     * @return string
     */
    public function get_event_id() {
        return $this->event_id;
    }

    /**
     * Value sent with the event.
     *
     * @return float
     */
    public function get_value() {
        return $this->value;
    }

    /**
     * Tax included in the value.
     *
     * @return float
     */
    public function get_tax() {
        return $this->tax;
    }

    /**
     * Shipping included in the value.
     *
     * @return float
     */
    public function get_shipping() {
        return $this->shipping;
    }

    /**
     * The value a platform sends in a value mode (see ValueMode), filtered through wcct_event_value.
     *
     * @param string $mode     Value mode: items, shipping or all.
     * @param string $platform Platform ID.
     *
     * @return float
     */
    public function get_value_for( $mode, $platform = '' ) {
        $mode = ValueMode::is_mode( $mode ) ? $mode : ValueMode::ITEMS;

        return $this->filter_value( ValueMode::value( $this->value, $this->tax, $this->shipping, $mode, $this->decimals ), $mode, $platform );
    }

    /**
     * The product line total (fees excluded), filtered through wcct_event_value as mode items; what GA4 sends.
     *
     * @param string $platform Platform ID.
     *
     * @return float
     */
    public function get_items_total_value( $platform = '' ) {
        return $this->filter_value( $this->items_total, ValueMode::ITEMS, $platform );
    }

    /**
     * Product line totals after discounts, without tax, fees or shipping.
     *
     * @return float
     */
    public function get_items_total() {
        return $this->items_total;
    }

    /**
     * Run a value through wcct_event_value.
     *
     * @param float  $value    Value.
     * @param string $mode     Value mode.
     * @param string $platform Platform ID.
     *
     * @return float
     */
    private function filter_value( $value, $mode, $platform ) {
        /**
         * Value sent with an event.
         *
         * @since 3.0.0
         *
         * @param float          $value    Value for the mode.
         * @param string         $mode     Value mode: items, shipping or all.
         * @param Event          $event    Event.
         * @param \WC_Order|null $order    The order, for order events.
         * @param string         $platform Platform ID.
         */
        return self::amount( apply_filters( 'wcct_event_value', $value, $mode, $this, $this->order, (string) $platform ), $this->decimals );
    }

    /**
     * Price decimals.
     *
     * @return int
     */
    public function get_decimals() {
        return $this->decimals;
    }

    /**
     * Currency code, or an empty string.
     *
     * @return string
     */
    public function get_currency() {
        return $this->currency;
    }

    /**
     * The order, for order events.
     *
     * @return \WC_Order|null
     */
    public function get_order() {
        return $this->order;
    }

    /**
     * The order ID, 0 when the event has no order.
     *
     * @return int
     */
    public function get_order_id() {
        return $this->order_id;
    }

    /**
     * The product, for product events.
     *
     * @return \WC_Product|null
     */
    public function get_product() {
        return $this->product;
    }

    /**
     * IDs of the products the event is about.
     *
     * @return int[]
     */
    public function get_product_ids() {
        return $this->product_ids;
    }

    /**
     * One item of extra data.
     *
     * @param string $key     Data key.
     * @param mixed  $default Returned when the key is missing.
     *
     * @return mixed
     */
    public function get( $key, $default = null ) {
        return array_key_exists( $key, $this->data ) ? $this->data[ $key ] : $default;
    }

    /**
     * Hashed customer data, field => SHA-256 hex.
     *
     * @return array
     */
    public function get_user_data() {
        return $this->user_data;
    }

    /**
     * Event ID for a new event.
     *
     * @param string $name     Event name.
     * @param int    $order_id Order ID, 0 for none.
     *
     * @return string
     */
    public static function new_event_id( $name, $order_id = 0 ) {
        if ( self::PURCHASE === $name && $order_id > 0 ) {
            return 'wcct_' . (int) $order_id . '_purchase';
        }

        $bytes    = random_bytes( 16 );
        $bytes[6] = chr( ord( $bytes[6] ) & 0x0f | 0x40 );
        $bytes[8] = chr( ord( $bytes[8] ) & 0x3f | 0x80 );

        return 'wcct_' . vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $bytes ), 4 ) );
    }

    /**
     * Normalise an amount: numeric, finite, not negative, rounded to the price decimals.
     *
     * @param mixed $amount   Raw amount.
     * @param int   $decimals Price decimals.
     *
     * @return float
     */
    public static function amount( $amount, $decimals = 2 ) {
        if ( is_string( $amount ) ) {
            $amount = trim( $amount );
        }

        if ( ! is_numeric( $amount ) ) {
            return 0.0;
        }

        $amount = (float) $amount;

        if ( ! is_finite( $amount ) || $amount < 0 ) {
            return 0.0;
        }

        return round( $amount, max( 0, (int) $decimals ) );
    }

    /**
     * Normalise a currency code to three upper-case letters, or an empty string.
     *
     * @param mixed $currency Raw currency.
     *
     * @return string
     */
    public static function currency_code( $currency ) {
        $currency = is_string( $currency ) ? strtoupper( trim( $currency ) ) : '';

        return preg_match( '/^[A-Z]{3}$/', $currency ) ? $currency : '';
    }

    /**
     * Positive integer IDs, in order, without duplicates.
     *
     * @param mixed $ids Raw IDs.
     *
     * @return int[]
     */
    private static function ids( $ids ) {
        $clean = array();

        foreach ( (array) $ids as $id ) {
            if ( is_numeric( $id ) && (int) $id > 0 ) {
                $clean[] = (int) $id;
            }
        }

        return array_values( array_unique( $clean ) );
    }
}
