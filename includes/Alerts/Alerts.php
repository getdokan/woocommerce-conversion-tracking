<?php

namespace StorePulse\ConversionTracking\Alerts;

use StorePulse\ConversionTracking\Settings\Schema;
use StorePulse\ConversionTracking\Settings\Store;

/**
 * Settings > Alerts: a switch per alert type plus "Doctor found something new", stored in wcct_settings_v3 as alert_<key>; on by default.
 * A switch only hides the badge, the card or the re-check; the data is still recorded.
 */
class Alerts {

    /**
     * Key of the "Doctor found something new" switch (the quiet re-check after a plugin is activated).
     */
    const DOCTOR = 'doctor_new';

    /**
     * Settings field ID prefix.
     */
    const PREFIX = 'alert_';

    /**
     * Settings page and section of the switches.
     */
    const PAGE    = 'alerts';
    const SECTION = 'alerts_dashboard';

    /**
     * Hook into WordPress.
     *
     * @return void
     */
    public static function register() {
        add_filter( 'wcct_doctor_recheck_on_activation', array( self::class, 'filter_recheck' ) );
        Badge::register();
    }

    /**
     * The settings field of an alert key.
     *
     * @param string $key Alert type key, or DOCTOR.
     *
     * @return string
     */
    public static function field( $key ) {
        return self::PREFIX . $key;
    }

    /**
     * Whether an alert is on; a missing or unreadable value is on.
     *
     * @param string     $key Alert type key, or DOCTOR.
     * @param array|null $doc Settings; the stored ones by default.
     *
     * @return bool
     */
    public static function is_on( $key, $doc = null ) {
        $doc   = is_array( $doc ) ? $doc : Store::load();
        $field = self::field( $key );

        if ( ! isset( $doc[ $field ] ) || ! is_scalar( $doc[ $field ] ) ) {
            return true;
        }

        $value = filter_var( $doc[ $field ], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );

        return null === $value ? true : $value;
    }

    /**
     * Every switch and whether it's on: type key (and DOCTOR) => bool.
     *
     * @param array|null $doc Settings; the stored ones by default.
     *
     * @return bool[]
     */
    public static function states( $doc = null ) {
        $doc    = is_array( $doc ) ? $doc : Store::load();
        $states = array();

        foreach ( array_merge( array_keys( Types::all() ), array( self::DOCTOR ) ) as $key ) {
            $states[ $key ] = self::is_on( $key, $doc );
        }

        return $states;
    }

    /**
     * The quiet re-check runs only while "Doctor found something new" is on.
     *
     * @param bool $enabled Whether the re-check runs.
     *
     * @return bool
     */
    public static function filter_recheck( $enabled ) {
        return $enabled && self::is_on( self::DOCTOR );
    }

    /**
     * Settings > Alerts in the settings schema: the page, its section and a switch per alert type, then the Doctor's.
     *
     * @return array
     */
    public static function schema_elements() {
        $elements = array(
            array(
                'id'    => self::PAGE,
                'type'  => 'page',
                'label' => __( 'Alerts', 'woocommerce-conversion-tracking' ),
            ),
            Schema::section( self::PAGE, self::SECTION, __( 'On the dashboard', 'woocommerce-conversion-tracking' ) ),
        );

        foreach ( Types::all() as $key => $type ) {
            $elements[] = self::switch_field( $key, $type['label'], $type['description'] );
        }

        $elements[] = self::switch_field(
            self::DOCTOR,
            __( 'Doctor found something new', 'woocommerce-conversion-tracking' ),
            __( 'After a plugin is activated, a quiet re-check. Dashboard only, never a popup.', 'woocommerce-conversion-tracking' )
        );

        return $elements;
    }

    /**
     * One alert switch.
     *
     * @param string $key         Alert key.
     * @param string $label       Title.
     * @param string $description Description.
     *
     * @return array
     */
    private static function switch_field( $key, $label, $description ) {
        return array(
            'id'          => self::field( $key ),
            'type'        => 'field',
            'variant'     => 'switch',
            'section_id'  => self::SECTION,
            'label'       => $label,
            'description' => $description,
            'default'     => true,
            'alert'       => $key,
        );
    }
}
