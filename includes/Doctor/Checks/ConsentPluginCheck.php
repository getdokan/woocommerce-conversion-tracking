<?php

namespace StorePulse\ConversionTracking\Doctor\Checks;

use StorePulse\ConversionTracking\Consent\ConsentMode;
use StorePulse\ConversionTracking\Doctor\AbstractCheck;
use StorePulse\ConversionTracking\Doctor\Context;
use StorePulse\ConversionTracking\Doctor\Finding;

/**
 * "Respect cookie choices" is on, but no consent plugin sets a consent type: tags wait for a choice that never comes, or run regardless.
 */
class ConsentPluginCheck extends AbstractCheck {

    /**
     * Where the merchant finds cookie plugins that work with the WP Consent API.
     */
    const PLUGINS_URL = 'https://wordpress.org/plugins/wp-consent-api/';

    /**
     * Working line, set by run().
     *
     * @var string
     */
    private $working = '';

    /**
     * Check ID.
     *
     * @return string
     */
    public function id() {
        return 'consent-plugin';
    }

    /**
     * Working line: names the tool that handles consent when one does.
     *
     * @return string
     */
    public function label() {
        return '' !== $this->working ? $this->working : __( 'Cookie choices reach your tags through your consent plugin', 'woocommerce-conversion-tracking' );
    }

    /**
     * A finding while the switch is on and no consent plugin is connected.
     *
     * @param Context $context Run context.
     *
     * @return Finding[]
     */
    public function run( Context $context ) {
        $this->working = '';

        if ( ! ConsentMode::is_enabled() ) {
            $this->working = __( 'Respect cookie choices is off, so no tag waits for a cookie choice', 'woocommerce-conversion-tracking' );

            return array();
        }

        $status = ConsentMode::status();

        if ( '' !== $status['message'] ) {
            $this->working = $status['message'];
        }

        $api = ConsentMode::api();

        if ( '' !== $api->get_consent_type() || ConsentMode::consent_tool_active() ) {
            return array();
        }

        // Tags hold for a choice only when the page expects a consent type (the runtime's typeExpected rule); else they run.
        $available = $api->is_available();
        $waits     = $available && has_filter( 'wp_get_consent_type' );

        return array(
            $this->finding(
                array(
                    'found'      => __( 'Cookie choices are on, but no consent plugin is connected', 'woocommerce-conversion-tracking' ),
                    'why'        => $waits
                        ? __( 'Your tags wait for a cookie choice that never comes, so those visitors\' sales aren\'t tracked.', 'woocommerce-conversion-tracking' )
                        : __( 'Nothing tells your tags what visitors chose, so they run as if everyone agreed.', 'woocommerce-conversion-tracking' ),
                    'label'      => __( 'Cookie choices', 'woocommerce-conversion-tracking' ),
                    'tags'       => array( __( 'All platforms', 'woocommerce-conversion-tracking' ), __( 'Europe', 'woocommerce-conversion-tracking' ) ),
                    'confidence' => Finding::SURE,
                    'steps'      => array(
                        __( 'Install a cookie plugin that supports the WP Consent API, such as Complianz, CookieYes or Cookiebot.', 'woocommerce-conversion-tracking' ),
                        __( 'If you don\'t sell to Europe, you can turn off Respect cookie choices in Platforms instead.', 'woocommerce-conversion-tracking' ),
                    ),
                    'action'     => array(
                        'label'    => __( 'See compatible plugins', 'woocommerce-conversion-tracking' ),
                        'url'      => self::PLUGINS_URL,
                        'external' => true,
                    ),
                    'evidence'   => array(
                        'consent_mode'  => 'on',
                        'consent_type'  => '',
                        'api_available' => $available,
                        'waits'         => $waits,
                    ),
                )
            ),
        );
    }
}
