<?php
/**
 * Sample Tracking Doctor checks for the e2e site, loaded by ct-e2e-helpers.php when the ct_e2e_doctor cookie is set.
 * WordPress loads only the PHP files in the root of mu-plugins, so this subfolder file runs only when required.
 */

// phpcs:disable

use StorePulse\ConversionTracking\Doctor\AbstractCheck;
use StorePulse\ConversionTracking\Doctor\Context;
use StorePulse\ConversionTracking\Doctor\Finding;

class CT_E2E_Doctor_Check extends AbstractCheck {

    public function id() {
        return 'e2e-duplicate';
    }

    public function label() {
        return 'No e2e pixel plugin is active';
    }

    public function run( Context $context ) {
        $plugin = isset( $_COOKIE['ct_e2e_doctor'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['ct_e2e_doctor'] ) ) : '';

        if ( '' === $plugin || 'none' === $plugin ) {
            return array();
        }

        return array(
            $this->finding(
                array(
                    'found'    => sprintf( '%s is also active', $plugin ),
                    'why'      => 'If its pixel is on, each sale reaches Meta twice.',
                    'label'    => 'Duplicate',
                    'tags'     => array( 'Meta' ),
                    'steps'    => array( 'Pick one plugin to send to Meta.', 'Then check again.' ),
                    'action'   => array( 'type' => 'recheck', 'label' => 'Check again' ),
                    'evidence' => array( 'plugin' => $plugin ),
                )
            ),
        );
    }
}

class CT_E2E_Doctor_Confirm_Check extends AbstractCheck {

    public function id() {
        return 'e2e-confirm';
    }

    public function label() {
        return 'No e2e tag manager is active';
    }

    public function run( Context $context ) {
        return array(
            $this->finding(
                array(
                    'found'      => 'An e2e tag manager is active',
                    'why'        => 'If its container also has a purchase tag, Google counts each sale twice.',
                    'confidence' => Finding::UNSURE,
                    'tags'       => array( 'Google' ),
                    'action'     => array(
                        'label'   => 'Open its settings',
                        'url'     => admin_url( 'plugins.php' ),
                        'confirm' => 'This opens another plugin. Nothing changes until you save there.',
                    ),
                    'evidence'   => array( 'gtm' => true ),
                )
            ),
        );
    }
}
