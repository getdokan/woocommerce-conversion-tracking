<?php

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Log\OrderLog;

/**
 * Tests for the order log schema and its 24-hour retention
 */
class OrderLogTest extends TestCase {

    const NOW = 1767225600;

    public function test_the_schema_has_the_columns_and_keys() {
        $sql = OrderLog::schema( 'wp_wcct_order_log', 'DEFAULT CHARSET=utf8mb4' );

        $this->assertStringStartsWith( "CREATE TABLE wp_wcct_order_log (\n", $sql );
        $this->assertStringContainsString( 'PRIMARY KEY  (id)', $sql );
        $this->assertStringContainsString( 'UNIQUE KEY order_platform_source (order_id,platform,source)', $sql );
        $this->assertStringContainsString( 'KEY created_at (created_at)', $sql );

        foreach ( array( 'order_id', 'platform', 'source', 'status', 'total', 'currency', 'created_via', 'thankyou_seen_at', 'sent_at', 'library_loaded', 'consent', 'reason', 'event_id', 'created_at' ) as $column ) {
            $this->assertArrayHasKey( $column, OrderLog::COLUMNS, $column );
        }
    }

    public function test_no_column_holds_customer_data() {
        foreach ( array_keys( OrderLog::COLUMNS ) as $column ) {
            $this->assertDoesNotMatchRegularExpression( '/mail|name|phone|address|billing|shipping|customer|user|ip|agent|city|postcode|country/', $column );
        }
    }

    public function test_rows_at_23_hours_are_kept_and_at_25_hours_removed() {
        $this->assertFalse( OrderLog::is_expired( OrderLog::datetime( self::NOW - 23 * 3600 ), self::NOW ) );
        $this->assertTrue( OrderLog::is_expired( OrderLog::datetime( self::NOW - 25 * 3600 ), self::NOW ) );
        $this->assertFalse( OrderLog::is_expired( OrderLog::datetime( self::NOW - 24 * 3600 ), self::NOW ) );
        $this->assertSame( '2025-12-31 00:00:00', OrderLog::cutoff( self::NOW ) );
    }

    public function test_retention_can_be_longer() {
        $this->assertFalse( OrderLog::is_expired( OrderLog::datetime( self::NOW - 25 * 3600 ), self::NOW, 35 * 86400 ) );
    }
}
