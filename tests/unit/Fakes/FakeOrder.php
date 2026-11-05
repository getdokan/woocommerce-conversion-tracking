<?php

/**
 * Order meta in memory, with the WC_Order meta method names.
 */
class WCCT_Test_Fake_Order {

    public $meta  = array();
    public $saves = 0;

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
