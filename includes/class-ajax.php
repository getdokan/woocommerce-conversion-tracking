<?php

use StorePulse\ConversionTracking\Access\CustomScripts;
use StorePulse\ConversionTracking\Access\SettingsIds;
use StorePulse\ConversionTracking\Admin\PostedSettings;

/**
 * Ajax handler class
 */
class WCCT_Ajax {

    /**
     * WC Conversion Tracking Ajax Class Constructor
     */
    public function __construct() {
        add_action( 'wp_ajax_wcct_save_settings', array( $this, 'wcct_save_settings' ) );
        add_action( 'wp_ajax_activate_happy_addons', array( $this, 'wcct_install_happy_addons' ) );

        add_action( 'wp_ajax_wcct_dismissable_notice', array( $this, 'wcct_dismissable_notice' ) );
    }

    /**
     * Save integration settings
     *
     * @return void
     */
    public function wcct_save_settings() {
        if ( ! current_user_can( wcct_manage_cap() ) ) {
            wp_send_json_error(
                [
                    'message' => __( 'You do not have permission to change these settings.', 'woocommerce-conversion-tracking' ),
                ],
                403
            );
        }

        if ( ! isset( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_REQUEST['_wpnonce'] ) ), 'wcct-settings' ) ) {
            wp_send_json_error(
                [
                    'message' => __( 'nonce verification failed', 'woocommerce-conversion-tracking' )
                ],
                403
            );
        }

        if ( ! isset( $_POST['settings'] ) ) {
            wp_send_json_error();
        }

        $posted               = is_array( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- PostedSettings cleans it field by field.
        $integration_settings = PostedSettings::clean( $posted, $this->integration_fields() );
        $stored_settings      = get_option( 'wcct_settings', array() );
        $stored_settings      = is_array( $stored_settings ) ? $stored_settings : array();
        $can_edit_script      = current_user_can( CustomScripts::CAPABILITY );

        if ( ! $can_edit_script && CustomScripts::changed( $integration_settings, $stored_settings ) ) {
            wp_send_json_error(
                [
                    'message' => __( 'Only administrators can change custom scripts.', 'woocommerce-conversion-tracking' ),
                ],
                403
            );
        }

        // Callbacks have always received slashed data. Their output is trusted: only SettingsIds and the script guard run after.
        $integration_settings = wp_unslash( apply_filters( 'wcct_save_integrations_settings', wp_slash( $integration_settings ) ) );
        $integration_settings = SettingsIds::clean( is_array( $integration_settings ) ? $integration_settings : array() );

        if ( ! $can_edit_script ) {
            $integration_settings = CustomScripts::keep( $integration_settings, $stored_settings );
        }

        update_option( 'wcct_settings', $integration_settings );
        wp_send_json_success( array(
            'message' => __( 'Settings has been saved successfully!', 'woocommerce-conversion-tracking' )
        ) );

    }

    /**
     * Settings fields of every integration, keyed by integration ID
     *
     * @return array
     */
    private function integration_fields() {
        $fields = array();

        foreach ( wcct_init()->manager->get_integrations() as $integration ) {
            if ( $integration instanceof WCCT_Integration ) {
                $fields[ $integration->get_id() ] = (array) $integration->get_settings();
            }
        }

        return $fields;
    }

    /**
     * Install the Happy addons via ajax
     *
     * @return json
     */
    public function wcct_install_happy_addons() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error(
                [
                    'message' => __( 'You do not have permission to install Happy Addons', 'woocommerce-conversion-tracking' )
                ]
            );
        }

        if ( ! isset( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_REQUEST['_wpnonce'] ) ), 'wcct-settings' ) ) {
            wp_send_json_error(
                [
                    'message' => __( 'nonce verification failed', 'woocommerce-conversion-tracking' )
                ]
            );
        }

        include_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        $upgrader = new Plugin_Upgrader( new WP_Ajax_Upgrader_Skin() );

        $plugin = 'happy-elementor-addons';
        $api    = plugins_api( 'plugin_information', array( 'slug' => $plugin, 'fields' => array( 'sections' => false ) ) );


        if (is_wp_error($api)) {
            die(sprintf(__('ERROR: Error fetching plugin information: %s', 'woocommerce-conversion-tracking'), esc_attr( $api->get_error_message() )));
        }

        add_filter( 'upgrader_package_options', function ( $options ) {
            $options['clear_destination'] = true;
            $options['hook_extra'] = [
                'type' => 'plugin',
                'action' => 'install',
                'plugin'  => 'happy-elementor-addons/plugin.php',
            ];
            return  $options;
        });

        $result   = $upgrader->install( $api->download_link );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result );
        }

        $result = activate_plugin( 'happy-elementor-addons/plugin.php' );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result );
        }
        wp_send_json_success([
            'message' => __( 'Successfully installed and activate,', 'woocommerce-conversion-tracking' )
        ]);
    }

    /**
     * Dismissable notice
     *
     * @return object
     */
    public function wcct_dismissable_notice() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error(
                [
                    'message' => __( 'You do not have permission to install Happy Addons', 'woocommerce-conversion-tracking' )
                ]
            );
        }

        if ( ! isset( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_REQUEST['_wpnonce'] ) ), 'wcct-settings' ) ) {
            wp_send_json_error(
                [
                    'message' => __( 'nonce verification failed', 'woocommerce-conversion-tracking' )
                ]
            );
        }

        update_option( 'wcct_dismissable_notice', 'closed' );

        wp_send_json_success();
    }
}
