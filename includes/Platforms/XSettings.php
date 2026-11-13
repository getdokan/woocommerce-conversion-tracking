<?php

namespace StorePulse\ConversionTracking\Platforms;

/**
 * X settings in the 3.0 shape: { pixel_id, purchase_event_id, cart_event_id, checkout_event_id, signup_event_id, events, needs_resetup }.
 */
class XSettings {

    /**
     * Pipeline event => the key holding its X event ID.
     */
    const EVENT_ID_KEYS = array(
        'purchase'       => 'purchase_event_id',
        'add_to_cart'    => 'cart_event_id',
        'begin_checkout' => 'checkout_event_id',
        'sign_up'        => 'signup_event_id',
    );

    /**
     * Pipeline event => its checkbox in the 2.x settings form.
     */
    const V1_SWITCHES = array(
        'purchase'       => 'Purchase',
        'add_to_cart'    => 'AddToCart',
        'begin_checkout' => 'InitiateCheckout',
        'sign_up'        => 'Registration',
    );

    /**
     * Pipeline event => the 2.x Pro single-event label; never sent as an event ID.
     */
    const LEGACY_LABELS = array(
        'add_to_cart' => 'AddtoCart-label',
        'sign_up'     => 'registration-label',
    );

    /**
     * The 2.x key that held the pixel ID (the universal website tag).
     */
    const LEGACY_PIXEL_KEY = 'universal_tag_id';

    /**
     * 3.0 settings from the twitter entry of the wcct_settings option.
     *
     * @param mixed $saved The twitter entry: { enabled, 0: { pixel_id, events: { Purchase, purchase_event_id, ... } } }.
     *
     * @return array
     */
    public static function from_v1( $saved ) {
        $first  = is_array( $saved ) && isset( $saved[0] ) && is_array( $saved[0] ) ? $saved[0] : array();
        $events = isset( $first['events'] ) && is_array( $first['events'] ) ? $first['events'] : array();
        $pixel  = self::text( $first, 'pixel_id' );

        $values = array(
            'pixel_id' => '' !== $pixel ? $pixel : self::text( $events, self::LEGACY_PIXEL_KEY ),
            'events'   => array( 'page_view' ),
        );

        foreach ( self::EVENT_ID_KEYS as $event => $key ) {
            $values[ $key ] = self::text( $events, $key );

            if ( isset( $events[ self::V1_SWITCHES[ $event ] ] ) && 'on' === $events[ self::V1_SWITCHES[ $event ] ] ) {
                $values['events'][] = $event;
            }
        }

        $values['needs_resetup'] = self::legacy_events( $events, $values );

        return $values;
    }

    /**
     * 3.0 settings from the twitter entry of wcct_settings_v3's platforms, missing keys filled in.
     *
     * @param mixed $platform Stored values.
     *
     * @return array
     */
    public static function from_v3( $platform ) {
        $platform = is_array( $platform ) ? $platform : array();
        $values   = array( 'pixel_id' => self::text( $platform, 'pixel_id' ) );

        foreach ( self::EVENT_ID_KEYS as $key ) {
            $values[ $key ] = self::text( $platform, $key );
        }

        $values['events'] = self::strings( isset( $platform['events'] ) ? $platform['events'] : array() );

        // A flag clears once the event has a valid ID.
        $values['needs_resetup'] = array_values(
            array_filter(
                self::strings( isset( $platform['needs_resetup'] ) ? $platform['needs_resetup'] : array() ),
                function ( $event ) use ( $values ) {
                    return ! isset( self::EVENT_ID_KEYS[ $event ] ) || ! self::is_event_id( $values[ self::EVENT_ID_KEYS[ $event ] ] );
                }
            )
        );

        return $values;
    }

    /**
     * The X event ID to send for a pipeline event, or an empty string when the row is off.
     *
     * @param array  $values Settings from from_v1() or from_v3().
     * @param string $event  Pipeline event name.
     *
     * @return string
     */
    public static function event_id( array $values, $event ) {
        if ( ! isset( self::EVENT_ID_KEYS[ $event ] ) || empty( $values['events'] ) || ! in_array( $event, (array) $values['events'], true ) ) {
            return '';
        }

        $id = self::text( $values, self::EVENT_ID_KEYS[ $event ] );

        return self::is_event_id( $id ) ? $id : '';
    }

    /**
     * Whether a value has the X event ID format, tw-<pixel>-<event>.
     *
     * @param mixed $id Value.
     *
     * @return bool
     */
    public static function is_event_id( $id ) {
        return is_string( $id ) && 1 === preg_match( '/^tw-[a-z0-9]+-[a-z0-9]+$/i', $id );
    }

    /**
     * Events 2.x sent without an event ID that now need one.
     *
     * @param array $events 2.x events entry.
     * @param array $values 3.0 values read so far.
     *
     * @return string[]
     */
    private static function legacy_events( array $events, array $values ) {
        $legacy = array();

        // 2.x sent Purchase with the base tag alone.
        if ( '' !== self::text( $events, self::LEGACY_PIXEL_KEY ) && isset( $events['Purchase'] ) && 'on' === $events['Purchase'] ) {
            $legacy[] = 'purchase';
        }

        foreach ( self::LEGACY_LABELS as $event => $label ) {
            if ( '' !== self::text( $events, $label ) ) {
                $legacy[] = $event;
            }
        }

        return array_values(
            array_filter(
                $legacy,
                function ( $event ) use ( $values ) {
                    return ! self::is_event_id( $values[ self::EVENT_ID_KEYS[ $event ] ] );
                }
            )
        );
    }

    /**
     * A trimmed string value, or an empty string.
     *
     * @param array  $values Values.
     * @param string $key    Key.
     *
     * @return string
     */
    private static function text( array $values, $key ) {
        return isset( $values[ $key ] ) && is_scalar( $values[ $key ] ) ? trim( (string) $values[ $key ] ) : '';
    }

    /**
     * The strings of a list.
     *
     * @param mixed $list List.
     *
     * @return string[]
     */
    private static function strings( $list ) {
        return is_array( $list ) ? array_values( array_filter( $list, 'is_string' ) ) : array();
    }
}
