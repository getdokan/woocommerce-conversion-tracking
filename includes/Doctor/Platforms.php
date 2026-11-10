<?php

namespace StorePulse\ConversionTracking\Doctor;

use StorePulse\ConversionTracking\Settings\Google;
use StorePulse\ConversionTracking\Settings\Schema;
use StorePulse\ConversionTracking\Settings\Values;

/**
 * What the checks need to know about each platform: its name, values, whether it is connected and whether it sends.
 */
class Platforms {

    /**
     * Platforms from the settings schema and the saved values.
     *
     * @param Context $context Run context.
     *
     * @return array[] See build().
     */
    public static function from_context( Context $context ) {
        $schema = Schema::get();
        $values = Values::get( $schema, $context->settings() );

        return self::build( $schema['platforms'], $values['platforms'], array( $context, 'is_paused' ) );
    }

    /**
     * Platform states keyed by settings platform ID.
     *
     * @param array    $platforms Normalized schema platforms.
     * @param array    $values    Values keyed by platform ID (Values::get()).
     * @param callable $is_paused Receives a platform or integration ID, returns whether it is paused (Pause::is_paused()).
     *
     * @return array[] { id, label, integrations, schema, values, connected, paused, sends }.
     */
    public static function build( array $platforms, array $values, callable $is_paused ) {
        $states = array();

        foreach ( $platforms as $id => $platform ) {
            $id           = (string) $id;
            $own          = isset( $values[ $id ] ) && is_array( $values[ $id ] ) ? $values[ $id ] : array();
            $schema       = isset( $platform['schema'] ) && is_array( $platform['schema'] ) ? $platform['schema'] : array();
            $integrations = isset( $platform['integrations'] ) && is_array( $platform['integrations'] ) ? array_values( array_map( 'strval', $platform['integrations'] ) ) : array( $id );
            $connected    = self::connected( $schema, $own );
            $relevant     = self::sending_integrations( $id, $integrations, $own );
            $paused       = ! empty( $own['paused'] ) || call_user_func( $is_paused, $id ) || count( array_filter( $relevant, $is_paused ) ) === count( $relevant );

            $states[ $id ] = array(
                'id'           => $id,
                'label'        => isset( $platform['label'] ) && is_string( $platform['label'] ) ? $platform['label'] : $id,
                'integrations' => $integrations,
                'schema'       => $schema,
                'values'       => $own,
                'connected'    => $connected,
                'paused'       => $connected && $paused,
                'sends'        => $connected && ! $paused,
            );
        }

        return $states;
    }

    /**
     * The integrations a platform sends through: Google's ga4 and adwords only where its mode and IDs use them.
     *
     * @param string   $id           Settings platform ID.
     * @param string[] $integrations Its integrations.
     * @param array    $values       Its values.
     *
     * @return string[] Never empty.
     */
    private static function sending_integrations( $id, array $integrations, array $values ) {
        if ( 'google' !== $id ) {
            return $integrations ? $integrations : array( $id );
        }

        $enabled = array_keys(
            array_filter(
                Google::integrations( $values ),
                static function ( $integration ) {
                    return ! empty( $integration['enabled'] );
                }
            )
        );

        return $enabled ? $enabled : ( $integrations ? $integrations : array( $id ) );
    }

    /**
     * Whether a field applies, given the other values (schema dependencies).
     *
     * @param array $field  Field.
     * @param array $values Platform values.
     *
     * @return bool
     */
    public static function applies( array $field, array $values ) {
        foreach ( isset( $field['dependencies'] ) && is_array( $field['dependencies'] ) ? $field['dependencies'] : array() as $dependency ) {
            if ( ! is_array( $dependency ) || ! isset( $dependency['key'], $dependency['value'] ) || ! is_array( $dependency['value'] ) ) {
                continue;
            }

            $current = isset( $values[ $dependency['key'] ] ) ? $values[ $dependency['key'] ] : null;

            if ( ! in_array( $current, $dependency['value'], true ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a platform has what it needs to send: an ID, or an enabled custom script (as the admin app's isConnected()).
     *
     * @param array $schema Platform schema elements.
     * @param array $values Platform values.
     *
     * @return bool
     */
    public static function connected( array $schema, array $values ) {
        foreach ( $schema as $field ) {
            if ( ! is_array( $field ) || ! isset( $field['type'], $field['id'] ) || 'field' !== $field['type'] || ! self::applies( $field, $values ) ) {
                continue;
            }

            if ( isset( $field['format'] ) && 'id' === $field['format'] && self::filled( $values, $field['id'] ) ) {
                return true;
            }

            $id = (string) $field['id'];

            if ( isset( $field['variant'] ) && 'switch' === $field['variant'] && '_enabled' === substr( $id, -8 ) && isset( $values[ $id ] ) && true === $values[ $id ] &&self::filled( $values, substr( $id, 0, -8 ) . '_script' ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * The events field of a platform, or null.
     *
     * @param array $schema Platform schema elements.
     *
     * @return array|null
     */
    public static function events_field( array $schema ) {
        foreach ( $schema as $field ) {
            if ( is_array( $field ) && isset( $field['type'], $field['id'], $field['variant'] ) && 'field' === $field['type'] && 'events' === $field['id'] && 'multicheck' === $field['variant'] ) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Platform name for an integration ID (ga4 and adwords are Google).
     *
     * @param array  $states      Platform states.
     * @param string $integration Integration ID.
     *
     * @return string
     */
    public static function label_for( array $states, $integration ) {
        foreach ( $states as $state ) {
            if ( $state['id'] === $integration || in_array( $integration, $state['integrations'], true ) ) {
                return $state['label'];
            }
        }

        return (string) $integration;
    }

    /**
     * Whether a value is a non-empty string.
     *
     * @param array  $values Values.
     * @param string $key    Key.
     *
     * @return bool
     */
    public static function filled( array $values, $key ) {
        return isset( $values[ $key ] ) && is_string( $values[ $key ] ) && '' !== trim( $values[ $key ] );
    }
}
