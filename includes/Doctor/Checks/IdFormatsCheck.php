<?php

namespace StorePulse\ConversionTracking\Doctor\Checks;

use StorePulse\ConversionTracking\Access\SettingsIds;
use StorePulse\ConversionTracking\Doctor\AbstractCheck;
use StorePulse\ConversionTracking\Doctor\Context;
use StorePulse\ConversionTracking\Doctor\Finding;
use StorePulse\ConversionTracking\Doctor\Platforms;
use StorePulse\ConversionTracking\Settings\Sanitizer;

/**
 * Saved IDs that don't match their format, IDs of an unusual length (a note) and IDs a Purchase needs that are missing.
 * New saves are checked already; settings saved before 3.0 and imports are not.
 */
class IdFormatsCheck extends AbstractCheck {

    /**
     * IDs a Purchase needs next to the main ID: platform => [ needed field, field it completes ].
     */
    const PURCHASE_NEEDS = array(
        'google'  => array( 'purchase_label', 'ads_id' ),
        'twitter' => array( 'purchase_event_id', 'pixel_id' ),
    );

    /**
     * Check ID.
     *
     * @return string
     */
    public function id() {
        return 'id-formats';
    }

    /**
     * Working line.
     *
     * @return string
     */
    public function label() {
        return __( 'Your platform IDs have the right format', 'woocommerce-conversion-tracking' );
    }

    /**
     * A finding per malformed or missing ID.
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

        foreach ( self::ids( $states ) as $id ) {
            if ( '' === $id['value'] ) {
                continue;
            }

            if ( ! self::saves( $id ) ) {
                $findings[] = $this->malformed( $id );
            } elseif ( self::unusual_length( $id ) ) {
                $findings[] = $this->unusual( $id );
            }
        }

        foreach ( self::PURCHASE_NEEDS as $platform => $fields ) {
            if ( empty( $states[ $platform ]['sends'] ) ) {
                continue;
            }

            $finding = $this->missing( $states[ $platform ], $fields[0], $fields[1] );

            if ( $finding ) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * Every saved ID with its format, from the settings schema and the wcct_doctor_ids filter.
     *
     * @param array $states Platform states.
     *
     * @return array[] { platform, platform_label, field, label, value, pattern, case, prefix, hint, typical_length }.
     */
    public static function ids( array $states ) {
        $ids = array();

        foreach ( $states as $state ) {
            foreach ( $state['schema'] as $field ) {
                if ( ! self::is_id_field( $field ) || ! Platforms::applies( $field, $state['values'] ) ) {
                    continue;
                }

                $ids[] = array(
                    'platform'       => $state['id'],
                    'platform_label' => $state['label'],
                    'field'          => (string) $field['id'],
                    'label'          => isset( $field['label'] ) ? (string) $field['label'] : (string) $field['id'],
                    'value'          => Platforms::filled( $state['values'], $field['id'] ) ? trim( $state['values'][ $field['id'] ] ) : '',
                    'pattern'        => (string) $field['pattern'],
                    'case'           => isset( $field['case'] ) ? (string) $field['case'] : '',
                    'prefix'         => isset( $field['prefix'] ) ? (string) $field['prefix'] : '',
                    'hint'           => isset( $field['placeholder'] ) ? (string) $field['placeholder'] : '',
                    'typical_length' => isset( $field['typical_length'] ) ? $field['typical_length'] : array(),
                );
            }
        }

        /**
         * Filter the IDs the Doctor checks, for example a second Meta pixel.
         *
         * @since 3.0.0
         *
         * @param array $ids    { platform, platform_label, field, label, value, pattern, case (upper, lower or empty), prefix, hint, typical_length ([ min, max ], optional) }.
         * @param array $states Platform states keyed by settings platform ID.
         */
        $ids = apply_filters( 'wcct_doctor_ids', $ids, $states );

        return array_values(
            array_filter(
                is_array( $ids ) ? $ids : array(),
                static function ( $id ) {
                    return is_array( $id ) && isset( $id['platform'], $id['field'], $id['value'], $id['pattern'] ) && is_string( $id['value'] ) && is_string( $id['pattern'] );
                }
            )
        );
    }

    /**
     * Whether the Save would accept the ID as it is (Sanitizer::id(): cleaned, in the field's case, within the length, matching).
     * A pattern that doesn't compile (from the filter) counts as accepted: we can't tell.
     *
     * @param array $id ID from ids().
     *
     * @return bool
     */
    private static function saves( array $id ) {
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a broken pattern from the filter must not break the run.
        if ( '' === $id['pattern'] || false === @preg_match( '~' . $id['pattern'] . '~', '' ) ) {
            return true;
        }

        try {
            Sanitizer::id(
                array(
                    'pattern' => $id['pattern'],
                    'case'    => isset( $id['case'] ) && is_string( $id['case'] ) ? $id['case'] : '',
                    'prefix'  => isset( $id['prefix'] ) && is_string( $id['prefix'] ) ? $id['prefix'] : '',
                ),
                $id['value']
            );
        } catch ( \InvalidArgumentException $e ) {
            return false;
        }

        return true;
    }

    /**
     * The typical length of an ID as [ min, max ], or null when it has none.
     *
     * @param array $id ID from ids().
     *
     * @return int[]|null
     */
    private static function typical_length( array $id ) {
        $range = isset( $id['typical_length'] ) && is_array( $id['typical_length'] ) ? array_values( $id['typical_length'] ) : array();

        if ( 2 !== count( $range ) || ! is_int( $range[0] ) || ! is_int( $range[1] ) ) {
            return null;
        }

        return $range;
    }

    /**
     * Whether a saved ID is outside its typical length; it still saves and sends.
     *
     * @param array $id ID from ids().
     *
     * @return bool
     */
    private static function unusual_length( array $id ) {
        $range = self::typical_length( $id );

        if ( ! $range ) {
            return false;
        }

        $length = strlen( SettingsIds::clean_id( trim( $id['value'] ) ) );

        return $length < $range[0] || $length > $range[1];
    }

    /**
     * Whether an element is an ID field with a format the merchant can change.
     *
     * @param mixed $field Element.
     *
     * @return bool
     */
    private static function is_id_field( $field ) {
        return is_array( $field ) && isset( $field['type'], $field['id'], $field['format'] ) && 'field' === $field['type'] && 'id' === $field['format']
            && ! empty( $field['pattern'] ) && is_string( $field['pattern'] ) && empty( $field['disabled'] );
    }

    /**
     * A field label without "(optional)".
     *
     * @param string $label Label.
     *
     * @return string
     */
    private static function field_name( $label ) {
        return trim( (string) preg_replace( '/\s*\([^)]*\)\s*$/', '', (string) $label ) );
    }

    /**
     * Platform and field name, without saying the platform twice ("Google tag ID", not "Google Google tag ID").
     *
     * @param string $platform Platform name.
     * @param string $label    Field label.
     *
     * @return string
     */
    private static function subject( $platform, $label ) {
        $field = self::field_name( $label );

        return 0 === stripos( $field, $platform . ' ' ) ? $field : $platform . ' ' . $field;
    }

    /**
     * The link to a field.
     *
     * @param string $platform Settings platform ID.
     * @param string $field    Field ID.
     *
     * @return string
     */
    private static function field_url( $platform, $field ) {
        return '#/platforms/' . $platform . '?field=' . $field;
    }

    /**
     * The finding for an ID that doesn't match its format.
     *
     * @param array $id ID from ids().
     *
     * @return Finding
     */
    private function malformed( array $id ) {
        $platform = isset( $id['platform_label'] ) && is_string( $id['platform_label'] ) ? $id['platform_label'] : $id['platform'];
        $why      = '' !== $id['hint']
            /* translators: 1: platform name, 2: the expected format, for example "Starts with G-" */
            ? sprintf( __( '%1$s ignores events sent with an ID it doesn\'t know, so they aren\'t counted. Expected: %2$s.', 'woocommerce-conversion-tracking' ), $platform, $id['hint'] )
            /* translators: %s: platform name */
            : sprintf( __( '%s ignores events sent with an ID it doesn\'t know, so they aren\'t counted.', 'woocommerce-conversion-tracking' ), $platform );

        return $this->finding(
            array(
                'key'        => $id['platform'] . ':' . $id['field'],
                /* translators: %s: platform and field name, for example "Meta Pixel ID" */
                'found'      => sprintf( __( 'Your %s doesn\'t look right', 'woocommerce-conversion-tracking' ), self::subject( $platform, isset( $id['label'] ) ? $id['label'] : $id['field'] ) ),
                'why'        => $why,
                'label'      => __( 'ID', 'woocommerce-conversion-tracking' ),
                'tags'       => array( $platform ),
                'confidence' => Finding::SURE,
                'action'     => array(
                    /* translators: %s: platform name */
                    'label' => sprintf( __( 'Open %s', 'woocommerce-conversion-tracking' ), $platform ),
                    'url'   => self::field_url( $id['platform'], $id['field'] ),
                ),
                'evidence'   => array(
                    'platform' => $id['platform'],
                    'field'    => $id['field'],
                    'value'    => $id['value'],
                ),
            )
        );
    }

    /**
     * The note for an ID outside its typical length: format only, it may still be right.
     *
     * @param array $id ID from ids().
     *
     * @return Finding
     */
    private function unusual( array $id ) {
        $platform = isset( $id['platform_label'] ) && is_string( $id['platform_label'] ) ? $id['platform_label'] : $id['platform'];
        $range    = self::typical_length( $id );
        $typical  = $range[0] === $range[1]
            /* translators: %d: number of characters */
            ? sprintf( _n( 'Most are %d character long.', 'Most are %d characters long.', $range[0], 'woocommerce-conversion-tracking' ), $range[0] )
            /* translators: 1: fewest characters, 2: most characters */
            : sprintf( __( 'Most are %1$d to %2$d characters long.', 'woocommerce-conversion-tracking' ), $range[0], $range[1] );

        return $this->finding(
            array(
                'key'        => $id['platform'] . ':' . $id['field'],
                /* translators: %s: platform and field name, for example "Meta Pixel ID" */
                'found'      => sprintf( __( 'Your %s has an unusual length', 'woocommerce-conversion-tracking' ), self::subject( $platform, isset( $id['label'] ) ? $id['label'] : $id['field'] ) ),
                /* translators: 1: typical length sentence, 2: platform name */
                'why'        => sprintf( __( '%1$s It is saved and sent as it is; double-check it. %2$s confirms it once events arrive.', 'woocommerce-conversion-tracking' ), $typical, $platform ),
                'label'      => __( 'ID', 'woocommerce-conversion-tracking' ),
                'tags'       => array( $platform ),
                'confidence' => Finding::UNSURE,
                'severity'   => Finding::INFO,
                'action'     => array(
                    /* translators: %s: platform name */
                    'label' => sprintf( __( 'Open %s', 'woocommerce-conversion-tracking' ), $platform ),
                    'url'   => self::field_url( $id['platform'], $id['field'] ),
                ),
                'evidence'   => array(
                    'platform' => $id['platform'],
                    'field'    => $id['field'],
                    'value'    => $id['value'],
                ),
            )
        );
    }

    /**
     * The finding for an ID a Purchase needs that is empty, or null.
     *
     * @param array  $state     Platform state.
     * @param string $field     Needed field.
     * @param string $completes Field it completes.
     *
     * @return Finding|null
     */
    private function missing( array $state, $field, $completes ) {
        $values = $state['values'];
        $events = isset( $values['events'] ) && is_array( $values['events'] ) ? $values['events'] : array();
        $label  = '';

        foreach ( $state['schema'] as $element ) {
            if ( self::is_id_field( $element ) && $field === $element['id'] && Platforms::applies( $element, $values ) ) {
                $label = self::field_name( isset( $element['label'] ) ? $element['label'] : $field );
            }
        }

        if ( '' === $label || ! in_array( 'purchase', $events, true ) || ! Platforms::filled( $values, $completes ) || Platforms::filled( $values, $field ) ) {
            return null;
        }

        $platform = $state['label'];

        return $this->finding(
            array(
                'key'        => $state['id'] . ':' . $field,
                /* translators: %s: platform and field name, for example "Google Ads conversion label" */
                'found'      => sprintf( __( 'Your %s looks incomplete', 'woocommerce-conversion-tracking' ), self::subject( $platform, $label ) ),
                /* translators: 1: platform name, 2: field name */
                'why'        => sprintf( __( '%1$s can\'t count purchases for your campaigns until the %2$s is filled in.', 'woocommerce-conversion-tracking' ), $platform, $label ),
                'label'      => __( 'ID', 'woocommerce-conversion-tracking' ),
                'tags'       => array( $platform ),
                'confidence' => Finding::SURE,
                'action'     => array(
                    /* translators: %s: platform name */
                    'label' => sprintf( __( 'Open %s', 'woocommerce-conversion-tracking' ), $platform ),
                    'url'   => self::field_url( $state['id'], $field ),
                ),
                'evidence'   => array(
                    'platform' => $state['id'],
                    'field'    => $field,
                    'value'    => '',
                ),
            )
        );
    }
}
