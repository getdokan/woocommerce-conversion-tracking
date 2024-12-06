<?php

/**
 * Abstract Class
 */
abstract class WCCT_Integration {

    /**
     * Store integration's id
     *
     * @var [type]
     */
    protected $id;

    /**
     * Store integration's name
     *
     * @var string
     */
    protected $name;

    /**
     * Check enable
     *
     * @var boolean
     */
    protected $enabled;

    /**
     * Store integration's settings
     *
     * @var array
     */
    protected $settings = array();

    /**
     * Store all supported features
     *
     * @var array
     */
    protected $supports = array();

    /**
     * Get settings for all integration
     *
     * @return array
     */
    abstract public function get_settings();

    /**
     * Enqueue integration script
     *
     * @return void
     */
    abstract public function enqueue_script();

    /**
     * Get the integration id
     *
     * @return [type] [description]
     */
    public function get_id() {
        return $this->id;
    }

    /**
     * Get the integration name
     *
     * @return string
     */
    public function get_name() {
        return $this->name;
    }

    /**
     * Check the integration enable or not
     *
     * @return boolean
     */
    public function is_enabled() {
        $settings = $this->get_integration_settings();

        if ( $settings && wc_string_to_bool($settings[ 'enabled' ]) ) {
            return true;
        }

        return false;
    }

    /**
     * Check if an event is enabled
     *
     * @param  string $event
     *
     * @return boolean
     */
    public function event_enabled( $event ) {
        $settings = $this->get_integration_settings();

        if ( isset( $settings[0]['events'] ) && array_key_exists( $event, $settings[0]['events'] ) && $settings[0]['events'][ $event ] == 'on' ) {
            return true;
        }

        return false;
    }

    /**
     * Integration settings get options
     *
     * @return array|false
     */
    public function get_integration_settings() {
        $integration_settings = get_option( 'wcct_settings', array() );

        if ( isset( $integration_settings[ $this->id ] ) ) {
            return $integration_settings[ $this->id ];
        }

        return false;
    }

    /**
     * Check feattures
     *
     * @param  array $feature
     *
     * @return boolean
     */
    public function supports( $feature ) {
        if ( in_array( $feature, $this->supports ) ) {
            return true;
        }

        return false;
    }

    /**
     * Helper function to iterate through a cart and gather all content ids
     *
     * @param  array $cart
     *
     * @return array
     */
    protected function get_content_ids_from_cart( $cart ) {
        $product_ids = array();

        foreach ($cart as $item) {
            $product_ids[] = $item['data']->get_id();
        }

        return $product_ids;
    }
}
