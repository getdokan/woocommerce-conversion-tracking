<?php

/**
 * Integration with settings in memory and a fixed script per event.
 */
class WCCT_Test_Fake_Integration extends WCCT_Integration {

    public $settings_value;

    public function __construct( $id, $enabled = true, $transports = array( 'browser' ) ) {
        $this->id             = $id;
        $this->transports     = $transports;
        $this->supports       = array( 'checkout', 'product_view', 'registration' );
        $this->settings_value = array( 'enabled' => $enabled );
    }

    public function get_settings() {
        return array();
    }

    public function enqueue_script() {
    }

    public function get_integration_settings() {
        return $this->settings_value;
    }

    public function checkout( $order_id ) {
        return $this->id . ".purchase(" . $order_id . ");";
    }

    public function registration() {
        return '<img src="x">';
    }

    public function get_footer_markup( array $events ) {
        return 'markup' === $this->id ? parent::get_footer_script( $events ) : '';
    }

    public function product_view() {
        return $this->id . '.view(' . $this->current_event->get_product_ids()[0] . ');';
    }
}
