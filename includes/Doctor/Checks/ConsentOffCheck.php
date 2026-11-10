<?php

namespace StorePulse\ConversionTracking\Doctor\Checks;

use StorePulse\ConversionTracking\Consent\ConsentMode;
use StorePulse\ConversionTracking\Doctor\AbstractCheck;
use StorePulse\ConversionTracking\Doctor\Context;
use StorePulse\ConversionTracking\Doctor\Finding;

/**
 * Good to know: "Respect cookie choices" is off. Links to the switch; never turns it on.
 */
class ConsentOffCheck extends AbstractCheck {

    /**
     * Check ID.
     *
     * @return string
     */
    public function id() {
        return 'consent-off';
    }

    /**
     * Working line.
     *
     * @return string
     */
    public function label() {
        return __( 'Respect cookie choices is on', 'woocommerce-conversion-tracking' );
    }

    /**
     * A note while the switch is off.
     *
     * @param Context $context Run context.
     *
     * @return Finding[]
     */
    public function run( Context $context ) {
        if ( ConsentMode::is_enabled() ) {
            return array();
        }

        return array(
            $this->finding(
                array(
                    'found'      => __( 'Consent mode is off', 'woocommerce-conversion-tracking' ),
                    'why'        => __( 'Your tags load without waiting for a cookie choice. If you sell to Europe, turn on Respect cookie choices so your cookie plugin decides what runs.', 'woocommerce-conversion-tracking' ),
                    'label'      => __( 'Cookie choices', 'woocommerce-conversion-tracking' ),
                    'tags'       => array( __( 'All platforms', 'woocommerce-conversion-tracking' ) ),
                    'severity'   => Finding::INFO,
                    'confidence' => Finding::SURE,
                    'action'     => array(
                        'label' => __( 'Open the setting', 'woocommerce-conversion-tracking' ),
                        'url'   => '#/platforms/facebook?field=consent_mode',
                    ),
                    'evidence'   => array( 'consent_mode' => 'off' ),
                )
            ),
        );
    }
}
