<?php

namespace StorePulse\ConversionTracking\Admin;

/**
 * Cleans what the settings page posts to the shape it saves, dropping unknown integrations and fields.
 */
class PostedSettings {

    /**
     * Key of the integration on/off switch.
     */
    const ENABLED = 'enabled';

    /**
     * Value a ticked checkbox is saved as.
     */
    const CHECKED = 'on';

    /**
     * Keep only the integrations and fields the settings page shows.
     *
     * @param array $posted Unslashed settings[] from the form, keyed by integration ID.
     * @param array $fields Each integration's get_settings() fields, keyed by integration ID.
     *
     * @return array
     */
    public static function clean( array $posted, array $fields ): array {
        $clean = array();

        foreach ( $posted as $id => $values ) {
            if ( ! isset( $fields[ $id ] ) || ! is_array( $fields[ $id ] ) || ! is_array( $values ) ) {
                continue;
            }

            $clean[ $id ] = self::integration( $values, self::by_name( $fields[ $id ] ) );
        }

        return $clean;
    }

    /**
     * One integration: the switch, top-level fields and the numbered field groups.
     *
     * @param array $values Posted values.
     * @param array $fields Fields by name.
     *
     * @return array
     */
    private static function integration( array $values, array $fields ): array {
        $clean = array();

        foreach ( $values as $key => $value ) {
            // Text and multicheck fields sit in numbered groups; Pro's extra Meta pixels add more groups.
            if ( is_int( $key ) ) {
                if ( is_array( $value ) ) {
                    $clean[] = self::group( $value, $fields );
                }

                continue;
            }

            if ( self::ENABLED === $key ) {
                $clean[ self::ENABLED ] = true;
                continue;
            }

            $type = isset( $fields[ $key ] ) ? $fields[ $key ]['type'] : '';

            if ( 'textarea' === $type ) {
                // Custom scripts are raw by design; everything else is cleaned to ID characters later.
                $clean[ $key ] = is_scalar( $value ) ? (string) $value : '';
            } elseif ( 'checkbox' === $type && self::CHECKED === $value ) {
                $clean[ $key ] = self::CHECKED;
            }
        }

        if ( ! isset( $clean[ self::ENABLED ] ) ) {
            $clean[ self::ENABLED ] = false;
        }

        return $clean;
    }

    /**
     * One numbered group of text and multicheck fields.
     *
     * @param array $values Posted values.
     * @param array $fields Fields by name.
     *
     * @return array
     */
    private static function group( array $values, array $fields ): array {
        $clean = array();

        foreach ( $values as $name => $value ) {
            $type = isset( $fields[ $name ] ) ? $fields[ $name ]['type'] : '';

            if ( 'text' === $type ) {
                $clean[ $name ] = self::text( $value );
            } elseif ( 'multicheck' === $type && is_array( $value ) ) {
                $options        = isset( $fields[ $name ]['options'] ) && is_array( $fields[ $name ]['options'] ) ? $fields[ $name ]['options'] : array();
                $clean[ $name ] = self::multicheck( $value, $options );
            }
        }

        return $clean;
    }

    /**
     * Ticked options and their label boxes; Pro-only teaser options are dropped.
     *
     * @param array $values  Posted values.
     * @param array $options Field options.
     *
     * @return array
     */
    private static function multicheck( array $values, array $options ): array {
        $labels = array();

        foreach ( $options as $option ) {
            if ( is_array( $option ) && ! empty( $option['event_label_box'] ) && isset( $option['label_name'] ) ) {
                $labels[ (string) $option['label_name'] ] = true;
            }
        }

        $clean = array();

        foreach ( $values as $key => $value ) {
            if ( isset( $options[ $key ] ) ) {
                $teaser = is_array( $options[ $key ] ) && ! empty( $options[ $key ]['profeature'] );

                if ( ! $teaser && self::CHECKED === $value ) {
                    $clean[ $key ] = self::CHECKED;
                }
            } elseif ( isset( $labels[ $key ] ) ) {
                $clean[ $key ] = self::text( $value );
            }
        }

        return $clean;
    }

    /**
     * A one-line string; anything else becomes empty.
     *
     * @param mixed $value Posted value.
     *
     * @return string
     */
    private static function text( $value ): string {
        return is_scalar( $value ) ? trim( preg_replace( '/[\x00-\x1F\x7F]+/', ' ', (string) $value ) ) : '';
    }

    /**
     * Index field definitions by their name.
     *
     * @param array $fields get_settings() fields.
     *
     * @return array
     */
    private static function by_name( array $fields ): array {
        $named = array();

        foreach ( $fields as $field ) {
            if ( is_array( $field ) && isset( $field['name'], $field['type'] ) && is_scalar( $field['name'] ) ) {
                $named[ (string) $field['name'] ] = $field;
            }
        }

        return $named;
    }
}
