<?php

namespace StorePulse\ConversionTracking\Settings;

use InvalidArgumentException;
use StorePulse\ConversionTracking\Access\CustomScripts;
use StorePulse\ConversionTracking\Platforms\Pause;
use StorePulse\ConversionTracking\Platforms\PlatformSettings;
use StorePulse\ConversionTracking\Platforms\XSettings;
use StorePulse\ConversionTracking\Privacy\CustomerDetails;

/**
 * Turns the stored settings into the API's values and applies a save to them.
 */
class Values {

    /**
     * Every field's value, the default where nothing is stored.
     *
     * @param array $schema Normalized schema.
     * @param array $doc    Stored settings: global field values plus { platforms: id => values }.
     *
     * @return array Global values plus { platforms: id => values + connected + paused + needs_resetup + credentials }.
     */
    public static function get( array $schema, array $doc ): array {
        $values = self::fill( Schema::fields( $schema['global'] ), $doc );

        $values['platforms'] = array();

        foreach ( $schema['platforms'] as $id => $platform ) {
            $stored = self::stored( $doc, $id );

            $values['platforms'][ $id ] = self::fill( Schema::fields( $platform['schema'] ), $stored );

            // Shown as the storefront reads it: without a stored value, the 2.x default.
            if ( CustomerDetails::PLATFORM === $id && isset( $values['platforms'][ $id ][ CustomerDetails::SETTING ] ) && ! array_key_exists( CustomerDetails::SETTING, $stored ) ) {
                $values['platforms'][ $id ][ CustomerDetails::SETTING ] = CustomerDetails::v1_default();
            }

            // Read-only: whether it has the IDs it needs (PlatformSettings::connected()), the one rule for the storefront, the app and the Doctor.
            $values['platforms'][ $id ]['connected'] = PlatformSettings::connected( (string) $id, $platform['schema'], $values['platforms'][ $id ] );

            // Read-only here; Pause owns it (settings/pause). A 2.x integration that was switched off reads as paused.
            $values['platforms'][ $id ]['paused'] = Pause::is_platform_paused( (string) $id, $doc );

            // Read-only: events 2.x sent that need a new event ID (X); input for What's new (#124) and the migration (#122).
            $values['platforms'][ $id ]['needs_resetup'] = self::needs_resetup( $id, $stored );

            // Reserved for tokens in 3.1; never returns a secret.
            $values['platforms'][ $id ]['credentials'] = new \stdClass();
        }

        return $values;
    }

    /**
     * Apply posted values to the stored settings.
     *
     * Only the keys posted change; unknown platforms and keys are ignored, and stored keys
     * the schema doesn't list (say, Pro's while Pro is off) are kept.
     *
     * @param array    $schema Normalized schema.
     * @param array    $doc    Stored settings.
     * @param mixed    $input  Posted global values plus { platforms: id => values }.
     * @param callable $can    Receives a capability, returns whether the current user has it.
     *
     * @return array { doc: new settings, errors: key => message, forbidden: keys the user may not change }
     */
    public static function apply( array $schema, array $doc, $input, callable $can ): array {
        $input  = is_array( $input ) ? $input : array();
        $result = array(
            'doc'       => $doc,
            'errors'    => array(),
            'forbidden' => array(),
        );

        $global = self::apply_fields( Schema::fields( $schema['global'] ), $doc, $input, '', $can );

        $result['doc']       = array_merge( $doc, $global['values'] );
        $result['errors']    = $global['errors'];
        $result['forbidden'] = $global['forbidden'];

        if ( ! isset( $result['doc']['platforms'] ) || ! is_array( $result['doc']['platforms'] ) ) {
            $result['doc']['platforms'] = array();
        }

        if ( ! array_key_exists( 'platforms', $input ) ) {
            return $result;
        }

        if ( ! is_array( $input['platforms'] ) ) {
            $result['errors']['platforms'] = __( 'Must be an object keyed by platform.', 'woocommerce-conversion-tracking' );

            return $result;
        }

        foreach ( $input['platforms'] as $id => $posted ) {
            if ( ! isset( $schema['platforms'][ $id ] ) ) {
                continue;
            }

            $key = 'platforms.' . $id;

            if ( ! is_array( $posted ) ) {
                $result['errors'][ $key ] = __( 'Must be an object.', 'woocommerce-conversion-tracking' );
                continue;
            }

            $stored  = self::stored( $doc, $id );
            $applied = self::apply_fields( Schema::fields( $schema['platforms'][ $id ]['schema'] ), $stored, $posted, $key . '.', $can );

            $result['doc']['platforms'][ $id ] = array_merge( $stored, $applied['values'] );
            $result['errors']                  = array_merge( $result['errors'], $applied['errors'] );
            $result['forbidden']               = array_merge( $result['forbidden'], $applied['forbidden'] );
        }

        return $result;
    }

    /**
     * Clean the posted values of one field list.
     *
     * @param array    $fields Fields keyed by ID.
     * @param array    $stored Stored values.
     * @param array    $posted Posted values.
     * @param string   $prefix Error key prefix.
     * @param callable $can    Capability check.
     *
     * @return array { values, errors, forbidden }
     */
    private static function apply_fields( array $fields, array $stored, array $posted, string $prefix, callable $can ): array {
        $out = array(
            'values'    => array(),
            'errors'    => array(),
            'forbidden' => array(),
        );

        foreach ( $fields as $id => $field ) {
            if ( ! array_key_exists( $id, $posted ) ) {
                continue;
            }

            // Locked fields (Pro fields on Free) keep what is stored.
            if ( ! empty( $field['disabled'] ) ) {
                continue;
            }

            $current = array_key_exists( $id, $stored ) ? $stored[ $id ] : Schema::default_value( $field );

            try {
                $value = Sanitizer::field( $field, $posted[ $id ], $current );
            } catch ( InvalidArgumentException $e ) {
                $out['errors'][ $prefix . $id ] = $e->getMessage();
                continue;
            }

            // Fields such as custom scripts need a capability beyond manage_wcct; unchanged values pass.
            if ( ! empty( $field['capability'] ) && ! call_user_func( $can, $field['capability'] ) ) {
                if ( CustomScripts::differs( $value, $current ) ) {
                    $out['forbidden'][] = $prefix . $id;
                }

                continue;
            }

            $out['values'][ $id ] = $value;
        }

        return $out;
    }

    /**
     * Values for the fields, stored or default.
     *
     * @param array $fields Fields keyed by ID.
     * @param array $stored Stored values.
     *
     * @return array
     */
    private static function fill( array $fields, array $stored ): array {
        $values = array();

        foreach ( $fields as $id => $field ) {
            $values[ $id ] = array_key_exists( $id, $stored ) ? $stored[ $id ] : Schema::default_value( $field );
        }

        return $values;
    }

    /**
     * Events of a platform flagged for a re-setup.
     *
     * @param string $id     Platform ID.
     * @param array  $stored Stored values.
     *
     * @return string[]
     */
    private static function needs_resetup( string $id, array $stored ): array {
        if ( 'twitter' === $id ) {
            return XSettings::from_v3( $stored )['needs_resetup'];
        }

        return isset( $stored['needs_resetup'] ) && is_array( $stored['needs_resetup'] ) ? array_values( array_filter( $stored['needs_resetup'], 'is_string' ) ) : array();
    }

    /**
     * A platform's stored values.
     *
     * @param array  $doc Stored settings.
     * @param string $id  Platform ID.
     *
     * @return array
     */
    private static function stored( array $doc, string $id ): array {
        return isset( $doc['platforms'][ $id ] ) && is_array( $doc['platforms'][ $id ] ) ? $doc['platforms'][ $id ] : array();
    }
}
