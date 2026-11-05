<?php

namespace StorePulse\ConversionTracking\Migration;

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
     * Run the migration if it is due; hooked early on every request.
     *
     * @return void
     */
    public static function maybe_run() {
        // One query instead of two when neither option is autoloaded (WordPress 6.4+).
        if ( function_exists( 'wp_prime_option_caches' ) ) {
            wp_prime_option_caches( array( self::MARKER, self::V1_OPTION ) );
        }

        $trigger = self::trigger();

        if ( Trigger::FAILED === $trigger->maybe_run() && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'Conversion Tracking: settings migration failed: ' . $trigger->error()->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }
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
     * The migration body.
     *
     * @return void
     */
    public function run() {
        // Filled by task 4.1.
    }
}
