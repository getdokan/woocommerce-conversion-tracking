<?php

namespace StorePulse\ConversionTracking\Migration;

/**
 * "Needs attention" items the 3.0 migration leaves for the What's new page; none means everything carried over.
 */
class Flags {

    /**
     * Option holding the flags: flag ID => { platform, fields, ... }. Missing on new installs and clean upgrades.
     */
    const OPTION = 'wcct_migration_flags';

    /**
     * The 2.x Ads ID held a conversion label ("AW-123/label"); the migration split it.
     */
    const GOOGLE_ADS_ID_SPLIT = 'google_ads_id_split';

    /**
     * X events 2.x sent without an event ID; each needs one now.
     */
    const X_EVENT_IDS = 'twitter_event_ids';

    /**
     * Flags the Lite migration writes.
     */
    const LITE = array( self::GOOGLE_ADS_ID_SPLIT, self::X_EVENT_IDS );

    /**
     * Every stored flag.
     *
     * @return array Flag ID => flag.
     */
    public static function all(): array {
        $flags = get_option( self::OPTION, array() );

        return is_array( $flags ) ? array_filter( $flags, 'is_array' ) : array();
    }

    /**
     * Store a migration's flags: the IDs it owns are replaced, other flags (say, Pro's) are kept.
     *
     * @param array    $flags Flag ID => flag.
     * @param string[] $owned Flag IDs the caller writes; those missing from $flags are removed, so a re-run drops a fixed one.
     *
     * @return void
     */
    public static function save( array $flags, array $owned ) {
        $all = array_merge( array_diff_key( self::all(), array_flip( $owned ) ), array_filter( $flags, 'is_array' ) );

        if ( ! $all ) {
            delete_option( self::OPTION );

            return;
        }

        // Read only by What's new, so not autoloaded.
        update_option( self::OPTION, $all, false );
    }
}
