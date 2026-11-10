<?php

namespace StorePulse\ConversionTracking\Migration;

use StorePulse\ConversionTracking\Backup\Snapshot;
use StorePulse\ConversionTracking\Cache\PageCache;
use StorePulse\ConversionTracking\Events\ProductId;
use StorePulse\ConversionTracking\Events\PurchaseGate;
use StorePulse\ConversionTracking\Events\ValueMode;
use StorePulse\ConversionTracking\Install;
use StorePulse\ConversionTracking\Platforms\XSettings;
use StorePulse\ConversionTracking\Privacy\CustomerDetails;
use StorePulse\ConversionTracking\Settings\Google;
use StorePulse\ConversionTracking\Settings\LegacyView;
use StorePulse\ConversionTracking\Settings\Store;

/**
 * Moves 2.x settings into the 3.0 options on the first request after an update.
 */
class SettingsMigration {

    /**
     * The 2.x settings option, kept read-only for one release.
     */
    const V1_OPTION = 'wcct_settings';

    /**
     * The 3.0 settings option.
     */
    const OPTION = 'wcct_settings_v3';

    /**
     * Written once the migration has run; holds the plugin version that ran it.
     */
    const MARKER = 'wcct_migration_done';

    /**
     * Lock taken while the migration runs.
     */
    const LOCK = 'wcct_migration_lock';

    /**
     * The last failed run: { message, time, source }; source is lite, or pro for Pro's migration. Removed once that run succeeds.
     */
    const ERROR_OPTION = 'wcct_migration_error';

    /**
     * Fired by retry() after Lite's run, so Pro retries its own.
     */
    const RETRY_ACTION = 'wcct_migration_retry';

    /**
     * Filter of whether a migration Retry can still finish is due, besides Lite's own.
     */
    const RETRY_PENDING_FILTER = 'wcct_migration_retry_pending';

    /**
     * Filter of the migrated 3.0 settings, before they are written.
     */
    const FILTER = 'wcct_migrated_settings';

    /**
     * 2.x options frozen in the snapshot, Pro's included even while Pro is off (its access route reads wcct_permissions).
     */
    const SNAPSHOT_OPTIONS = array( 'wcct_settings', 'wcct_data_feed', 'wcct_permissions' );

    /**
     * Platforms that get the upgraders' defaults => the integration their value mode is for.
     */
    const UPGRADE_PLATFORMS = array(
        'facebook' => 'facebook',
        'google'   => 'adwords',
        'twitter'  => 'twitter',
        'bing_ads' => 'bing_ads',
    );

    /**
     * "Skip these roles" field of the Meta stream (#93); 2.x skipped nobody, so upgraders keep tracking everyone.
     */
    const SKIP_ROLES_FIELD   = 'skip_roles';
    const SKIP_ROLES_UPGRADE = 'none';

    /**
     * Run the migration if it is due; called early on every request.
     *
     * @param OptionStore|null $store Option store, the WordPress one by default.
     *
     * @return string One of the Trigger status constants.
     */
    public static function maybe_run( ?OptionStore $store = null ): string {
        // One query instead of two when neither option is autoloaded (WordPress 6.4+).
        if ( function_exists( 'wp_prime_option_caches' ) ) {
            wp_prime_option_caches( array( self::MARKER, self::V1_OPTION ) );
        }

        $store = $store ? $store : new WpOptionStore();

        self::heal( $store );

        return self::report( self::trigger( $store ) );
    }

    /**
     * Finish a run that wrote the 3.0 settings but died before its marker, so its lock can't block 2.x saves for good.
     *
     * @param OptionStore $store Option store.
     *
     * @return bool Whether it had to.
     */
    public static function heal( OptionStore $store ): bool {
        if ( $store->exists( self::MARKER ) || ! $store->exists( self::OPTION ) || ! $store->exists( self::V1_OPTION ) ) {
            return false;
        }

        $store->save( self::MARKER, defined( 'WCCT_VERSION' ) ? WCCT_VERSION : '' );

        $held = $store->read( self::LOCK );

        if ( null !== $held ) {
            $store->delete( self::LOCK, $held );
        }

        return true;
    }

    /**
     * Run again after a failure: drop the lock the failed run kept, then run if still due.
     *
     * @param OptionStore|null $store Option store, the WordPress one by default.
     *
     * @return string One of the Trigger status constants.
     */
    public static function retry( ?OptionStore $store = null ): string {
        $store = $store ? $store : new WpOptionStore();
        $error = get_option( self::ERROR_OPTION, array() );
        $held  = $store->read( self::LOCK );

        // Only a lock taken before the failure; a newer run keeps its own.
        if ( null !== $held && is_array( $error ) && isset( $error['time'] ) && (int) $held <= (int) $error['time'] ) {
            $store->delete( self::LOCK, $held );
        }

        $status = self::report( self::trigger( $store ) );

        /**
         * Fires when the merchant retries the migration, after Lite's run.
         *
         * @since 3.0.0
         *
         * @param string $status Lite's Trigger status.
         */
        do_action( self::RETRY_ACTION, $status );

        return $status;
    }

    /**
     * Whether a failed migration is still due: Lite's, or one added through the filter (Pro's).
     *
     * @return bool
     */
    public static function retry_pending(): bool {
        if ( Store::migration_pending() ) {
            return true;
        }

        /**
         * Filter whether another migration the Retry notice can finish is still due.
         *
         * @since 3.0.0
         *
         * @param bool $pending Whether one is due.
         */
        return (bool) apply_filters( self::RETRY_PENDING_FILTER, false );
    }

    /**
     * Run a trigger and record a failure for the Retry notice, or clear it after a run.
     *
     * @param Trigger $trigger Trigger.
     *
     * @return string One of the Trigger status constants.
     */
    private static function report( Trigger $trigger ): string {
        $status = $trigger->maybe_run();

        if ( Trigger::FAILED === $status ) {
            $message = $trigger->error() ? $trigger->error()->getMessage() : '';

            $failure = array(
                'message' => $message,
                'time'    => time(),
                'source'  => 'lite',
            );

            update_option( self::ERROR_OPTION, $failure, false );

            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'Conversion Tracking: settings migration failed: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            }
        } elseif ( Trigger::RAN === $status && false !== get_option( self::ERROR_OPTION, false ) ) {
            delete_option( self::ERROR_OPTION );
        }

        return $status;
    }

    /**
     * Build the trigger for the Lite settings migration.
     *
     * @param OptionStore|null $store Option store, the WordPress one by default.
     *
     * @return Trigger
     */
    public static function trigger( ?OptionStore $store = null ): Trigger {
        $store   = $store ? $store : new WpOptionStore();
        $version = defined( 'WCCT_VERSION' ) ? WCCT_VERSION : '';

        return new Trigger( $store, new Lock( $store, self::LOCK ), self::MARKER, array( self::class, 'is_pending' ), array( new self(), 'run' ), $version );
    }

    /**
     * 2.x settings exist and 3.0 settings don't.
     *
     * @param OptionStore $store Option store.
     * @param bool        $fresh Read the database instead of the cache.
     *
     * @return bool
     */
    public static function is_pending( OptionStore $store, bool $fresh = false ): bool {
        return $store->exists( self::V1_OPTION, $fresh ) && ! $store->exists( self::OPTION, $fresh );
    }

    /**
     * The migration body: the 2.x option is only read, and the 3.0 settings are written last, so a failure leaves it due.
     *
     * @return void
     */
    public function run() {
        // Each pause asks for a page-cache purge; the migration purges once, after the 3.0 settings.
        PageCache::hold();

        try {
            $this->migrate();
        } finally {
            PageCache::release();
        }
    }

    /**
     * The body's writes, in order.
     *
     * @return void
     */
    private function migrate() {
        $v1      = get_option( self::V1_OPTION, array() );
        $upgrade = Install::is_upgrade();

        // Orders from before 3.0 count as sent; written once, never moved by a re-run.
        PurchaseGate::ensure_since();

        if ( $upgrade ) {
            self::snapshot();
        }

        $result = self::map( $v1, $upgrade );

        Flags::save( $result['flags'], Flags::LITE );

        LegacyPause::migrate( $v1 );

        /**
         * Filter the 3.0 settings the migration writes. Pauses are already in wcct_paused_platforms and can't change here.
         *
         * @since 3.0.0
         *
         * @param array $settings 3.0 settings.
         * @param mixed $v1       The wcct_settings option.
         * @param bool  $upgrade  Whether the store was upgraded from 2.x.
         */
        $settings = apply_filters( self::FILTER, $result['settings'], $v1, $upgrade );

        Store::save( is_array( $settings ) ? $settings : $result['settings'] );
    }

    /**
     * The 3.0 settings and "Needs attention" flags for a 2.x option.
     *
     * @param mixed $v1      The wcct_settings option.
     * @param bool  $upgrade Whether the store was upgraded from 2.x; a new install gets no upgrade defaults and no flags.
     *
     * @return array { settings, flags }
     */
    public static function map( $v1, bool $upgrade ): array {
        $settings = LegacyPause::apply( self::flat_custom( LegacyView::from_v1( $v1 ), $v1 ), $v1 );

        $settings['platforms'] = isset( $settings['platforms'] ) && is_array( $settings['platforms'] ) ? $settings['platforms'] : array();

        // A pause lives in wcct_paused_platforms (LegacyPause::migrate()), not in the platform values.
        foreach ( $settings['platforms'] as $id => $values ) {
            if ( is_array( $values ) ) {
                unset( $settings['platforms'][ $id ]['paused'] );
            }
        }

        if ( ! $upgrade ) {
            return array(
                'settings' => $settings,
                'flags'    => array(),
            );
        }

        foreach ( self::UPGRADE_PLATFORMS as $platform => $integration ) {
            if ( isset( $settings['platforms'][ $platform ] ) && is_array( $settings['platforms'][ $platform ] ) ) {
                $settings['platforms'][ $platform ] += self::upgrade_defaults( $integration );
            }
        }

        return array(
            'settings' => $settings,
            'flags'    => self::flags( is_array( $v1 ) ? $v1 : array(), $settings['platforms'] ),
        );
    }

    /**
     * Values an upgrader's platform keeps from 2.x: the order total, the product ID, every role tracked.
     *
     * @param string $integration Integration ID the value mode is for.
     *
     * @return array
     */
    public static function upgrade_defaults( string $integration ): array {
        return array(
            'value_mode'           => ValueMode::default_for( $integration, true ),
            ProductId::FIELD       => ProductId::ID,
            self::SKIP_ROLES_FIELD => self::SKIP_ROLES_UPGRADE,
        );
    }

    /**
     * The 2.0 upgrade saved the 1.x scripts flat at the top of wcct_settings, where 2.x never read them: keep them, switched off.
     *
     * @param array $settings 3.0 settings from LegacyView::from_v1().
     * @param mixed $v1       The wcct_settings option.
     *
     * @return array
     */
    private static function flat_custom( array $settings, $v1 ): array {
        if ( ! is_array( $v1 ) || isset( $v1['custom'] ) || ( ! isset( $v1['checkout'] ) && ! isset( $v1['registration'] ) ) ) {
            return $settings;
        }

        $checkout     = isset( $v1['checkout'] ) && is_string( $v1['checkout'] ) ? $v1['checkout'] : '';
        $registration = isset( $v1['registration'] ) && is_string( $v1['registration'] ) ? $v1['registration'] : '';

        $settings['platforms']['custom'] = array(
            'thankyou_enabled'           => false,
            'thankyou_script'            => $checkout,
            'signup_enabled'             => false,
            'signup_script'              => $registration,
            CustomerDetails::SETTING     => LegacyView::uses_customer_placeholder( $checkout ),
        );

        return $settings;
    }

    /**
     * "Needs attention" flags: the split Google Ads ID, the X events that need an event ID.
     *
     * @param array $v1        The wcct_settings option.
     * @param array $platforms Migrated platforms.
     *
     * @return array Flag ID => flag.
     */
    private static function flags( array $v1, array $platforms ): array {
        $flags = array();
        $ads   = LegacyView::first( $v1, 'adwords' );

        if ( null !== $ads && isset( $platforms['google'] ) ) {
            $saved = isset( $ads['account_id'] ) && is_scalar( $ads['account_id'] ) ? trim( (string) $ads['account_id'] ) : '';
            $label = isset( $ads['events']['Purchase-label'] ) && is_scalar( $ads['events']['Purchase-label'] ) ? trim( (string) $ads['events']['Purchase-label'] ) : '';

            if ( Google::split_ads_id( $saved ) || Google::split_ads_id( $label ) ) {
                $flags[ Flags::GOOGLE_ADS_ID_SPLIT ] = array(
                    'platform'       => 'google',
                    'fields'         => array( 'ads_id', 'purchase_label' ),
                    'saved'          => $saved,
                    'ads_id'         => isset( $platforms['google']['ads_id'] ) ? $platforms['google']['ads_id'] : '',
                    'purchase_label' => isset( $platforms['google']['purchase_label'] ) ? $platforms['google']['purchase_label'] : '',
                );
            }
        }

        $events = isset( $platforms['twitter']['needs_resetup'] ) && is_array( $platforms['twitter']['needs_resetup'] ) ? $platforms['twitter']['needs_resetup'] : array();

        if ( $events ) {
            $flags[ Flags::X_EVENT_IDS ] = array(
                'platform' => 'twitter',
                'fields'   => array_values( array_intersect_key( XSettings::EVENT_ID_KEYS, array_flip( $events ) ) ),
                'events'   => array_values( $events ),
            );
        }

        return $flags;
    }

    /**
     * Freeze the 2.x options for the What's new download and the access route; written once, secrets removed.
     *
     * @return void
     */
    public static function snapshot() {
        if ( false !== get_option( Snapshot::SNAPSHOT_OPTION, false ) ) {
            return;
        }

        $values = Snapshot::collect(
            array_values( array_unique( array_merge( self::SNAPSHOT_OPTIONS, Snapshot::options() ) ) ),
            function ( $name ) {
                return get_option( $name, null );
            }
        );

        add_option( Snapshot::SNAPSHOT_OPTION, $values, '', false );
    }
}
