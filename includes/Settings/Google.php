<?php

namespace StorePulse\ConversionTracking\Settings;

/**
 * Maps the one google platform to the ga4 and adwords integrations and back.
 */
class Google {

    const MODE_ANALYTICS = 'analytics';
    const MODE_ADS       = 'ads';
    const MODE_BOTH      = 'both';

    /**
     * Platform events the ga4 integration sends.
     */
    const ANALYTICS_EVENTS = array( 'view_product', 'add_to_cart', 'begin_checkout', 'purchase', 'search', 'sign_up' );

    /**
     * Platform events the adwords integration sends as conversions.
     */
    const ADS_EVENTS = array( 'purchase', 'sign_up' );

    /**
     * 2.x ga4 event keys => platform events; search and sign_up are only saved by 3.0's 2.x-style settings page.
     */
    const V1_ANALYTICS_EVENTS = array(
        'view_item'      => 'view_product',
        'add_to_cart'    => 'add_to_cart',
        'begin_checkout' => 'begin_checkout',
        'purchase'       => 'purchase',
        'search'         => 'search',
        'sign_up'        => 'sign_up',
    );

    /**
     * 2.x adwords event keys => platform events.
     */
    const V1_ADS_EVENTS = array(
        'Purchase'     => 'purchase',
        'Registration' => 'sign_up',
    );

    /**
     * The settings of the ga4 and adwords integrations for one google platform.
     *
     * @param array $google Google platform values.
     *
     * @return array { ga4: { enabled, measurement_id, tag_id, events }, adwords: { enabled, account_id, purchase_label, registration_label, events } }
     */
    public static function integrations( array $google ): array {
        $mode   = self::mode( $google );
        $events = isset( $google['events'] ) && is_array( $google['events'] ) ? array_values( $google['events'] ) : array();

        $measurement_id = self::text( $google, 'measurement_id' );
        $ads_id         = self::text( $google, 'ads_id' );

        return array(
            'ga4'     => array(
                'enabled'        => self::MODE_ADS !== $mode && '' !== $measurement_id,
                'measurement_id' => $measurement_id,
                'tag_id'         => self::text( $google, 'tag_id' ),
                'events'         => array_values( array_intersect( $events, self::ANALYTICS_EVENTS ) ),
            ),
            'adwords' => array(
                'enabled'            => self::MODE_ANALYTICS !== $mode && '' !== $ads_id,
                'account_id'         => $ads_id,
                'purchase_label'     => self::text( $google, 'purchase_label' ),
                'registration_label' => self::text( $google, 'registration_label' ),
                'events'             => array_values( array_intersect( $events, self::ADS_EVENTS ) ),
            ),
        );
    }

    /**
     * The google platform built from the 2.x ga4 and adwords settings.
     *
     * @param mixed $ga4     2.x ga4 settings: { enabled, 0: { measurement_id, events } }.
     * @param mixed $adwords 2.x adwords settings: { enabled, 0: { account_id, events: { Purchase, Purchase-label, ... } } }.
     *
     * @return array Empty when neither integration was ever saved; paused when IDs are saved but both were switched off.
     */
    public static function from_v1( $ga4, $adwords ): array {
        $ga4     = is_array( $ga4 ) ? $ga4 : array();
        $adwords = is_array( $adwords ) ? $adwords : array();

        if ( ! $ga4 && ! $adwords ) {
            return array();
        }

        $analytics = isset( $ga4[0] ) && is_array( $ga4[0] ) ? $ga4[0] : array();
        $ads       = isset( $adwords[0] ) && is_array( $adwords[0] ) ? $adwords[0] : array();
        $ads_keys  = isset( $ads['events'] ) && is_array( $ads['events'] ) ? $ads['events'] : array();

        $ads_id = self::text( $ads, 'account_id' );
        $split  = self::split_ads_id( $ads_id );
        $label  = self::label( self::text( $ads_keys, 'Purchase-label' ) );

        // 2.x stores often pasted "AW-123/label" into the ID field; the label field wins when it has one.
        $google = array(
            'measurement_id' => self::text( $analytics, 'measurement_id' ),
            'ads_id'         => $split ? $split[0] : $ads_id,
            'purchase_label' => '' === $label && $split ? $split[1] : $label,
        );

        // Pro 1.x saved it lower-case and read it capitalised.
        $registration_label = self::label( self::text( $ads_keys, 'registration-label' ) );
        $registration_label = '' !== $registration_label ? $registration_label : self::label( self::text( $ads_keys, 'Registration-label' ) );

        if ( '' !== $registration_label ) {
            $google['registration_label'] = $registration_label;
        }

        $google['mode']   = self::mode_from_v1( $ga4, $adwords, $google );
        $google['paused'] = ( '' !== $google['measurement_id'] || '' !== $google['ads_id'] ) && ! self::enabled( $ga4 ) && ! self::enabled( $adwords );
        $google['events'] = array_values(
            array_unique(
                array_merge(
                    self::v1_events( $analytics, self::V1_ANALYTICS_EVENTS ),
                    self::v1_events( $ads, self::V1_ADS_EVENTS )
                )
            )
        );

        return $google;
    }

    /**
     * An Ads ID saved with a conversion label ("AW-123/label") as [ ID, label ]; null for anything else.
     *
     * @param string $value Saved value.
     *
     * @return array|null
     */
    public static function split_ads_id( string $value ): ?array {
        if ( ! preg_match( '#^(AW-[0-9]+)\s*/\s*([A-Za-z0-9_-]+)$#i', trim( $value ), $match ) ) {
            return null;
        }

        return array( strtoupper( $match[1] ), $match[2] );
    }

    /**
     * A saved conversion label, without an "AW-123/" prefix pasted in front of it.
     *
     * @param string $value Saved value.
     *
     * @return string
     */
    private static function label( string $value ): string {
        $split = self::split_ads_id( $value );

        return $split ? $split[1] : $value;
    }

    /**
     * The saved mode, Both when missing or unknown.
     *
     * @param array $google Google platform values.
     *
     * @return string
     */
    public static function mode( array $google ): string {
        $mode = isset( $google['mode'] ) ? $google['mode'] : '';

        return in_array( $mode, array( self::MODE_ANALYTICS, self::MODE_ADS, self::MODE_BOTH ), true ) ? $mode : self::MODE_BOTH;
    }

    /**
     * Mode from the enabled 2.x integrations, or from the saved IDs when none is enabled.
     *
     * @param array $ga4     2.x ga4 settings.
     * @param array $adwords 2.x adwords settings.
     * @param array $google  IDs already read.
     *
     * @return string
     */
    private static function mode_from_v1( array $ga4, array $adwords, array $google ): string {
        $has_analytics = '' !== $google['measurement_id'];
        $has_ads       = '' !== $google['ads_id'];

        $analytics = $has_analytics && self::enabled( $ga4 );
        $ads       = $has_ads && self::enabled( $adwords );

        if ( ! $analytics && ! $ads ) {
            $analytics = $has_analytics;
            $ads       = $has_ads;
        }

        if ( $analytics && ! $ads ) {
            return self::MODE_ANALYTICS;
        }

        return $ads && ! $analytics ? self::MODE_ADS : self::MODE_BOTH;
    }

    /**
     * Whether a 2.x integration was switched on, as the 2.x storefront read it.
     *
     * @param array $integration 2.x settings.
     *
     * @return bool
     */
    public static function enabled( array $integration ): bool {
        $enabled = isset( $integration['enabled'] ) ? $integration['enabled'] : false;

        // As 2.x read it (wc_string_to_bool()), so 'on' is off.
        return true === $enabled || 1 === $enabled || ( is_string( $enabled ) && in_array( strtolower( $enabled ), array( 'yes', 'true', '1' ), true ) );
    }

    /**
     * Platform events switched on in a 2.x integration.
     *
     * @param array $settings 2.x index-0 settings.
     * @param array $map      2.x key => platform event.
     *
     * @return array
     */
    public static function v1_events( array $settings, array $map ): array {
        $saved  = isset( $settings['events'] ) && is_array( $settings['events'] ) ? $settings['events'] : array();
        $events = array();

        foreach ( $map as $key => $event ) {
            if ( isset( $saved[ $key ] ) && 'on' === $saved[ $key ] ) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * A string value, or an empty string.
     *
     * @param array  $values Values.
     * @param string $key    Key.
     *
     * @return string
     */
    private static function text( array $values, string $key ): string {
        return isset( $values[ $key ] ) && is_scalar( $values[ $key ] ) ? trim( (string) $values[ $key ] ) : '';
    }
}
