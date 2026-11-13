<?php

namespace StorePulse\ConversionTracking\Doctor\Checks;

use StorePulse\ConversionTracking\Consent\ConsentData;
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

        $api       = ConsentMode::api();
        $available = $api->is_available();

        if ( '' !== $api->get_consent_type() || ( $available && ConsentMode::consent_tool_active() ) ) {
            return array();
        }

        $silent = ConsentMode::silent_tools();

        if ( array() !== $silent ) {
            return array( $this->silent_tool_finding( $silent, $available ) );
        }

        // No type is expected here (the runtime's typeExpected rule), so tags never wait; they run.
        return array(
            $this->finding(
                array(
                    'found'      => __( 'Cookie choices are on, but no consent plugin is connected', 'woocommerce-conversion-tracking' ),
                    'why'        => __( 'Nothing tells your tags what visitors chose, so they run as if everyone agreed.', 'woocommerce-conversion-tracking' ),
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
                        // Kept so findings dismissed before stay dismissed.
                        'waits'         => false,
                    ),
                )
            ),
        );
    }

    /**
     * Warning for consent tools that are active but report no consent type, so our defaults apply.
     *
     * @param string[] $tools     Tool slugs.
     * @param bool     $available Whether the WP Consent API is active.
     *
     * @return Finding
     */
    private function silent_tool_finding( array $tools, $available ) {
        $names = implode( ', ', array_map( array( ConsentData::class, 'source_name' ), $tools ) );

        return $this->finding(
            array(
                'found'      => __( 'Consent tool detected but not reporting', 'woocommerce-conversion-tracking' ),
                'why'        => sprintf(
                    /* translators: %s: consent tool names, for example Real Cookie Banner */
                    __( '%s is active but doesn\'t pass visitors\' cookie choices to the WP Consent API, so Conversion Tracking can\'t see them and uses its own defaults: Google tags follow consent mode, and your other tags run as if everyone agreed.', 'woocommerce-conversion-tracking' ),
                    $names
                ),
                'label'      => __( 'Cookie choices', 'woocommerce-conversion-tracking' ),
                'tags'       => array( __( 'All platforms', 'woocommerce-conversion-tracking' ), __( 'Europe', 'woocommerce-conversion-tracking' ) ),
                'confidence' => Finding::SURE,
                'steps'      => array(
                    sprintf(
                        $available
                            /* translators: %s: consent tool names */
                            ? __( 'Connect %s to the WP Consent API in its settings.', 'woocommerce-conversion-tracking' )
                            /* translators: %s: consent tool names */
                            : __( 'Install the WP Consent API plugin and connect %s to it.', 'woocommerce-conversion-tracking' ),
                        $names
                    ),
                    sprintf(
                        /* translators: %s: consent tool names */
                        __( 'Or turn on Google consent mode in %s, so it tells Google tags what each visitor chose.', 'woocommerce-conversion-tracking' ),
                        $names
                    ),
                ),
                'action'     => array(
                    'label'    => __( 'See compatible plugins', 'woocommerce-conversion-tracking' ),
                    'url'      => self::PLUGINS_URL,
                    'external' => true,
                ),
                'evidence'   => array(
                    'consent_mode'  => 'on',
                    'consent_type'  => '',
                    'api_available' => (bool) $available,
                    'tools'         => $tools,
                ),
            )
        );
    }
}
