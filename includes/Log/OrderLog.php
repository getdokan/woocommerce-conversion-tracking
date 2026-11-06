<?php

namespace StorePulse\ConversionTracking\Log;

/**
 * The order × platform table: one row per order, platform and source, written by the check-in and kept 24 hours.
 */
class OrderLog {

    /**
     * Table name without the WordPress prefix.
     */
    const TABLE = 'wcct_order_log';

    /**
     * Schema version; bump it when the table changes.
     */
    const DB_VERSION = '1';

    /**
     * Option holding the installed schema version.
     */
    const VERSION_OPTION = 'wcct_order_log_db_version';

    /**
     * Rows are kept this many seconds; 3.0 hard-codes 24 hours.
     */
    const RETENTION = 86400;

    /**
     * Source of a row written by the thank-you page.
     */
    const SOURCE_BROWSER = 'browser';

    /**
     * Columns and their SQL types; no column holds customer data.
     */
    const COLUMNS = array(
        'id'               => 'bigint(20) unsigned NOT NULL AUTO_INCREMENT',
        'order_id'         => 'bigint(20) unsigned NOT NULL',
        'platform'         => 'varchar(32) NOT NULL',
        'source'           => "varchar(16) NOT NULL DEFAULT 'browser'",
        'status'           => "varchar(32) NOT NULL DEFAULT ''",
        'total'            => 'decimal(26,8) NOT NULL DEFAULT 0',
        'currency'         => "varchar(3) NOT NULL DEFAULT ''",
        'created_via'      => "varchar(64) NOT NULL DEFAULT ''",
        'order_created_at' => 'datetime NULL DEFAULT NULL',
        'thankyou_seen_at' => 'datetime NULL DEFAULT NULL',
        'sent_at'          => 'datetime NULL DEFAULT NULL',
        'library_loaded'   => 'tinyint(1) NOT NULL DEFAULT 0',
        'consent'          => "varchar(16) NOT NULL DEFAULT ''",
        'reason'           => "varchar(32) NOT NULL DEFAULT ''",
        'event_id'         => "varchar(64) NOT NULL DEFAULT ''",
        'created_at'       => 'datetime NOT NULL',
        'updated_at'       => 'datetime NOT NULL',
    );

    /**
     * Columns a check-in writes; kept when the row was already handed over.
     */
    const CHECKIN_COLUMNS = array( 'sent_at', 'consent', 'reason', 'event_id', 'updated_at' );

    /**
     * Full table name.
     *
     * @return string
     */
    public static function table() {
        global $wpdb;

        return $wpdb->prefix . self::TABLE;
    }

    /**
     * CREATE TABLE statement in dbDelta's format.
     *
     * @param string $table   Full table name.
     * @param string $collate Charset and collation clause.
     *
     * @return string
     */
    public static function schema( $table, $collate = '' ) {
        $lines = array();

        foreach ( self::COLUMNS as $name => $type ) {
            $lines[] = $name . ' ' . $type;
        }

        // dbDelta wants two spaces after PRIMARY KEY and no spaces inside key column lists.
        $lines[] = 'PRIMARY KEY  (id)';
        $lines[] = 'UNIQUE KEY order_platform_source (order_id,platform,source)';
        $lines[] = 'KEY created_at (created_at)';

        return 'CREATE TABLE ' . $table . " (\n" . implode( ",\n", $lines ) . "\n) " . $collate . ';';
    }

    /**
     * Create or update the table when the stored schema version differs; cheap, reads an autoloaded option.
     *
     * @return void
     */
    public static function maybe_install() {
        if ( self::DB_VERSION !== get_option( self::VERSION_OPTION ) ) {
            self::install();
        }
    }

    /**
     * Create or update the table.
     *
     * @return void
     */
    public static function install() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta( self::schema( self::table(), $wpdb->get_charset_collate() ) );

        update_option( self::VERSION_OPTION, self::DB_VERSION );
    }

    /**
     * Whether the table is installed at the current version.
     *
     * @return bool
     */
    public static function is_installed() {
        return self::DB_VERSION === get_option( self::VERSION_OPTION );
    }

    /**
     * The row for an order, platform and source, or null.
     *
     * @param int    $order_id Order ID.
     * @param string $platform Platform ID.
     * @param string $source   Source.
     *
     * @return array|null
     */
    public static function get( $order_id, $platform, $source = self::SOURCE_BROWSER ) {
        global $wpdb;

        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, read fresh on every check-in.
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d AND platform = %s AND source = %s", $order_id, $platform, $source ), ARRAY_A );

        return is_array( $row ) ? $row : null;
    }

    /**
     * Rows for some orders, oldest first.
     *
     * @param int[] $order_ids Order IDs.
     *
     * @return array[]
     */
    public static function for_orders( array $order_ids ) {
        global $wpdb;

        $order_ids = array_values( array_filter( array_map( 'absint', $order_ids ) ) );

        if ( ! $order_ids ) {
            return array();
        }

        $table        = self::table();
        $placeholders = implode( ',', array_fill( 0, count( $order_ids ), '%d' ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- own table; the placeholders are built above.
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id IN ({$placeholders}) ORDER BY id", $order_ids ), ARRAY_A );

        return is_array( $rows ) ? $rows : array();
    }

    /**
     * Rows created at or after a Unix time, oldest first.
     *
     * @param int $since Unix time.
     *
     * @return array[]
     */
    public static function since( $since ) {
        global $wpdb;

        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE created_at >= %s ORDER BY id", self::datetime( $since ) ), ARRAY_A );

        return is_array( $rows ) ? $rows : array();
    }

    /**
     * Insert the row, or update it when the order, platform and source already have one; a handed-over row never goes back.
     *
     * @param array $row Column => value; order_id, platform, source, library_loaded, created_at and updated_at are required.
     *
     * @return bool
     */
    public static function upsert( array $row ) {
        global $wpdb;

        $row = array_intersect_key( $row, self::COLUMNS );

        unset( $row['id'] );

        // The hand-over guard below needs it.
        if ( ! isset( $row['library_loaded'] ) ) {
            return false;
        }

        $values  = array();
        $updates = array();

        foreach ( $row as $column => $value ) {
            $values[] = null === $value ? 'NULL' : $wpdb->prepare( is_int( $value ) ? '%d' : '%s', $value );
        }

        foreach ( array_keys( $row ) as $column ) {
            if ( in_array( $column, array( 'order_id', 'platform', 'source', 'created_at', 'library_loaded' ), true ) ) {
                continue;
            }

            if ( 'thankyou_seen_at' === $column ) {
                $updates[] = "{$column} = COALESCE({$column}, VALUES({$column}))";
            } elseif ( in_array( $column, self::CHECKIN_COLUMNS, true ) ) {
                // A late "not handed over" check-in can't undo a hand-over that arrived first.
                $updates[] = "{$column} = IF(library_loaded = 1 AND VALUES(library_loaded) = 0, {$column}, VALUES({$column}))";
            } else {
                $updates[] = "{$column} = VALUES({$column})";
            }
        }

        // Last: the assignments above still read the old value.
        $updates[] = 'library_loaded = GREATEST(library_loaded, VALUES(library_loaded))';

        $table = self::table();
        $sql   = "INSERT INTO {$table} (" . implode( ', ', array_keys( $row ) ) . ') VALUES (' . implode( ', ', $values ) . ')' . ( $updates ? ' ON DUPLICATE KEY UPDATE ' . implode( ', ', $updates ) : '' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- each value is prepared above.
        return false !== $wpdb->query( $sql );
    }

    /**
     * Keep the order status of an order's rows current.
     *
     * @param int    $order_id Order ID.
     * @param string $status   Status without the wc- prefix.
     *
     * @return void
     */
    public static function update_status( $order_id, $status ) {
        global $wpdb;

        if ( ! self::is_installed() ) {
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update( self::table(), array( 'status' => (string) $status ), array( 'order_id' => (int) $order_id ), array( '%s' ), array( '%d' ) );
    }

    /**
     * Delete rows created before the retention window; returns how many went.
     *
     * @param int|null $now       Unix time, default now.
     * @param int      $retention Seconds to keep.
     *
     * @return int
     */
    public static function prune( $now = null, $retention = self::RETENTION ) {
        global $wpdb;

        if ( ! self::is_installed() ) {
            return 0;
        }

        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
        $deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", self::cutoff( $now, $retention ) ) );

        return is_numeric( $deleted ) ? (int) $deleted : 0;
    }

    /**
     * The oldest created_at kept, as a GMT datetime.
     *
     * @param int|null $now       Unix time, default now.
     * @param int      $retention Seconds to keep.
     *
     * @return string
     */
    public static function cutoff( $now = null, $retention = self::RETENTION ) {
        return self::datetime( ( null === $now ? time() : (int) $now ) - max( 0, (int) $retention ) );
    }

    /**
     * Whether a row created at a GMT datetime is past the retention window.
     *
     * @param string   $created_at GMT datetime.
     * @param int|null $now        Unix time, default now.
     * @param int      $retention  Seconds to keep.
     *
     * @return bool
     */
    public static function is_expired( $created_at, $now = null, $retention = self::RETENTION ) {
        return (string) $created_at < self::cutoff( $now, $retention );
    }

    /**
     * A Unix time as a GMT datetime for the table.
     *
     * @param int $time Unix time.
     *
     * @return string
     */
    public static function datetime( $time ) {
        return gmdate( 'Y-m-d H:i:s', (int) $time );
    }
}
