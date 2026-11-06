<?php

namespace StorePulse\ConversionTracking\Uninstall;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

/**
 * Deletes Free's data on every site; run from uninstall.php.
 */
class Uninstaller {

    /**
     * Pro's main file name.
     */
    const PRO_FILE = 'woocommerce-conversion-tracking-pro.php';

    /**
     * Pro's text domain.
     */
    const PRO_TEXT_DOMAIN = 'woocommerce-conversion-tracking-pro';

    /**
     * Rows deleted per query, so a large store never sends one huge statement.
     */
    const BATCH = 5000;

    /**
     * Free's plugin folder name (for the Appsero tracker keys).
     *
     * @var string
     */
    private $slug;

    /**
     * Whether Pro's files are still there.
     *
     * @var bool
     */
    private $pro_installed;

    /**
     * Set up for one uninstall.
     *
     * @param string $slug          Free's plugin folder name.
     * @param bool   $pro_installed Whether Pro's files are still there.
     */
    public function __construct( string $slug, bool $pro_installed ) {
        $this->slug          = $slug;
        $this->pro_installed = $pro_installed;
    }

    /**
     * Remove the data on every site, then the network-wide data.
     *
     * @return void
     */
    public function run() {
        // A large network can take a while; finish even if the browser goes away.
        ignore_user_abort( true );

        if ( function_exists( 'set_time_limit' ) && false === strpos( (string) ini_get( 'disable_functions' ), 'set_time_limit' ) ) {
            set_time_limit( 0 );
        }

        if ( is_multisite() ) {
            $sites = get_sites(
                array(
                    'fields' => 'ids',
                    'number' => 0,
                )
            );

            foreach ( $sites as $site_id ) {
                switch_to_blog( (int) $site_id );
                $this->clean_site();
                restore_current_blog();
            }

            $this->clean_network();
        } else {
            $this->clean_site();
        }

        $this->clean_users();

        wp_cache_flush();
    }

    /**
     * Whether Pro is still installed: its main file is on disk.
     *
     * @return bool
     */
    public static function pro_installed(): bool {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        foreach ( get_plugins() as $file => $data ) {
            $is_pro = ! empty( $data['TextDomain'] ) ? self::PRO_TEXT_DOMAIN === $data['TextDomain'] : self::PRO_FILE === basename( $file );

            // get_plugins() may still list a plugin deleted earlier in this request.
            if ( $is_pro && file_exists( WP_PLUGIN_DIR . '/' . $file ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remove the current site's options, transients, table, meta, scheduled work and feed folder.
     *
     * @return void
     */
    private function clean_site() {
        global $wpdb;

        $names = self::matching( $wpdb->options, 'option_name', array_merge( Cleanup::LEGACY_OPTIONS, Cleanup::tracker_options( $this->slug ) ) );

        foreach ( Cleanup::options( $names, $this->pro_installed, $this->slug ) as $name ) {
            delete_option( $name );
        }

        foreach ( Cleanup::TRANSIENTS as $transient ) {
            delete_transient( $transient );
        }

        foreach ( Cleanup::TABLES as $table ) {
            $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" );
        }

        foreach ( Cleanup::ORDER_META as $key ) {
            self::delete_order_meta( $key );
        }

        foreach ( Cleanup::PRODUCT_META as $key ) {
            self::delete_meta_rows( $wpdb->postmeta, $key );
        }

        foreach ( Cleanup::action_groups( $this->pro_installed ) as $group ) {
            self::delete_action_group( $group );
        }

        $cron  = function_exists( '_get_cron_array' ) ? _get_cron_array() : array();
        $hooks = array();

        foreach ( is_array( $cron ) ? $cron : array() as $events ) {
            $hooks = array_merge( $hooks, is_array( $events ) ? array_keys( $events ) : array() );
        }

        foreach ( Cleanup::cron_hooks( $hooks, $this->pro_installed, $this->slug ) as $hook ) {
            wp_unschedule_hook( $hook );
        }

        foreach ( Cleanup::upload_dirs( $this->pro_installed ) as $dir ) {
            self::delete_upload_dir( $dir );
        }
    }

    /**
     * Remove network options and site transients (multisite).
     *
     * @return void
     */
    private function clean_network() {
        global $wpdb;

        $names = self::matching( $wpdb->sitemeta, 'meta_key', array() );

        foreach ( Cleanup::options( $names, $this->pro_installed, $this->slug ) as $name ) {
            delete_network_option( null, $name );
        }
    }

    /**
     * Remove the user meta (one table on multisite).
     *
     * @return void
     */
    private function clean_users() {
        global $wpdb;

        $keys = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT meta_key FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", Cleanup::USER_META_PATTERN ) );

        foreach ( (array) $keys as $key ) {
            self::delete_meta_rows( $wpdb->usermeta, $key );
        }
    }

    /**
     * Names in a table column matching our patterns or listed exactly.
     *
     * @param string   $table  Table name.
     * @param string   $column Name column.
     * @param string[] $exact  Names outside the patterns.
     *
     * @return string[]
     */
    private static function matching( string $table, string $column, array $exact ): array {
        global $wpdb;

        $where = implode( ' OR ', array_fill( 0, count( Cleanup::OPTION_PATTERNS ), "{$column} LIKE %s" ) );
        $args  = Cleanup::OPTION_PATTERNS;

        if ( $exact ) {
            $where .= " OR {$column} IN (" . implode( ', ', array_fill( 0, count( $exact ), '%s' ) ) . ')';
            $args   = array_merge( $args, $exact );
        }

        return (array) $wpdb->get_col( $wpdb->prepare( "SELECT {$column} FROM {$table} WHERE {$where}", $args ) );
    }

    /**
     * Delete an order meta key from both order stores (HPOS table and posts), whichever is in use.
     *
     * @param string $key Meta key.
     *
     * @return void
     */
    private static function delete_order_meta( string $key ) {
        global $wpdb;

        $hpos = $wpdb->prefix . 'wc_orders_meta';

        if ( self::table_exists( $hpos ) ) {
            self::delete_meta_rows( $hpos, $key );
        }

        self::delete_meta_rows( $wpdb->postmeta, $key );
    }

    /**
     * Delete every row of a meta key, in batches; caches are flushed at the end of run().
     *
     * @param string $table Meta table.
     * @param string $key   Meta key.
     *
     * @return void
     */
    private static function delete_meta_rows( string $table, string $key ) {
        global $wpdb;

        do {
            $deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE meta_key = %s LIMIT %d", $key, self::BATCH ) );
        } while ( $deleted >= self::BATCH );
    }

    /**
     * Delete an Action Scheduler group with all its actions and their logs, in any status.
     *
     * @param string $group Group slug.
     *
     * @return void
     */
    private static function delete_action_group( string $group ) {
        global $wpdb;

        $groups  = $wpdb->prefix . 'actionscheduler_groups';
        $actions = $wpdb->prefix . 'actionscheduler_actions';
        $logs    = $wpdb->prefix . 'actionscheduler_logs';

        if ( ! self::table_exists( $groups ) || ! self::table_exists( $actions ) ) {
            return;
        }

        $group_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT group_id FROM {$groups} WHERE slug = %s", $group ) );

        if ( ! $group_id ) {
            return;
        }

        if ( self::table_exists( $logs ) ) {
            $wpdb->query( $wpdb->prepare( "DELETE logs FROM {$logs} logs INNER JOIN {$actions} actions ON actions.action_id = logs.action_id WHERE actions.group_id = %d", $group_id ) );
        }

        $wpdb->delete( $actions, array( 'group_id' => $group_id ) );
        $wpdb->delete( $groups, array( 'group_id' => $group_id ) );
    }

    /**
     * Remove a folder under the current site's uploads, with its files.
     *
     * @param string $name Folder name.
     *
     * @return void
     */
    private static function delete_upload_dir( string $name ) {
        $uploads = wp_upload_dir( null, false, true );

        if ( empty( $uploads['basedir'] ) || '' === $name || false !== strpos( $name, '..' ) ) {
            return;
        }

        self::delete_dir( $uploads['basedir'] . '/' . $name );
    }

    /**
     * Delete a folder and everything in it.
     *
     * @param string $dir Folder path.
     *
     * @return void
     */
    private static function delete_dir( string $dir ) {
        if ( ! is_dir( $dir ) || is_link( $dir ) ) {
            return;
        }

        foreach ( (array) scandir( $dir ) as $entry ) {
            if ( '.' === $entry || '..' === $entry ) {
                continue;
            }

            $path = $dir . '/' . $entry;

            if ( is_dir( $path ) && ! is_link( $path ) ) {
                self::delete_dir( $path );
            } else {
                wp_delete_file( $path );
            }
        }

        @rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
    }

    /**
     * Whether a table exists.
     *
     * @param string $table Table name.
     *
     * @return bool
     */
    private static function table_exists( string $table ): bool {
        global $wpdb;

        static $exists = array();

        if ( ! isset( $exists[ $table ] ) ) {
            $exists[ $table ] = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
        }

        return $exists[ $table ];
    }
}
