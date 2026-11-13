<?php

/**
 * Order meta, status and totals in memory, with the WC_Order method names.
 */
class WCCT_Test_Fake_Order {

    public $meta     = array();
    public $saves    = 0;
    public $id       = 0;
    public $key      = 'wc_order_test';
    public $status   = 'processing';
    public $created  = null;
    public $total    = '0';
    public $tax      = '0';
    public $shipping = '0';
    public $currency = 'USD';
    public $items    = array();

    public function __construct( array $props = array() ) {
        foreach ( $props as $name => $value ) {
            $this->$name = $value;
        }
    }

    public function get_id() {
        return $this->id;
    }

    public function get_order_key() {
        return $this->key;
    }

    public function get_status() {
        return $this->status;
    }

    public function get_date_created() {
        return null === $this->created ? null : new DateTimeImmutable( '@' . $this->created );
    }

    public function get_total() {
        return $this->total;
    }

    public function get_total_tax() {
        return $this->tax;
    }

    public function get_shipping_total() {
        return $this->shipping;
    }

    public function get_currency() {
        return $this->currency;
    }

    public function get_items() {
        return $this->items;
    }

    public function get_meta( $key, $single = true ) {
        return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
    }

    public function update_meta_data( $key, $value ) {
        $this->meta[ $key ] = $value;
    }

    public function delete_meta_data( $key ) {
        unset( $this->meta[ $key ] );
    }

    public function save_meta_data() {
        $this->saves++;
    }
}

if ( ! class_exists( 'WC_Order_Item_Product' ) ) {
    /**
     * A product line with its total after discounts, without tax.
     */
    class WC_Order_Item_Product {

        private $product_id;
        private $total;

        public function __construct( $product_id, $total ) {
            $this->product_id = $product_id;
            $this->total      = $total;
        }

        public function get_product_id() {
            return $this->product_id;
        }

        public function get_variation_id() {
            return 0;
        }

        public function get_total() {
            return $this->total;
        }
    }
}
