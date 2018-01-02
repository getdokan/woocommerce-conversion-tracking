<?php

/**
 * Ajax handler class
 */
class WCCT_Ajax {

    /**
     * WC Conversion Tracking Ajax Class Constructor
     */
    public function __construct() {
        add_action( 'wp_ajax_wcct_save_settings', array( $this, 'wcct_save_settings' ) );
    }

    /**
     * Save integration settings
     *
     * @return void
     */
    public function wcct_save_settings() {
        if ( !current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( isset( $_POST['fields'] ) ) {
            parse_str( $_POST['fields'], $fields );
        } else {
            return;
        }

        $integration_enabled = array(
            'facebook'  =>  !empty( $fields['facebook_enabled'] ) ? intval( $fields['facebook_enabled'] ) : 0,
            'twitter'   =>  !empty( $fields['twitter_enabled'] ) ? intval( $fields['twitter_enabled'] ) : 0,
            'google'    =>  !empty( $fields['google_enabled'] ) ? intval( $fields['google_enabled'] ) : 0,
            'custom'    =>  !empty( $fields['custom_enabled'] ) ? intval( $fields['custom_enabled'] ) : 0,
        );

        $integration_settings = array();

        foreach ( $fields as $key => $field ) {
            $integration_settings[$key] = $field;
        }

        update_option( 'integration_enabled', $integration_enabled );
        update_option( 'integration_settings', stripslashes_deep( $integration_settings ) );
        wp_send_json_success();

    }
}