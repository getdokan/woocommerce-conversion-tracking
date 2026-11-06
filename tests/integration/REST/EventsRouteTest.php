<?php
// phpcs:disable

use StorePulse\ConversionTracking\Log\OrderLog;

/**
 * The public check-in route (#111) and the order log it writes (#112)
 */
class EventsRouteTest extends WCCT_Test_Rest_Case {

    const ROUTE = '/wcct/v1/events';
    const LOG   = '\StorePulse\ConversionTracking\Log\OrderLog';
    const KEY   = 'wc_order_ctguest0001';

    protected function setUp(): void {
        if ( self::pro_loaded() ) {
            $this->markTestSkipped( 'Free only.' );
        }
    }

    private static function settings() {
        return array( 'set_option', 'wcct_settings', WCCT_Test_Settings::lite_all() );
    }

    private static function check_in( $platform, array $fields = array(), $order_id = 3001, $key = self::KEY ) {
        return array( 'request', 'POST', self::ROUTE, array_merge( array( 'order_id' => $order_id, 'key' => $key, 'platform' => $platform ), $fields ), 'logged_out' );
    }

    private static function rows( $order_id = 3001 ) {
        return array( 'call', self::LOG . '::for_orders', array( array( $order_id ) ) );
    }

    private static function flags( $order_id = 3001 ) {
        return array( 'call', 'wcct_test_sent_flags', array( $order_id ) );
    }

    public function test_a_hand_over_writes_the_row_the_sent_flag_and_the_last_purchase() {
        $results = $this->rest(
            array(
                self::settings(),
                self::check_in( 'facebook', array( 'loaded' => true, 'consent' => 'granted', 'event_id' => 'wcct_3001_purchase' ) ),
                self::rows(),
                self::flags(),
                array( 'option', 'wcct_last_purchase_facebook' ),
            )
        );

        $this->assertSame( array( 200, array( 'result' => 'recorded' ) ), array( $results[1]['status'], $results[1]['data'] ) );
        $this->assertCount( 1, $results[2] );

        $row = $results[2][0];

        $this->assertSame( array( '3001', 'facebook', 'browser', 'processing', '78.00000000', 'USD', '1', 'granted', '', 'wcct_3001_purchase' ), array( $row['order_id'], $row['platform'], $row['source'], $row['status'], $row['total'], $row['currency'], $row['library_loaded'], $row['consent'], $row['reason'], $row['event_id'] ) );
        $this->assertNotNull( $row['sent_at'] );
        $this->assertNotNull( $row['thankyou_seen_at'] );
        $this->assertNotNull( $row['order_created_at'] );
        $this->assertSame( array( 'facebook' ), $results[3] );
        $this->assertSame( array( 3001, '3001' ), array( $results[4]['order_id'], $results[4]['order_number'] ) );
        $this->assertLessThan( 60, abs( time() - $results[4]['last_purchase_at'] ) );
    }

    public function test_each_rejection_writes_nothing() {
        $results = $this->rest(
            array(
                array( 'set_option', 'wcct_settings', array_intersect_key( WCCT_Test_Settings::lite_all(), array( 'facebook' => 1 ) ) ),
                self::check_in( 'bing_ads', array( 'loaded' => true ) ),
                self::check_in( 'twitter', array( 'loaded' => true ) ),
                self::check_in( 'facebook', array( 'loaded' => true ), 3001, 'wc_order_wrong' ),
                self::check_in( 'facebook', array( 'loaded' => true ), 3001, '' ),
                self::check_in( 'facebook', array( 'loaded' => true ), 3002, self::KEY ),
                self::check_in( 'facebook', array( 'loaded' => true ), 999999 ),
                self::check_in( '<script>', array( 'loaded' => true ) ),
                self::check_in( 'facebook', array( 'loaded' => true, 'consent' => 'maybe' ) ),
                array( 'request', 'POST', self::ROUTE, array( 'platform' => 'facebook', 'loaded' => true ), 'logged_out' ),
                self::rows(),
                self::flags(),
                array( 'option', 'wcct_last_purchase_facebook' ),
            )
        );

        $codes = array_map(
            function ( $result ) {
                return array( $result['status'], $result['data']['code'] );
            },
            array_slice( $results, 1, 9 )
        );

        $this->assertSame(
            array(
                array( 400, 'wcct_rest_unknown_platform' ),
                array( 400, 'wcct_rest_unknown_platform' ),
                array( 403, 'wcct_rest_invalid_order' ),
                array( 403, 'wcct_rest_invalid_order' ),
                array( 403, 'wcct_rest_invalid_order' ),
                array( 403, 'wcct_rest_invalid_order' ),
                array( 400, 'rest_invalid_param' ),
                array( 400, 'rest_invalid_param' ),
                array( 403, 'wcct_rest_invalid_order' ),
            ),
            $codes
        );
        $this->assertSame( array(), $results[10] );
        $this->assertSame( array(), $results[11] );
        $this->assertNull( $results[12] );
    }

    public function test_a_miss_writes_the_row_only_and_a_hand_over_upgrades_it_for_good() {
        $results = $this->rest(
            array(
                self::settings(),
                self::check_in( 'twitter', array( 'loaded' => false, 'consent' => 'denied', 'reason' => 'timeout' ) ),
                self::rows(),
                self::flags(),
                self::check_in( 'twitter', array( 'loaded' => false, 'reason' => 'hidden' ) ),
                self::check_in( 'twitter', array( 'loaded' => true, 'consent' => 'granted' ) ),
                self::rows(),
                self::flags(),
                self::check_in( 'twitter', array( 'loaded' => false, 'reason' => 'hidden' ) ),
                self::rows(),
                array( 'option', 'wcct_last_purchase_twitter' ),
            )
        );

        $this->assertSame( 'recorded', $results[1]['data']['result'] );
        $this->assertSame( array( '0', 'denied', 'timeout' ), array( $results[2][0]['library_loaded'], $results[2][0]['consent'], $results[2][0]['reason'] ) );
        $this->assertNull( $results[2][0]['sent_at'] );
        $this->assertSame( array(), $results[3] );
        $this->assertSame( 'unchanged', $results[4]['data']['result'], 'a second miss within a minute is throttled' );
        $this->assertSame( 'recorded', $results[5]['data']['result'] );
        $this->assertCount( 1, $results[6] );
        $this->assertSame( array( '1', 'granted', '' ), array( $results[6][0]['library_loaded'], $results[6][0]['consent'], $results[6][0]['reason'] ) );
        $this->assertSame( array( 'twitter' ), $results[7] );
        $this->assertSame( 'unchanged', $results[8]['data']['result'] );
        $this->assertSame( $results[6], $results[9] );
        $this->assertSame( 3001, $results[10]['order_id'] );
    }

    public function test_a_hand_over_writes_a_missing_flag_even_when_the_row_is_final() {
        $now     = time();
        $results = $this->rest(
            array(
                self::settings(),
                array( 'call', self::LOG . '::upsert', array( array( 'order_id' => 3001, 'platform' => 'facebook', 'source' => 'browser', 'library_loaded' => 1, 'created_at' => OrderLog::datetime( $now ), 'updated_at' => OrderLog::datetime( $now ) ) ) ),
                self::flags(),
                self::check_in( 'facebook', array( 'loaded' => true ) ),
                self::flags(),
                self::check_in( 'facebook', array( 'loaded' => true ) ),
                array( 'call', self::LOG . '::upsert', array( array( 'order_id' => 3001, 'platform' => 'twitter', 'source' => 'browser', 'created_at' => OrderLog::datetime( $now ), 'updated_at' => OrderLog::datetime( $now ) ) ) ),
            )
        );

        $this->assertSame( array(), $results[2] );
        $this->assertSame( 'recorded', $results[3]['data']['result'] );
        $this->assertSame( array( 'facebook' ), $results[4] );
        $this->assertSame( 'unchanged', $results[5]['data']['result'] );
        $this->assertFalse( $results[6], 'library_loaded is required' );
    }

    public function test_every_platform_gets_one_row() {
        $steps = array( self::settings() );

        foreach ( array( 'facebook', 'adwords', 'ga4', 'twitter', 'custom' ) as $platform ) {
            $steps[] = self::check_in( $platform, array( 'loaded' => true ) );
            $steps[] = self::check_in( $platform, array( 'loaded' => true ) );
        }

        $steps[] = self::rows();
        $results = $this->rest( $steps );

        $this->assertSame( array( 'facebook', 'adwords', 'ga4', 'twitter', 'custom' ), array_column( end( $results ), 'platform' ) );
    }

    public function test_failed_orders_never_check_in_and_status_changes_reach_the_rows() {
        $results = $this->rest(
            array(
                self::settings(),
                self::check_in( 'facebook', array( 'loaded' => true ) ),
                array( 'call', 'wcct_test_order_status', array( 3001, 'completed' ) ),
                self::rows(),
                array( 'call', 'wcct_test_order_status', array( 3002, 'failed' ) ),
                self::check_in( 'facebook', array( 'loaded' => true ), 3002, 'wc_order_ctcustomer01' ),
                self::rows( 3002 ),
            )
        );

        $this->assertSame( 'completed', $results[3][0]['status'] );
        $this->assertSame( 'skipped', $results[5]['data']['result'] );
        $this->assertSame( array(), $results[6] );
    }

    public function test_the_prune_removes_rows_older_than_24_hours_and_keeps_the_last_purchase() {
        $now = time();
        $row = function ( $order_id, $age ) use ( $now ) {
            return array( 'call', self::LOG . '::upsert', array( array( 'order_id' => $order_id, 'platform' => 'facebook', 'source' => 'browser', 'library_loaded' => 1, 'created_at' => OrderLog::datetime( $now - $age ), 'updated_at' => OrderLog::datetime( $now - $age ) ) ) );
        };

        $results = $this->rest(
            array(
                self::settings(),
                array( 'set_option', 'wcct_last_purchase_facebook', array( 'last_purchase_at' => $now - 30 * 3600, 'order_id' => 3002, 'order_number' => '3002' ) ),
                $row( 3001, 23 * 3600 ),
                $row( 3002, 25 * 3600 ),
                array( 'call', '\StorePulse\ConversionTracking\Log\Prune::run' ),
                array( 'call', self::LOG . '::for_orders', array( array( 3001, 3002 ) ) ),
                array( 'option', 'wcct_last_purchase_facebook' ),
            )
        );

        $this->assertSame( array( '3001' ), array_column( $results[5], 'order_id' ) );
        $this->assertSame( 3002, $results[6]['order_id'] );
    }

    public function test_the_table_has_the_unique_key_and_no_customer_columns() {
        $results = $this->rest(
            array(
                array( 'call', 'wcct_test_query', array( 'SHOW COLUMNS FROM {log}' ) ),
                array( 'call', 'wcct_test_query', array( 'SHOW INDEX FROM {log}' ) ),
                array( 'option', OrderLog::VERSION_OPTION ),
            )
        );

        $this->assertSame( array_keys( OrderLog::COLUMNS ), array_column( $results[0], 'Field' ) );

        $unique = array();
        $keys   = array();

        foreach ( $results[1] as $index ) {
            $keys[ $index['Key_name'] ][] = $index['Column_name'];

            if ( 'order_platform_source' === $index['Key_name'] ) {
                $unique[] = (int) $index['Non_unique'];
            }
        }

        $this->assertSame( array( 'order_id', 'platform', 'source' ), $keys['order_platform_source'] );
        $this->assertSame( array( 0, 0, 0 ), $unique );
        $this->assertSame( array( 'created_at' ), $keys['created_at'] );
        $this->assertSame( OrderLog::DB_VERSION, $results[2] );
    }

    public function test_the_seen_ping_needs_the_open_test_link_and_is_throttled() {
        $token   = 'abcdefghijklmnopqrstuvwxyz012345';
        $results = $this->rest(
            array(
                self::settings(),
                array( 'request', 'POST', '/wcct/v1/onboarding/verify', null, 'customer' ),
                array( 'request', 'GET', '/wcct/v1/onboarding/verify', null, 'logged_out' ),
                array( 'request', 'POST', '/wcct/v1/onboarding/verify', null, 'admin' ),
                array( 'set_option', 'wcct_verify', array( 'token' => $token, 'started' => time(), 'seen' => 0, 'platforms' => array() ) ),
                array( 'request', 'POST', self::ROUTE, array( 'type' => 'seen', 'token' => 'wrong' ), 'logged_out' ),
                array( 'request', 'POST', self::ROUTE, array( 'type' => 'seen', 'token' => $token, 'platforms' => array( 'facebook' => true, 'twitter' => false, 'evil' => true ) ), 'logged_out' ),
                array( 'request', 'POST', self::ROUTE, array( 'type' => 'seen', 'token' => $token, 'platforms' => array( 'facebook' => true ) ), 'logged_out' ),
                array( 'request', 'GET', '/wcct/v1/onboarding/verify', null, 'admin' ),
            )
        );

        $this->assertSame( array( 403, 401, 200 ), array( $results[1]['status'], $results[2]['status'], $results[3]['status'] ) );
        $this->assertStringContainsString( '?wcct_verify=', $results[3]['data']['url'] );
        $this->assertSame( array( 403, 'wcct_rest_invalid_token' ), array( $results[5]['status'], $results[5]['data']['code'] ) );
        $this->assertSame( 200, $results[6]['status'] );
        $this->assertSame( array( 429, 'wcct_rest_throttled' ), array( $results[7]['status'], $results[7]['data']['code'] ) );
        $this->assertTrue( $results[8]['data']['active'] );
        $this->assertGreaterThan( 0, $results[8]['data']['seen_at'] );
        $this->assertSame( array( 'facebook' => true, 'adwords' => false, 'twitter' => false, 'ga4' => false, 'custom' => false ), $results[8]['data']['platforms'] );
        $this->assertArrayNotHasKey( 'token', $results[8]['data'] );
    }
}
