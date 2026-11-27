<?php

namespace StorePulse\ConversionTracking\Doctor\Checks;

use StorePulse\ConversionTracking\Doctor\AbstractCheck;
use StorePulse\ConversionTracking\Doctor\Context;
use StorePulse\ConversionTracking\Doctor\Finding;
use StorePulse\ConversionTracking\Doctor\Platforms;

/**
 * A connected platform has Purchase off, so it never hears about a sale.
 */
class PurchaseOnCheck extends AbstractCheck {

    /**
     * Check ID.
     *
     * @return string
     */
    public function id() {
        return 'purchase-on';
    }

    /**
     * Working line.
     *
     * @return string
     */
    public function label() {
        return __( 'Purchase is on for every connected platform', 'woocommerce-conversion-tracking' );
    }

    /**
     * A finding per connected, unpaused platform with Purchase off.
     *
     * @param Context $context Run context.
     *
     * @return Finding[]
     */
    public function run( Context $context ) {
        return $this->findings( Platforms::from_context( $context ) );
    }

    /**
     * Findings for some platform states.
     *
     * @param array $states Platform states.
     *
     * @return Finding[]
     */
    public function findings( array $states ) {
        $findings = array();

        foreach ( $states as $state ) {
            if ( ! $state['sends'] || ! self::offers_purchase( $state['schema'] ) ) {
                continue;
            }

            $events = isset( $state['values']['events'] ) && is_array( $state['values']['events'] ) ? $state['values']['events'] : array();

            if ( in_array( 'purchase', $events, true ) ) {
                continue;
            }

            $platform   = $state['label'];
            $findings[] = $this->finding(
                array(
                    'key'        => $state['id'],
                    /* translators: %s: platform name, for example Meta */
                    'found'      => sprintf( __( 'Purchase is off for %s', 'woocommerce-conversion-tracking' ), $platform ),
                    /* translators: %s: platform name */
                    'why'        => sprintf( __( '%s doesn\'t hear about your sales, so it can\'t count conversions or optimize your ads for purchases.', 'woocommerce-conversion-tracking' ), $platform ),
                    'label'      => __( 'Events', 'woocommerce-conversion-tracking' ),
                    'tags'       => array( $platform ),
                    'confidence' => Finding::SURE,
                    'action'     => array(
                        /* translators: %s: platform name */
                        'label' => sprintf( __( 'Open %s', 'woocommerce-conversion-tracking' ), $platform ),
                        'url'   => '#/platforms/' . $state['id'] . '?field=events',
                    ),
                    'evidence'   => array(
                        'platform' => $state['id'],
                        'purchase' => 'off',
                    ),
                )
            );
        }

        return $findings;
    }

    /**
     * Whether the platform's events list has a Purchase the merchant can switch.
     *
     * @param array $schema Platform schema elements.
     *
     * @return bool
     */
    private static function offers_purchase( array $schema ) {
        $field = Platforms::events_field( $schema );

        foreach ( $field && isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array() as $option ) {
            if ( is_array( $option ) && isset( $option['value'] ) && 'purchase' === $option['value'] && empty( $option['locked'] ) && empty( $option['always'] ) ) {
                return true;
            }
        }

        return false;
    }
}
