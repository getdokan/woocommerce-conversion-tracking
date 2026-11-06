<?php

/**
 * In-memory stand-in for WC_Session: get() and set() only
 */
class WCCT_Test_Fake_Session {

    public $data = array();

    public $writes = 0;

    public function get( $key, $default = null ) {
        return array_key_exists( $key, $this->data ) ? $this->data[ $key ] : $default;
    }

    public function set( $key, $value ) {
        $this->data[ $key ] = $value;
        $this->writes++;
    }
}
