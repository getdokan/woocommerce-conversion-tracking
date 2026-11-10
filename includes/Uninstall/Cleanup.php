<?php

namespace StorePulse\ConversionTracking\Uninstall;

/**
 * What uninstall removes, and what it leaves for Pro while Pro is still installed.
 */
class Cleanup {

    /**
     * LIKE patterns for every option and transient the plugins write (wcct_*).
     */
    const OPTION_PATTERNS = array(
        'wcct\_%',
        '\_transient\_wcct\_%',
        '\_transient\_timeout\_wcct\_%',
        '\_site\_transient\_wcct\_%',
        '\_site\_transient\_timeout\_wcct\_%',
    );

    /**
     * Options outside the wcct_ prefix: the 1.x WooCommerce integration settings.
     */
    const LEGACY_OPTIONS = array( 'woocommerce_wc_conv_tracking_settings' );

    /**
     * Transients listed by name too, for stores that keep transients in an object cache.
     */
    const TRANSIENTS = array( 'wcct_upgrade_to_20', 'wcct_alert_badge' );

    /**
     * Appsero tracker options, after the plugin folder name.
     */
    const TRACKER_OPTION_SUFFIXES = array( '_allow_tracking', '_tracking_notice', '_tracking_last_send', '_tracking_skipped' );

    /**
     * Appsero tracker cron hook, after the plugin folder name.
     */
    const TRACKER_HOOK_SUFFIX = '_tracker_send_event';

    /**
     * Options Pro writes; Free leaves them while Pro is installed.
     */
    const PRO_OPTIONS = array( 'wcct_permissions', 'wcct_data_feed', 'wcct_secret_feed_password' );

    /**
     * Name prefixes Pro writes under (options, transients, hooks).
     */
    const PRO_PREFIXES = array( 'wcct_pro_', 'wcct_feed_' );

    /**
     * User meta keys, as a LIKE pattern: the pending sign-up flag.
     */
    const USER_META_PATTERN = '\_wcct\_%';

    /**
     * Order meta removed: the thank-you page flag of the order log.
     */
    const ORDER_META = array( '_wcct_thankyou_seen' );

    /**
     * Order meta kept (Default 7): the sent flags, so a reinstall still counts each purchase once. Documentation for the tests; never read at uninstall.
     */
    const KEPT_ORDER_META = array( '_wcct_sent_*', '_wcct_ga4_tracked' );

    /**
     * Product meta of the 1.x per-product conversion code.
     */
    const PRODUCT_META = array( '_wc_conv_track' );

    /**
     * Tables, without the site prefix.
     */
    const TABLES = array( 'wcct_order_log' );

    /**
     * Action Scheduler group of Free (the order log prune).
     */
    const ACTION_GROUPS = array( 'wcct' );

    /**
     * Action Scheduler group of Pro (the catalog feed builds).
     */
    const PRO_ACTION_GROUPS = array( 'wcct-product-feed' );

    /**
     * Prefix of every cron hook the plugins schedule.
     */
    const CRON_PREFIX = 'wcct_';

    /**
     * Pro's catalog feed folder under uploads.
     */
    const PRO_UPLOAD_DIRS = array( 'wcct-feed' );

    /**
     * Option names to delete out of the ones a site has.
     *
     * @param string[] $names         Option names found by OPTION_PATTERNS, LEGACY_OPTIONS and the tracker options.
     * @param bool     $pro_installed Whether Pro's files are still there.
     * @param string   $slug          Free's plugin folder name.
     *
     * @return string[]
     */
    public static function options( array $names, bool $pro_installed, string $slug ): array {
        $delete = array();

        foreach ( $names as $name ) {
            if ( ! is_string( $name ) || ! self::is_ours( $name, $slug ) ) {
                continue;
            }

            if ( $pro_installed && self::is_pro( self::unprefixed( $name ) ) ) {
                continue;
            }

            $delete[] = $name;
        }

        return array_values( array_unique( $delete ) );
    }

    /**
     * Cron hooks to clear out of the ones a site has.
     *
     * @param string[] $hooks         Scheduled hook names.
     * @param bool     $pro_installed Whether Pro's files are still there.
     * @param string   $slug          Free's plugin folder name.
     *
     * @return string[]
     */
    public static function cron_hooks( array $hooks, bool $pro_installed, string $slug ): array {
        $clear = array();

        foreach ( $hooks as $hook ) {
            if ( ! is_string( $hook ) ) {
                continue;
            }

            $ours = 0 === strpos( $hook, self::CRON_PREFIX ) || ( '' !== $slug && $slug . self::TRACKER_HOOK_SUFFIX === $hook );

            if ( $ours && ! ( $pro_installed && self::is_pro( $hook ) ) ) {
                $clear[] = $hook;
            }
        }

        return array_values( array_unique( $clear ) );
    }

    /**
     * Action Scheduler groups to empty.
     *
     * @param bool $pro_installed Whether Pro's files are still there.
     *
     * @return string[]
     */
    public static function action_groups( bool $pro_installed ): array {
        return $pro_installed ? self::ACTION_GROUPS : array_merge( self::ACTION_GROUPS, self::PRO_ACTION_GROUPS );
    }

    /**
     * Folders under uploads to remove.
     *
     * @param bool $pro_installed Whether Pro's files are still there.
     *
     * @return string[]
     */
    public static function upload_dirs( bool $pro_installed ): array {
        return $pro_installed ? array() : self::PRO_UPLOAD_DIRS;
    }

    /**
     * Appsero tracker option names.
     *
     * @param string $slug Free's plugin folder name.
     *
     * @return string[]
     */
    public static function tracker_options( string $slug ): array {
        if ( '' === $slug ) {
            return array();
        }

        return array_map(
            function ( $suffix ) use ( $slug ) {
                return $slug . $suffix;
            },
            self::TRACKER_OPTION_SUFFIXES
        );
    }

    /**
     * Whether an order meta key is one uninstall removes; the sent flags never are.
     *
     * @param string $key Meta key.
     *
     * @return bool
     */
    public static function removes_order_meta( string $key ): bool {
        return in_array( $key, self::ORDER_META, true );
    }

    /**
     * Whether a wcct_ name (option, transient name or hook) belongs to Pro.
     *
     * @param string $name Name without a transient prefix.
     *
     * @return bool
     */
    public static function is_pro( string $name ): bool {
        if ( in_array( $name, self::PRO_OPTIONS, true ) ) {
            return true;
        }

        foreach ( self::PRO_PREFIXES as $prefix ) {
            if ( 0 === strpos( $name, $prefix ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an option name is one of ours.
     *
     * @param string $name Option name.
     * @param string $slug Free's plugin folder name.
     *
     * @return bool
     */
    private static function is_ours( string $name, string $slug ): bool {
        return 0 === strpos( self::unprefixed( $name ), 'wcct_' ) || in_array( $name, self::LEGACY_OPTIONS, true ) || in_array( $name, self::tracker_options( $slug ), true );
    }

    /**
     * An option name without its transient prefix.
     *
     * @param string $name Option name.
     *
     * @return string
     */
    private static function unprefixed( string $name ): string {
        return (string) preg_replace( '/^_(site_)?transient_(timeout_)?/', '', $name );
    }
}
