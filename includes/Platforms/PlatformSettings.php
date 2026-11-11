<?php

namespace StorePulse\ConversionTracking\Platforms;

use StorePulse\ConversionTracking\Privacy\CustomerDetails;
use StorePulse\ConversionTracking\Settings\Google;
use StorePulse\ConversionTracking\Settings\Schema;
use StorePulse\ConversionTracking\Settings\Store;

/**
 * The storefront's reader of wcct_settings_v3, and the one rule for whether a platform is connected (has the IDs it needs).
 */
class PlatformSettings {

    /**
     * Schema key marking an ID field the platform can't send without.
     */
    const REQUIRED_KEY = 'required_to_send';

    /**
     * Filter: whether a platform is connected, after the schema's rule.
     */
    const CONNECTED_FILTER = 'wcct_platform_connected';

    /**
     * The wcct_settings_v3 option for this request (null while it doesn't exist), once read.
     *
     * @var array|null
     */
    private static $doc = null;

    /**
     * Whether $doc was read in this request.
     *
     * @var bool
     */
    private static $read = false;

    /**
     * Platform ID => its required ID fields, null until read.
     *
     * @var array|null
     */
    private static $required = null;

    /**
     * Platform ID => its fields keyed by ID, null until read.
     *
     * @var array|null
     */
    private static $fields = null;

    /**
     * Whether the option hooks that forget $doc are added.
     *
     * @var bool
     */
    private static $hooked = false;

    /**
     * A platform's values in wcct_settings_v3, empty when it has none; null while the option doesn't exist (integrations then read the 2.x settings).
     *
     * @param string $platform Settings platform ID.
     *
     * @return array|null
     */
    public static function values( string $platform ): ?array {
        $doc = self::doc();

        if ( null === $doc ) {
            return null;
        }

        return self::with_defaults( $platform, isset( $doc['platforms'][ $platform ] ) && is_array( $doc['platforms'][ $platform ] ) ? $doc['platforms'][ $platform ] : array() );
    }

    /**
     * Stored values plus the schema default of every field not stored, as the settings page shows them; stored keys the schema doesn't list are kept.
     *
     * @param string $platform Settings platform ID.
     * @param array  $stored   The platform's stored values.
     *
     * @return array
     */
    public static function with_defaults( string $platform, array $stored ): array {
        if ( null === self::$fields ) {
            self::$fields = array();

            foreach ( Schema::get()['platforms'] as $id => $schema ) {
                self::$fields[ (string) $id ] = Schema::fields( $schema['schema'] );
            }
        }

        foreach ( isset( self::$fields[ $platform ] ) ? self::$fields[ $platform ] : array() as $id => $field ) {
            // Customer details keep their own 2.x default when not stored (CustomerDetails::v1_default()).
            if ( ! array_key_exists( $id, $stored ) && ! ( CustomerDetails::PLATFORM === $platform && CustomerDetails::SETTING === $id ) ) {
                $stored[ $id ] = Schema::default_value( $field );
            }
        }

        return $stored;
    }

    /**
     * The ID fields the schema marks required to send, for one platform; empty when it marks none.
     *
     * @param string $platform Settings platform ID.
     *
     * @return string[]
     */
    public static function required_ids( string $platform ): array {
        if ( null === self::$required ) {
            self::$required = array();

            foreach ( Schema::get()['platforms'] as $id => $schema ) {
                self::$required[ (string) $id ] = self::marked( $schema['schema'] );
            }
        }

        return isset( self::$required[ $platform ] ) ? self::$required[ $platform ] : array();
    }

    /**
     * Whether a platform is on by the 3.0 rule: every required ID is set (pausing is the off switch); null when the 3.0 settings don't exist or the schema marks no required ID.
     *
     * @param string $platform Settings platform ID.
     *
     * @return bool|null
     */
    public static function is_on( string $platform ): ?bool {
        $values = self::values( $platform );

        if ( null === $values ) {
            return null;
        }

        $required = self::required_ids( $platform );

        return $required ? self::filled_all( $values, $required ) : null;
    }

    /**
     * Whether a platform has the IDs it needs: the required ones where the schema marks them, Google by its mode and IDs, else any ID or a Custom placement on with a script.
     *
     * @param string $platform Settings platform ID.
     * @param array  $elements The platform's schema elements.
     * @param array  $values   The platform's values.
     *
     * @return bool
     */
    public static function connected( string $platform, array $elements, array $values ): bool {
        $required = self::marked( $elements );

        if ( $required ) {
            $connected = self::filled_all( $values, $required );
        } elseif ( 'google' === $platform ) {
            $connected = (bool) array_filter( array_column( Google::integrations( $values ), 'enabled' ) );
        } else {
            $connected = self::any_id( $elements, $values );
        }

        /**
         * Whether a platform is connected (has the IDs it needs to send).
         *
         * @since 3.0.0
         *
         * @param bool   $connected Whether it is.
         * @param string $platform  Settings platform ID.
         * @param array  $values    The platform's values.
         */
        return (bool) apply_filters( self::CONNECTED_FILTER, $connected, $platform, $values );
    }

    /**
     * Whether a field applies, given the other values (schema dependencies).
     *
     * @param array $field  Field.
     * @param array $values Platform values.
     *
     * @return bool
     */
    public static function applies( array $field, array $values ): bool {
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
     * A trimmed string value, or an empty string.
     *
     * @param array  $values Values.
     * @param string $key    Key.
     *
     * @return string
     */
    public static function text( array $values, string $key ): string {
        return isset( $values[ $key ] ) && is_scalar( $values[ $key ] ) ? trim( (string) $values[ $key ] ) : '';
    }

    /**
     * Forget what was read in this request.
     *
     * @return void
     */
    public static function reset() {
        self::$doc      = null;
        self::$read     = false;
        self::$required = null;
        self::$fields   = null;
    }

    /**
     * The wcct_settings_v3 option, read once per request; null while it doesn't exist.
     *
     * @return array|null
     */
    private static function doc(): ?array {
        if ( ! self::$read ) {
            $doc = get_option( Store::OPTION, null );

            self::$doc  = null === $doc ? null : ( is_array( $doc ) ? $doc : array() );
            self::$read = true;

            self::hook();
        }

        return self::$doc;
    }

    /**
     * Read the settings again after a save in the same request.
     *
     * @return void
     */
    private static function hook() {
        if ( self::$hooked || ! function_exists( 'add_action' ) ) {
            return;
        }

        foreach ( array( Store::OPTION, Store::V1_OPTION ) as $option ) {
            add_action( 'add_option_' . $option, array( self::class, 'reset' ) );
            add_action( 'update_option_' . $option, array( self::class, 'reset' ) );
            add_action( 'delete_option_' . $option, array( self::class, 'reset' ) );
        }

        self::$hooked = true;
    }

    /**
     * IDs of the fields marked required to send.
     *
     * @param array $elements Schema elements.
     *
     * @return string[]
     */
    private static function marked( array $elements ): array {
        $ids = array();

        foreach ( Schema::fields( $elements ) as $id => $field ) {
            if ( ! empty( $field[ self::REQUIRED_KEY ] ) ) {
                $ids[] = (string) $id;
            }
        }

        return $ids;
    }

    /**
     * Whether every key has a non-empty value.
     *
     * @param array    $values Values.
     * @param string[] $keys   Keys.
     *
     * @return bool
     */
    private static function filled_all( array $values, array $keys ): bool {
        foreach ( $keys as $key ) {
            if ( '' === self::text( $values, $key ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Any applicable ID field filled, or a Custom placement switched on with its script.
     *
     * @param array $elements Schema elements.
     * @param array $values   Values.
     *
     * @return bool
     */
    private static function any_id( array $elements, array $values ): bool {
        foreach ( $elements as $field ) {
            if ( ! is_array( $field ) || ! isset( $field['type'], $field['id'] ) || 'field' !== $field['type'] || ! self::applies( $field, $values ) ) {
                continue;
            }

            $id = (string) $field['id'];

            if ( isset( $field['format'] ) && 'id' === $field['format'] && self::filled( $values, $id ) ) {
                return true;
            }

            if ( isset( $field['variant'] ) && 'switch' === $field['variant'] && '_enabled' === substr( $id, -8 ) && isset( $values[ $id ] ) && true === $values[ $id ] && self::filled( $values, substr( $id, 0, -8 ) . '_script' ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a value is a non-empty string.
     *
     * @param array  $values Values.
     * @param string $key    Key.
     *
     * @return bool
     */
    private static function filled( array $values, string $key ): bool {
        return isset( $values[ $key ] ) && is_string( $values[ $key ] ) && '' !== trim( $values[ $key ] );
    }
}
