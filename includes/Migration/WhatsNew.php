<?php

namespace StorePulse\ConversionTracking\Migration;

use StorePulse\ConversionTracking\Backup\Snapshot;
use StorePulse\ConversionTracking\Consent\ConsentMode;
use StorePulse\ConversionTracking\Install;
use StorePulse\ConversionTracking\Onboarding\Redirect;
use StorePulse\ConversionTracking\Platforms\Pause;
use StorePulse\ConversionTracking\Platforms\XSettings;
use StorePulse\ConversionTracking\REST\BackupController;
use StorePulse\ConversionTracking\Settings\Schema;
use StorePulse\ConversionTracking\Settings\Store;

/**
 * The What's new page for upgraders: built from the migration flags, the pre-3.0 snapshot and the live settings.
 */
class WhatsNew {

    /**
     * Option holding { dismissed: unix time, flags: flag IDs present then, confirmed: flag IDs marked "Looks right" }; not autoloaded.
     */
    const OPTION = 'wcct_whats_new';

    /**
     * Filter of the "Needs attention" items; Lite describes its own flags, Pro adds items for its flags here.
     */
    const ITEMS_FILTER = 'wcct_whats_new_attention';

    /**
     * Filter of the plain notes shown under the heading (Pro: "Pro 3.0 is active").
     */
    const NOTES_FILTER = 'wcct_whats_new_notes';

    /**
     * Platform labels used when the schema doesn't list the platform (say, Microsoft while Pro is off).
     */
    const FALLBACK_LABELS = array(
        'facebook' => 'Meta',
        'google'   => 'Google',
        'twitter'  => 'X',
        'bing_ads' => 'Microsoft',
        'custom'   => 'Custom script',
    );

    /**
     * Whether the store upgraded from 2.x and the migration finished: a frozen snapshot and the 3.0 settings exist, and neither migration (Lite's or Pro's) failed.
     *
     * @return bool
     */
    public static function is_available(): bool {
        $snapshot = get_option( Snapshot::SNAPSHOT_OPTION, null );

        return Install::is_upgrade() && is_array( $snapshot ) && $snapshot && false !== get_option( SettingsMigration::OPTION, false ) && ! self::migration_failed();
    }

    /**
     * Whether a migration failed and waits for Retry (wcct_migration_error, any source).
     *
     * @return bool
     */
    public static function migration_failed(): bool {
        return false !== get_option( SettingsMigration::ERROR_OPTION, false );
    }

    /**
     * Send the merchant here once, on the next admin page; called by the migration on an upgrade.
     *
     * @return bool Whether it was queued.
     */
    public static function queue_redirect(): bool {
        return Redirect::queue_whats_new();
    }

    /**
     * Whether "Done, show me the dashboard" was pressed and no flag appeared since (Pro may update and migrate later than Free).
     *
     * @param string[] $open IDs of the items still open.
     *
     * @return bool
     */
    public static function is_dismissed( array $open = array() ): bool {
        $state = self::state();

        if ( empty( $state['dismissed'] ) ) {
            return false;
        }

        return ! array_diff( $open, $state['flags'] );
    }

    /**
     * Flag IDs the merchant marked "Looks right"; their items stay closed.
     *
     * @return string[]
     */
    public static function confirmed(): array {
        return self::state()['confirmed'];
    }

    /**
     * Close an item whose value was already right ("Looks right").
     *
     * @param string $id Flag ID.
     *
     * @return void
     */
    public static function confirm( string $id ) {
        $state = self::state();

        $state['confirmed'] = array_values( array_unique( array_merge( $state['confirmed'], array( $id ) ) ) );

        update_option( self::OPTION, $state, false );
    }

    /**
     * Hide the dashboard banner until a new flag appears; the page itself stays reachable.
     *
     * @param int $time Unix time.
     *
     * @return void
     */
    public static function dismiss( int $time ) {
        $state = array_merge(
            self::state(),
            array(
                'dismissed' => $time,
                'flags'     => array_keys( Flags::all() ),
            )
        );

        update_option( self::OPTION, $state, false );
        self::seen();
    }

    /**
     * The stored state, cleaned.
     *
     * @return array { dismissed: int, flags: string[], confirmed: string[] }
     */
    private static function state(): array {
        $raw   = get_option( self::OPTION, array() );
        $raw   = is_array( $raw ) ? $raw : array();
        $state = array( 'dismissed' => isset( $raw['dismissed'] ) ? (int) $raw['dismissed'] : 0 );

        foreach ( array( 'flags', 'confirmed' ) as $key ) {
            $state[ $key ] = isset( $raw[ $key ] ) && is_array( $raw[ $key ] ) ? array_values( array_filter( $raw[ $key ], 'is_string' ) ) : array();
        }

        return $state;
    }

    /**
     * The page was opened: a queued redirect is no longer needed.
     *
     * @return void
     */
    public static function seen() {
        Redirect::drop_whats_new();
    }

    /**
     * Everything the page shows.
     *
     * @return array
     */
    public static function data(): array {
        $snapshot = get_option( Snapshot::SNAPSHOT_OPTION, array() );
        $snapshot = is_array( $snapshot ) ? $snapshot : array();
        $v1       = isset( $snapshot[ SettingsMigration::V1_OPTION ] ) ? $snapshot[ SettingsMigration::V1_OPTION ] : array();
        $doc      = Store::load();
        $labels   = self::labels();
        $flags    = Flags::all();
        $items    = self::attention( $flags, $doc );

        /**
         * Filter the What's new "Needs attention" items, one per migration flag still open; a flag no item describes isn't shown.
         *
         * @since 3.0.0
         *
         * @param array $items { id, platform, fields, title, text, action, url, confirmable }; confirmable items get "Looks right".
         * @param array $flags The wcct_migration_flags option.
         */
        $items = apply_filters( self::ITEMS_FILTER, $items, $flags );

        /**
         * Filter the What's new notes; text may wrap a link in <link></link>, which opens url.
         *
         * @since 3.0.0
         *
         * @param array $notes    { id, title, text, url }.
         * @param array $snapshot The pre-3.0 options.
         */
        $notes = apply_filters( self::NOTES_FILTER, array(), $snapshot );
        $items = self::open_items( self::clean_items( $items ), self::confirmed() );

        return array(
            'state'        => $items ? 'attention' : 'done',
            'attention'    => $items,
            'paused'       => self::paused( $v1, Pause::ids(), $labels ),
            'notes'        => self::clean_notes( $notes ),
            'extra_pixels' => self::extra_pixels( $v1 ),
            'consent'      => ConsentMode::is_enabled(),
            'backup'       => null !== Snapshot::load( array( BackupController::class, 'read_option' ) ),
            'dismissed'    => self::is_dismissed( array_column( $items, 'id' ) ),
            'seen'         => ! Redirect::whats_new_pending(),
        );
    }

    /**
     * One "Needs attention" item per open Lite flag: the X flag closes once every event has its ID, the Google one once its fields change. Other flags are left to the filter (Pro).
     *
     * @param array $flags Flag ID => { platform, fields, ... }.
     * @param array $doc   Stored 3.0 settings.
     *
     * @return array
     */
    public static function attention( array $flags, array $doc ): array {
        $platforms = isset( $doc['platforms'] ) && is_array( $doc['platforms'] ) ? $doc['platforms'] : array();
        $items     = array();

        foreach ( $flags as $id => $flag ) {
            if ( ! in_array( $id, Flags::LITE, true ) || ! is_array( $flag ) || ! isset( $flag['platform'] ) || ! is_string( $flag['platform'] ) ) {
                continue;
            }

            $platform = $flag['platform'];
            $stored   = isset( $platforms[ $platform ] ) && is_array( $platforms[ $platform ] ) ? $platforms[ $platform ] : array();
            $item     = array(
                'id'       => $id,
                'platform' => $platform,
                'fields'   => isset( $flag['fields'] ) && is_array( $flag['fields'] ) ? array_values( array_filter( $flag['fields'], 'is_string' ) ) : array(),
            );

            if ( Flags::GOOGLE_ADS_ID_SPLIT === $id ) {
                if ( self::google_changed( $flag, $stored ) ) {
                    continue;
                }

                $item['title']  = __( 'We split your Google Ads ID into a conversion ID and a label.', 'woocommerce-conversion-tracking' );
                $item['text']   = __( 'Check it looks right.', 'woocommerce-conversion-tracking' );
                $item['action'] = __( 'Check Google', 'woocommerce-conversion-tracking' );
                // A correct split needs no change, so nothing would close it.
                $item['confirmable'] = true;
            }

            if ( Flags::X_EVENT_IDS === $id ) {
                // The flag is written once; the live settings say which events, still switched on, lack an ID.
                $x      = XSettings::from_v3( $stored );
                $events = array_values( array_intersect( $x['needs_resetup'], $x['events'] ) );

                if ( ! $events ) {
                    continue;
                }

                $item['fields'] = array_values( array_intersect_key( XSettings::EVENT_ID_KEYS, array_flip( $events ) ) );
                $item['title']  = __( 'X retired the old event codes.', 'woocommerce-conversion-tracking' );
                $item['text']   = self::x_text( $events );
                $item['action'] = __( 'Set up X', 'woocommerce-conversion-tracking' );
            }

            $item['url'] = self::field_url( $platform, $item['fields'] );
            $items[]     = $item;
        }

        return $items;
    }

    /**
     * Platforms 2.x had switched off that are still paused, so the page can say why.
     *
     * @param mixed    $v1     The wcct_settings option from the snapshot.
     * @param string[] $paused Paused IDs now (wcct_paused_platforms).
     * @param array    $labels Platform ID => label.
     *
     * @return array { id, platform, label, url }
     */
    public static function paused( $v1, array $paused, array $labels ): array {
        $names = array(
            'ga4'     => __( 'Google Analytics', 'woocommerce-conversion-tracking' ),
            'adwords' => __( 'Google Ads', 'woocommerce-conversion-tracking' ),
        );
        $items = array();

        foreach ( array_intersect( LegacyPause::ids( $v1 ), $paused ) as $id ) {
            $platform = Pause::platform_of( $id );

            // A whole Google pause covers its integrations' own entries.
            if ( 'google' !== $id && 'google' === $platform && in_array( 'google', $paused, true ) ) {
                continue;
            }

            $items[] = array(
                'id'       => $id,
                'platform' => $platform,
                'label'    => isset( $names[ $id ] ) ? $names[ $id ] : ( isset( $labels[ $platform ] ) ? $labels[ $platform ] : $platform ),
                'url'      => '#/platforms/' . $platform,
            );
        }

        return $items;
    }

    /**
     * Extra Meta pixels Pro kept in 2.x (facebook[1..N] with an ID).
     *
     * @param mixed $v1 The wcct_settings option from the snapshot.
     *
     * @return int
     */
    public static function extra_pixels( $v1 ): int {
        $facebook = is_array( $v1 ) && isset( $v1['facebook'] ) && is_array( $v1['facebook'] ) ? $v1['facebook'] : array();
        $count    = 0;

        foreach ( $facebook as $index => $pixel ) {
            if ( is_int( $index ) && $index > 0 && is_array( $pixel ) && isset( $pixel['pixel_id'] ) && is_scalar( $pixel['pixel_id'] ) && '' !== trim( (string) $pixel['pixel_id'] ) ) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Platform ID => label, from the settings schema.
     *
     * @return array
     */
    private static function labels(): array {
        $labels = self::FALLBACK_LABELS;

        foreach ( Schema::get()['platforms'] as $id => $platform ) {
            if ( isset( $platform['label'] ) && is_string( $platform['label'] ) ) {
                $labels[ $id ] = $platform['label'];
            }
        }

        return $labels;
    }

    /**
     * Whether the Ads ID or label no longer hold what the split wrote, so the merchant has been there.
     *
     * @param array $flag   The google_ads_id_split flag.
     * @param array $stored Stored Google settings.
     *
     * @return bool
     */
    private static function google_changed( array $flag, array $stored ): bool {
        foreach ( array( 'ads_id', 'purchase_label' ) as $key ) {
            $split = isset( $flag[ $key ] ) && is_scalar( $flag[ $key ] ) ? (string) $flag[ $key ] : '';
            $now   = isset( $stored[ $key ] ) && is_scalar( $stored[ $key ] ) ? (string) $stored[ $key ] : '';

            if ( $split !== $now ) {
                return true;
            }
        }

        return false;
    }

    /**
     * The line after "X retired the old event codes."
     *
     * @param string[] $events Pipeline events that need an event ID.
     *
     * @return string
     */
    private static function x_text( array $events ): string {
        if ( array( 'purchase' ) === $events ) {
            return __( 'Purchases need a new event ID.', 'woocommerce-conversion-tracking' );
        }

        $count = count( $events );

        /* translators: %d: number of X events */
        return sprintf( _n( '%d event needs a new event ID.', '%d events need a new event ID each.', $count, 'woocommerce-conversion-tracking' ), $count );
    }

    /**
     * The platform page, opened at the first field to check.
     *
     * @param string   $platform Platform ID.
     * @param string[] $fields   Fields.
     *
     * @return string
     */
    private static function field_url( string $platform, array $fields ): string {
        $url = '#/platforms/' . $platform;

        return $fields ? $url . '?field=' . rawurlencode( $fields[0] ) : $url;
    }

    /**
     * The items not marked "Looks right".
     *
     * @param array    $items     Clean items.
     * @param string[] $confirmed Confirmed flag IDs.
     *
     * @return array
     */
    public static function open_items( array $items, array $confirmed ): array {
        return array_values(
            array_filter(
                $items,
                function ( $item ) use ( $confirmed ) {
                    return ! in_array( $item['id'], $confirmed, true );
                }
            )
        );
    }

    /**
     * Items with the keys the page reads, as strings.
     *
     * @param mixed $items Filtered items.
     *
     * @return array
     */
    private static function clean_items( $items ): array {
        $clean = array();

        foreach ( is_array( $items ) ? $items : array() as $item ) {
            if ( ! is_array( $item ) || empty( $item['id'] ) || empty( $item['title'] ) ) {
                continue;
            }

            $clean[] = array(
                'id'       => (string) $item['id'],
                'platform' => isset( $item['platform'] ) ? (string) $item['platform'] : '',
                'fields'   => isset( $item['fields'] ) && is_array( $item['fields'] ) ? array_values( array_map( 'strval', $item['fields'] ) ) : array(),
                'title'    => (string) $item['title'],
                'text'     => isset( $item['text'] ) ? (string) $item['text'] : '',
                'action'   => isset( $item['action'] ) ? (string) $item['action'] : '',
                'url'         => isset( $item['url'] ) ? (string) $item['url'] : '',
                'confirmable' => ! empty( $item['confirmable'] ),
            );
        }

        return $clean;
    }

    /**
     * Notes with the keys the page reads, as strings.
     *
     * @param mixed $notes Filtered notes.
     *
     * @return array
     */
    private static function clean_notes( $notes ): array {
        $clean = array();

        foreach ( is_array( $notes ) ? $notes : array() as $note ) {
            if ( ! is_array( $note ) || empty( $note['id'] ) || ( empty( $note['title'] ) && empty( $note['text'] ) ) ) {
                continue;
            }

            $clean[] = array(
                'id'    => (string) $note['id'],
                'title' => isset( $note['title'] ) ? (string) $note['title'] : '',
                'text'  => isset( $note['text'] ) ? (string) $note['text'] : '',
                'url'   => isset( $note['url'] ) ? (string) $note['url'] : '',
            );
        }

        return $clean;
    }
}
