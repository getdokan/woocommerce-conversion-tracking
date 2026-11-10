<?php

namespace StorePulse\ConversionTracking\Platforms;

use StorePulse\ConversionTracking\Settings\LegacyView;
use StorePulse\ConversionTracking\Settings\Store;

/**
 * Pause tracking per platform; the wcct_paused_platforms option is the one place a pause is stored.
 */
class Pause {

    /**
     * Autoloaded list of paused platform IDs (settings platform IDs; ga4 or adwords pause only that integration).
     */
    const OPTION = 'wcct_paused_platforms';

    /**
     * When each pause started: ID => Unix time; written by set(), not autoloaded (the Activity log reads it).
     */
    const SINCE_OPTION = 'wcct_paused_since';

    /**
     * Fired after a platform is paused or resumed.
     */
    const CHANGED_ACTION = 'wcct_platform_pause_changed';

    /**
     * Integration ID => the settings platform it belongs to, where they differ.
     */
    const INTEGRATIONS = array(
        'ga4'     => 'google',
        'adwords' => 'google',
    );

    /**
     * Paused IDs read from the settings while the option doesn't exist, null until read.
     *
     * @var array|null
     */
    private static $seed = null;

    /**
     * The settings platform an integration belongs to.
     *
     * @param string $id Integration or platform ID.
     *
     * @return string
     */
    public static function platform_of( string $id ): string {
        return isset( self::INTEGRATIONS[ $id ] ) ? self::INTEGRATIONS[ $id ] : $id;
    }

    /**
     * The stored list, null when the option doesn't exist; a malformed value pauses nothing.
     *
     * @return array|null
     */
    public static function stored(): ?array {
        // Autoloaded when Pause writes it, so no query; also read when something saved it without autoload.
        $ids = get_option( self::OPTION, null );

        if ( null === $ids ) {
            return null;
        }

        $ids = maybe_unserialize( $ids );

        return is_array( $ids ) ? array_values( array_unique( array_filter( $ids, 'is_string' ) ) ) : array();
    }

    /**
     * Paused IDs: the option, or the paused flags of the settings until the option is first written.
     *
     * @param array|null $doc Settings to read the flags from; Store::load() by default.
     *
     * @return string[]
     */
    public static function ids( ?array $doc = null ): array {
        $stored = self::stored();

        if ( null !== $stored ) {
            return $stored;
        }

        if ( null !== $doc ) {
            return self::from_doc( $doc );
        }

        if ( null === self::$seed ) {
            self::$seed = self::from_doc( Store::load() );

            // A save later in the same request is read again.
            if ( function_exists( 'add_action' ) ) {
                foreach ( array( Store::OPTION, Store::V1_OPTION, self::OPTION ) as $option ) {
                    add_action( 'add_option_' . $option, array( self::class, 'reset' ) );
                    add_action( 'update_option_' . $option, array( self::class, 'reset' ) );
                    add_action( 'delete_option_' . $option, array( self::class, 'reset' ) );
                }
            }
        }

        return self::$seed;
    }

    /**
     * Platform IDs flagged paused in a settings document: 2.x integrations switched off, read by LegacyView.
     *
     * @param array $doc Settings.
     *
     * @return string[]
     */
    public static function from_doc( array $doc ): array {
        $platforms = isset( $doc['platforms'] ) && is_array( $doc['platforms'] ) ? $doc['platforms'] : array();
        $ids       = array();

        foreach ( $platforms as $id => $values ) {
            if ( is_string( $id ) && is_array( $values ) && ! empty( $values['paused'] ) ) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Whether an integration (or a whole platform) is paused; Google's ga4 and adwords follow the google platform.
     *
     * @param string     $id  Integration or platform ID.
     * @param array|null $doc Settings to read the flags from while the option doesn't exist.
     *
     * @return bool
     */
    public static function is_paused( string $id, ?array $doc = null ): bool {
        $ids    = self::ids( $doc );
        $paused = in_array( $id, $ids, true ) || in_array( self::platform_of( $id ), $ids, true );

        /**
         * Whether a platform is paused.
         *
         * @since 3.0.0
         *
         * @param bool   $paused Whether the ID or its platform is in the wcct_paused_platforms option.
         * @param string $id     Integration ID.
         */
        return (bool) apply_filters( 'wcct_platform_paused', $paused, $id );
    }

    /**
     * Whether a settings platform shows as paused: listed itself, or every one of its integrations is.
     *
     * @param string     $platform Platform ID.
     * @param array|null $doc      Settings to read the flags from while the option doesn't exist.
     *
     * @return bool
     */
    public static function is_platform_paused( string $platform, ?array $doc = null ): bool {
        $ids          = self::ids( $doc );
        $integrations = array_keys( self::INTEGRATIONS, $platform, true );

        return in_array( $platform, $ids, true ) || ( $integrations && ! array_diff( $integrations, $ids ) );
    }

    /**
     * Pause or resume a platform; resuming Google also clears a pause of ga4 or adwords alone.
     *
     * @param string $platform Platform ID.
     * @param bool   $paused   Pause or resume.
     *
     * @return string[] The new list.
     */
    public static function set( string $platform, bool $paused ): array {
        $remove = array_merge( array( $platform ), array_keys( self::INTEGRATIONS, $platform, true ) );
        $ids    = array_values( array_diff( self::ids(), $remove ) );

        if ( $paused ) {
            $ids[] = $platform;
        }

        // Autoloaded: the storefront reads it on every page.
        update_option( self::OPTION, $ids, true );
        self::record_since( $remove, $paused ? $platform : '' );

        if ( ! $paused ) {
            self::switch_on_v1( $platform );
        }
        self::reset();
        GoogleTag::reset();

        /**
         * Fires after a platform is paused or resumed.
         *
         * @since 3.0.0
         *
         * @param string   $platform Platform ID.
         * @param bool     $paused   Whether it is paused now.
         * @param string[] $ids      Every paused ID.
         */
        do_action( self::CHANGED_ACTION, $platform, $paused, $ids );

        return $ids;
    }

    /**
     * When the pause covering an integration or platform started, as a Unix time; 0 when unknown (paused before 3.0 kept the time).
     *
     * @param string $id Integration or platform ID.
     *
     * @return int
     */
    public static function paused_since( string $id ): int {
        $since = get_option( self::SINCE_OPTION, array() );
        $since = is_array( $since ) ? $since : array();

        foreach ( array( $id, self::platform_of( $id ) ) as $key ) {
            if ( isset( $since[ $key ] ) && is_numeric( $since[ $key ] ) ) {
                return (int) $since[ $key ];
            }
        }

        return 0;
    }

    /**
     * Drop the start times of the IDs a set() touched and stamp the one it paused.
     *
     * @param string[] $removed IDs whose pause ends or restarts.
     * @param string   $paused  ID paused now, empty on resume.
     *
     * @return void
     */
    private static function record_since( array $removed, string $paused ) {
        $stored = get_option( self::SINCE_OPTION, array() );
        $stored = is_array( $stored ) ? $stored : array();
        $since  = array_diff_key( $stored, array_flip( $removed ) );

        // Pausing again keeps the first start time.
        if ( '' !== $paused ) {
            $since[ $paused ] = isset( $stored[ $paused ] ) ? (int) $stored[ $paused ] : time();
        }

        update_option( self::SINCE_OPTION, $since, false );
    }

    /**
     * Turn a 2.x integration switched off with its ID saved (read as paused, see LegacyView) back on, so Resume reaches the storefront.
     *
     * @param string $platform Platform ID.
     *
     * @return void
     */
    private static function switch_on_v1( string $platform ) {
        $v1   = get_option( Store::V1_OPTION, null );
        $view = is_array( $v1 ) ? LegacyView::from_v1( $v1 ) : array();

        if ( empty( $view['platforms'][ $platform ]['paused'] ) ) {
            return;
        }

        $values       = $view['platforms'][ $platform ];
        $integrations = array( $platform );

        // Google is two 2.x integrations; only those with an ID saved.
        if ( 'google' === $platform ) {
            $integrations = array();
            $integrations = ! empty( $values['measurement_id'] ) ? array_merge( $integrations, array( 'ga4' ) ) : $integrations;
            $integrations = ! empty( $values['ads_id'] ) ? array_merge( $integrations, array( 'adwords' ) ) : $integrations;
        }

        foreach ( $integrations as $id ) {
            if ( isset( $v1[ $id ] ) && is_array( $v1[ $id ] ) ) {
                // The value the 2.x settings page saves for a ticked switch.
                $v1[ $id ]['enabled'] = '1';
            }
        }

        update_option( Store::V1_OPTION, $v1 );
    }

    /**
     * Forget the flags read in this request.
     *
     * @return void
     */
    public static function reset() {
        self::$seed = null;
    }
}
