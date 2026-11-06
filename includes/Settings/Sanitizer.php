<?php

namespace StorePulse\ConversionTracking\Settings;

use InvalidArgumentException;
use StorePulse\ConversionTracking\Access\SettingsIds;

/**
 * Validates and cleans one posted value against its schema field.
 */
class Sanitizer {

    /**
     * Variants whose value is one of the options.
     */
    const CHOICE_VARIANTS = array( 'select', 'radio_capsule', 'customize_radio' );

    /**
     * Variants whose value is a list of options.
     */
    const LIST_VARIANTS = array( 'multicheck', 'checkbox_group' );

    /**
     * Longest ID or label kept.
     */
    const MAX_ID_LENGTH = 100;

    /**
     * The clean value.
     *
     * @param array $field   Schema field.
     * @param mixed $value   Posted value.
     * @param mixed $current Stored value, or the default.
     *
     * @throws InvalidArgumentException When the value doesn't fit the field.
     *
     * @return mixed
     */
    public static function field( array $field, $value, $current ) {
        if ( isset( $field['sanitize_callback'] ) && is_callable( $field['sanitize_callback'] ) ) {
            return call_user_func( $field['sanitize_callback'], $value, $field, $current );
        }

        $variant = isset( $field['variant'] ) ? $field['variant'] : '';

        if ( 'switch' === $variant ) {
            return self::boolean( $value );
        }

        if ( 'text' === $variant ) {
            $value = self::text( $value );

            return isset( $field['format'] ) && 'id' === $field['format'] ? self::id( $field, $value ) : sanitize_text_field( $value );
        }

        if ( 'textarea' === $variant ) {
            $value = self::text( $value );

            // Scripts are saved as written; only unfiltered_html users reach this.
            return isset( $field['format'] ) && 'script' === $field['format'] ? $value : sanitize_textarea_field( $value );
        }

        if ( in_array( $variant, self::CHOICE_VARIANTS, true ) ) {
            return self::choice( $field, $value );
        }

        if ( in_array( $variant, self::LIST_VARIANTS, true ) ) {
            return self::choices( $field, $value, $current );
        }

        throw new InvalidArgumentException( __( 'This setting can\'t be saved.', 'woocommerce-conversion-tracking' ) );
    }

    /**
     * A platform ID or label: ID characters only, in the field's case, matching its pattern; empty clears it.
     *
     * @param array  $field Field with optional case (upper, lower) and pattern.
     * @param string $value Value.
     *
     * @throws InvalidArgumentException When it is too long or doesn't match the pattern.
     *
     * @return string
     */
    public static function id( array $field, string $value ): string {
        $value = SettingsIds::clean_id( trim( $value ) );
        $case  = isset( $field['case'] ) ? $field['case'] : '';

        if ( 'upper' === $case ) {
            $value = strtoupper( $value );
        } elseif ( 'lower' === $case ) {
            $value = strtolower( $value );
        }

        if ( strlen( $value ) > self::MAX_ID_LENGTH ) {
            throw new InvalidArgumentException( __( 'This ID is too long.', 'woocommerce-conversion-tracking' ) );
        }

        if ( '' !== $value && ! empty( $field['pattern'] ) && ! preg_match( '~' . $field['pattern'] . '~', $value ) ) {
            throw new InvalidArgumentException( __( 'This doesn\'t look like a valid ID.', 'woocommerce-conversion-tracking' ) );
        }

        return $value;
    }

    /**
     * A boolean from the usual true and false spellings.
     *
     * @param mixed $value Value.
     *
     * @throws InvalidArgumentException When it isn't one.
     *
     * @return bool
     */
    public static function boolean( $value ): bool {
        if ( is_bool( $value ) ) {
            return $value;
        }

        $value = is_scalar( $value ) ? strtolower( (string) $value ) : null;

        if ( in_array( $value, array( '1', 'true', 'yes', 'on' ), true ) ) {
            return true;
        }

        if ( in_array( $value, array( '0', 'false', 'no', 'off', '' ), true ) ) {
            return false;
        }

        throw new InvalidArgumentException( __( 'Must be on or off.', 'woocommerce-conversion-tracking' ) );
    }

    /**
     * A string from a scalar.
     *
     * @param mixed $value Value.
     *
     * @throws InvalidArgumentException When it isn't one.
     *
     * @return string
     */
    private static function text( $value ): string {
        if ( null === $value ) {
            return '';
        }

        if ( ! is_scalar( $value ) || is_bool( $value ) ) {
            throw new InvalidArgumentException( __( 'Must be text.', 'woocommerce-conversion-tracking' ) );
        }

        return (string) $value;
    }

    /**
     * One of the field's options.
     *
     * @param array $field Field.
     * @param mixed $value Value.
     *
     * @throws InvalidArgumentException When it isn't one.
     *
     * @return mixed The option's value.
     */
    private static function choice( array $field, $value ) {
        foreach ( self::options( $field ) as $option ) {
            if ( is_scalar( $value ) && (string) $option['value'] === (string) $value ) {
                return $option['value'];
            }
        }

        throw new InvalidArgumentException( __( 'Not one of the choices.', 'woocommerce-conversion-tracking' ) );
    }

    /**
     * Options picked from the field's list, in the field's order.
     *
     * Locked options keep their stored state; always-on options are always included.
     *
     * @param array $field   Field.
     * @param mixed $value   Value.
     * @param mixed $current Stored value.
     *
     * @throws InvalidArgumentException When the value isn't a list of options.
     *
     * @return array
     */
    private static function choices( array $field, $value, $current ): array {
        if ( ! is_array( $value ) ) {
            throw new InvalidArgumentException( __( 'Must be a list.', 'woocommerce-conversion-tracking' ) );
        }

        $posted  = array();
        $current = is_array( $current ) ? array_map( 'strval', array_filter( $current, 'is_scalar' ) ) : array();
        $known   = array();

        foreach ( self::options( $field ) as $option ) {
            $known[] = (string) $option['value'];
        }

        foreach ( $value as $item ) {
            if ( ! is_scalar( $item ) || ! in_array( (string) $item, $known, true ) ) {
                throw new InvalidArgumentException( __( 'Not one of the choices.', 'woocommerce-conversion-tracking' ) );
            }

            $posted[] = (string) $item;
        }

        $picked = array();

        foreach ( self::options( $field ) as $option ) {
            $key = (string) $option['value'];

            if ( ! empty( $option['always'] ) ) {
                $on = true;
            } elseif ( ! empty( $option['locked'] ) ) {
                $on = in_array( $key, $current, true );
            } else {
                $on = in_array( $key, $posted, true );
            }

            if ( $on ) {
                $picked[] = $option['value'];
            }
        }

        return $picked;
    }

    /**
     * The field's options that have a value.
     *
     * @param array $field Field.
     *
     * @return array
     */
    private static function options( array $field ): array {
        $options = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();

        return array_filter(
            $options,
            function ( $option ) {
                return is_array( $option ) && isset( $option['value'] ) && is_scalar( $option['value'] );
            }
        );
    }
}
