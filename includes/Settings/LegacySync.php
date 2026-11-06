<?php

namespace StorePulse\ConversionTracking\Settings;

use StorePulse\ConversionTracking\Access\CustomScripts;
use StorePulse\ConversionTracking\Migration\SettingsMigration;
use StorePulse\ConversionTracking\Platforms\XSettings;

/**
 * Until the 2.x settings page is removed, a save there writes the keys it owns through to the 3.0 settings.
 */
class LegacySync {

    /**
     * 3.0 keys a 2.x save never writes: Pause and the X re-setup flags belong to 3.0.
     */
    const SKIP_KEYS = array( 'paused', 'needs_resetup' );

    /**
     * Google keys => the 2.x integrations they are read from; written only when the 2.x option holds all of them.
     */
    const GOOGLE_SOURCES = array(
        'measurement_id'     => array( 'ga4' ),
        'ads_id'             => array( 'adwords' ),
        'purchase_label'     => array( 'adwords' ),
        'registration_label' => array( 'adwords' ),
        'mode'               => array( 'ga4', 'adwords' ),
    );

    /**
     * Set while a sync writes, so it never runs inside itself.
     *
     * @var bool
     */
    private static $running = false;

    /**
     * Hook the writes of the 2.x option.
     *
     * @return void
     */
    public static function register() {
        add_action( 'add_option_' . Store::V1_OPTION, array( __CLASS__, 'added' ), 10, 2 );
        add_action( 'update_option_' . Store::V1_OPTION, array( __CLASS__, 'updated' ), 10, 2 );
    }

    /**
     * The 2.x option was created.
     *
     * @param string $option Option name.
     * @param mixed  $value  Saved value.
     *
     * @return void
     */
    public static function added( $option, $value ) {
        self::sync( $value );
    }

    /**
     * The 2.x option changed.
     *
     * @param mixed $old   Previous value.
     * @param mixed $value Saved value.
     *
     * @return void
     */
    public static function updated( $old, $value ) {
        self::sync( $value );
    }

    /**
     * Write a 2.x save through to the 3.0 settings, once they exist and no migration is writing them.
     *
     * @param mixed      $v1      The saved wcct_settings option.
     * @param array|null $shown   2.x integration => event keys its settings page shows; read from the integrations by default.
     * @param bool|null  $scripts Whether the user may change custom scripts; the current user's capability by default.
     *
     * @return void
     */
    public static function sync( $v1, ?array $shown = null, ?bool $scripts = null ) {
        if ( self::$running ) {
            return;
        }

        $doc = get_option( Store::OPTION, null );

        // Before the first 3.0 write the storefront reads the 2.x option itself; the migration writes its own result.
        if ( ! is_array( $doc ) || null !== get_option( SettingsMigration::LOCK, null ) ) {
            return;
        }

        self::$running = true;

        try {
            $shown   = null === $shown ? self::shown_events() : $shown;
            $scripts = null === $scripts ? current_user_can( CustomScripts::CAPABILITY ) : $scripts;

            Store::save( self::apply( $doc, $v1, $shown, $scripts ) );
        } finally {
            self::$running = false;
        }
    }

    /**
     * The 3.0 settings with the keys of each platform the 2.x option holds replaced; every other key is kept.
     *
     * @param array $doc     3.0 settings.
     * @param mixed $v1      The wcct_settings option.
     * @param array $shown   2.x integration => event keys its settings page shows.
     * @param bool  $scripts Whether custom scripts may change.
     *
     * @return array
     */
    public static function apply( array $doc, $v1, array $shown, bool $scripts ): array {
        $v1   = is_array( $v1 ) ? $v1 : array();
        $view = LegacyView::from_v1( $v1 );

        if ( empty( $view['platforms'] ) || ! is_array( $view['platforms'] ) ) {
            return $doc;
        }

        $doc['platforms'] = isset( $doc['platforms'] ) && is_array( $doc['platforms'] ) ? $doc['platforms'] : array();

        foreach ( $view['platforms'] as $id => $values ) {
            if ( ! is_array( $values ) ) {
                continue;
            }

            $stored = isset( $doc['platforms'][ $id ] ) && is_array( $doc['platforms'][ $id ] ) ? $doc['platforms'][ $id ] : array();
            $owned  = self::owned_events( (string) $id, $shown );

            foreach ( $values as $key => $value ) {
                if ( ! self::owns( (string) $id, (string) $key, $v1, $owned, $scripts ) ) {
                    continue;
                }

                $stored[ $key ] = 'events' === $key ? self::merge_events( $stored, $value, $owned ) : $value;
            }

            $doc['platforms'][ $id ] = $stored;
        }

        return $doc;
    }

    /**
     * Whether the 2.x page owns a key of a platform.
     *
     * @param string     $id      Platform ID.
     * @param string     $key     3.0 key.
     * @param array      $v1      The wcct_settings option.
     * @param array|null $owned   Events the page shows, null when unknown.
     * @param bool       $scripts Whether custom scripts may change.
     *
     * @return bool
     */
    private static function owns( string $id, string $key, array $v1, ?array $owned, bool $scripts ): bool {
        if ( in_array( $key, self::SKIP_KEYS, true ) ) {
            return false;
        }

        if ( 'google' === $id && isset( self::GOOGLE_SOURCES[ $key ] ) ) {
            return ! array_diff( self::GOOGLE_SOURCES[ $key ], array_keys( $v1 ) );
        }

        // An X event ID belongs to the page only when it shows that event.
        $x_event = 'twitter' === $id ? array_search( $key, XSettings::EVENT_ID_KEYS, true ) : false;

        if ( false !== $x_event ) {
            return null === $owned || in_array( $x_event, $owned, true );
        }

        // CustomScripts::keep() put the stored 2.x scripts back; the 3.0 ones stay as they are.
        return 'custom' !== $id || $scripts || '_script' !== substr( $key, -7 );
    }

    /**
     * Platform events the 2.x page shows a checkbox for; null for a platform this class has no map of.
     *
     * @param string $id    Platform ID.
     * @param array  $shown 2.x integration => event keys shown.
     *
     * @return array|null
     */
    private static function owned_events( string $id, array $shown ): ?array {
        $maps = array(
            'facebook' => array( 'facebook' => LegacyView::FACEBOOK_EVENTS ),
            'google'   => array(
                'ga4'     => Google::V1_ANALYTICS_EVENTS,
                'adwords' => Google::V1_ADS_EVENTS,
            ),
            'twitter'  => array( 'twitter' => array_flip( XSettings::V1_SWITCHES ) ),
        );

        if ( ! isset( $maps[ $id ] ) ) {
            return null;
        }

        $owned = array();

        foreach ( $maps[ $id ] as $integration => $map ) {
            $keys  = isset( $shown[ $integration ] ) && is_array( $shown[ $integration ] ) ? $shown[ $integration ] : array();
            $owned = array_merge( $owned, array_values( array_intersect_key( $map, array_flip( $keys ) ) ) );
        }

        return array_values( array_unique( $owned ) );
    }

    /**
     * Stored events with the owned ones switched as the 2.x save has them.
     *
     * @param array      $stored Stored platform values.
     * @param mixed      $events Events from the 2.x save.
     * @param array|null $owned  Owned events, null for all.
     *
     * @return array
     */
    private static function merge_events( array $stored, $events, ?array $owned ): array {
        $events = is_array( $events ) ? $events : array();

        if ( null === $owned ) {
            return array_values( $events );
        }

        $before = isset( $stored['events'] ) && is_array( $stored['events'] ) ? $stored['events'] : array();

        return array_values( array_unique( array_merge( array_diff( $before, $owned ), array_intersect( $events, $owned ) ) ) );
    }

    /**
     * Event checkbox keys of each integration's 2.x settings page, from the manager's integrations.
     *
     * @return array integration ID => keys
     */
    private static function shown_events(): array {
        if ( ! function_exists( 'wcct_init' ) || ! isset( wcct_init()->manager ) ) {
            return array();
        }

        return self::page_events( (array) wcct_init()->manager->get_integrations() );
    }

    /**
     * Event checkbox keys the 2.x settings page lets the user change, per integration; Pro teasers are left out.
     *
     * @param array $integrations WCCT_Integration objects.
     *
     * @return array integration ID => keys
     */
    public static function page_events( array $integrations ): array {
        $shown = array();

        foreach ( $integrations as $integration ) {
            if ( ! $integration instanceof \WCCT_Integration ) {
                continue;
            }

            foreach ( (array) $integration->get_settings() as $field ) {
                if ( ! is_array( $field ) || ! isset( $field['name'], $field['options'] ) || 'events' !== $field['name'] || ! is_array( $field['options'] ) ) {
                    continue;
                }

                foreach ( $field['options'] as $key => $option ) {
                    if ( ! is_array( $option ) || empty( $option['profeature'] ) ) {
                        $shown[ $integration->get_id() ][] = (string) $key;
                    }
                }
            }
        }

        return $shown;
    }
}
