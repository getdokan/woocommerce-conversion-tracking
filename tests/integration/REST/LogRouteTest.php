<?php
// phpcs:disable

/**
 * The Activity log route (#114) and the statuses it reads (#113), from orders and rows seeded in the test store
 */
class LogRouteTest extends WCCT_Test_Rest_Case {

    const ROUTE = '/wcct/v1/log';

    const STATUSES = '\StorePulse\ConversionTracking\Log\Statuses';

    // Every platform that checks in with lite_all(): Meta, Google Ads, GA4, X, Custom.
    const ALL = array( 'facebook', 'adwords', 'ga4', 'twitter', 'custom' );

    protected function setUp(): void {
        if ( self::pro_loaded() ) {
            $this->markTestSkipped( 'Free only.' );
        }
    }

    private static function settings() {
        return array( 'set_option', 'wcct_settings', WCCT_Test_Settings::lite_all() );
    }

    private static function order( array $props = array() ) {
        return array( 'call', 'wcct_test_log_order', array( $props ) );
    }

    private static function rows( array $loaded, array $columns = array(), $age = 3590 ) {
        $rows = array();

        foreach ( self::ALL as $platform ) {
            $rows[] = array( $platform, in_array( $platform, $loaded, true ), $columns, $age );
        }

        return $rows;
    }

    private static function get( array $query = array(), $user = 'admin' ) {
        return array( 'request', 'GET', self::ROUTE, $query ? $query : null, $user );
    }

    /**
     * Order ID => [ status, cause, platform => status ] from a response.
     */
    private static function by_order( array $response ) {
        $orders = array();

        foreach ( $response['data']['orders'] as $order ) {
            $orders[ $order['id'] ] = array( $order['status'], $order['cause'], array_column( $order['platforms'], 'status', 'id' ) );
        }

        return $orders;
    }

    public function test_only_managers_read_the_log() {
        $steps = array( self::settings() );
        $users = array( 'admin' => 200, 'shop_manager' => 403, 'customer' => 403, 'subscriber' => 403, 'seller' => 403, 'logged_out' => 401 );

        foreach ( array_keys( $users ) as $user ) {
            $steps[] = self::get( array(), $user );
        }

        $this->assertSame( array_values( $users ), array_slice( self::statuses( $this->rest( $steps ) ), 1 ) );
    }

    public function test_each_status_renders_from_a_real_order() {
        $results = $this->rest(
            array(
                self::settings(),
                array( 'call', 'wcct_test_log_clear' ),
                self::order( array( 'rows' => self::rows( self::ALL ) ) ),
                self::order( array( 'rows' => self::rows( array( 'adwords', 'ga4', 'twitter', 'custom' ) ) ) ),
                self::order( array( 'thankyou_age' => null ) ),
                self::order(),
                self::order( array( 'status' => 'on-hold', 'rows' => self::rows( self::ALL ) ) ),
                self::order( array( 'status' => 'failed', 'thankyou_age' => null ) ),
                self::order( array( 'age' => 120, 'thankyou_age' => null ) ),
                self::order( array( 'rows' => self::rows( array(), array( 'consent' => 'denied' ) ) ) ),
                self::order( array( 'created_via' => 'admin', 'rows' => self::rows( self::ALL ) ) ),
                self::order( array( 'created_via' => 'rest-api' ) ),
                self::order( array( 'renewal' => true ) ),
                self::order( array( 'status' => 'pending', 'thankyou_age' => null ) ),
                self::order( array( 'age' => 2 * 86400, 'rows' => self::rows( self::ALL, array(), 2 * 86400 ) ) ),
                self::order( array( 'created_via' => 'admin' ) ),
                // Created two days ago, paid on the pay page an hour ago.
                self::order( array( 'age' => 2 * 86400, 'rows' => self::rows( self::ALL ) ) ),
                self::get(),
            )
        );

        list( , , $counted, $missed, $not_reached, $not_reported, $pending, $failed, $waiting, $declined, $admin_paid, $rest, $renewal, $abandoned, $old, $admin, $paid_later ) = $results;

        $response = end( $results );
        $orders   = self::by_order( $response );
        $google   = array( 'facebook' => 'confirmed', 'google' => 'confirmed', 'twitter' => 'confirmed', 'custom' => 'confirmed' );

        $this->assertSame( 200, $response['status'] );
        $this->assertSame( array( 'confirmed', '', $google ), $orders[ $counted ] );
        $this->assertSame( array( 'not_confirmed', '', array_merge( $google, array( 'facebook' => 'not_confirmed' ) ) ), $orders[ $missed ] );
        $this->assertSame( array( 'no_checkin', 'not_reached' ), array_slice( $orders[ $not_reached ], 0, 2 ) );
        $this->assertSame( array( 'no_checkin', 'not_reported' ), array_slice( $orders[ $not_reported ], 0, 2 ) );
        $this->assertSame( array( 'pending', '' ), array_slice( $orders[ $pending ], 0, 2 ) );
        $this->assertSame( array( 'skipped', 'order_status' ), array_slice( $orders[ $failed ], 0, 2 ) );
        $this->assertSame( array( 'waiting', '' ), array_slice( $orders[ $waiting ], 0, 2 ) );
        $this->assertSame( array( 'skipped', 'consent', array( 'facebook' => 'skipped', 'google' => 'skipped', 'twitter' => 'skipped', 'custom' => 'skipped' ) ), $orders[ $declined ] );

        // Admin, REST, renewal, abandoned-payment and older orders show nothing; neither do the seeded orders, moved out of the window.
        foreach ( array( $admin, $rest, $renewal, $abandoned, $old, 3001, 3002 ) as $hidden ) {
            $this->assertArrayNotHasKey( $hidden, $orders );
        }

        // An admin order paid on the pay page, and an order paid in the window after it was created, really sent a Purchase.
        $this->assertSame( array( 'confirmed', '', $google ), $orders[ $admin_paid ] );
        $this->assertSame( array( 'confirmed', '', $google ), $orders[ $paid_later ] );

        // Newest first; the waiting order is the newest and the order paid later the oldest.
        $this->assertSame( $waiting, $response['data']['orders'][0]['id'] );
        $this->assertSame( $paid_later, end( $response['data']['orders'] )['id'] );
        $this->assertSame( array( 'all' => 10, 'tracked' => 3, 'not-confirmed' => 1, 'pending' => 1 ), $response['data']['counts'] );
        $this->assertSame( array( 10, 1, 1, 20 ), array( $response['data']['total'], $response['data']['pages'], $response['data']['page'], $response['data']['per_page'] ) );

        $missed_order = $response['data']['orders'][ array_search( $missed, array_column( $response['data']['orders'], 'id' ), true ) ];
        $meta         = $missed_order['platforms'][0];

        $this->assertSame( array( 'facebook', 'Meta', array( 'facebook' ), false, 'timeout', null, "wcct_{$missed}_purchase" ), array( $meta['id'], $meta['name'], $meta['integrations'], $meta['sent'], $meta['cause'], $meta['sent_at'], $meta['event_id'] ) );
        $this->assertSame( array( 'google', 'Google', array( 'adwords', 'ga4' ) ), array( $missed_order['platforms'][1]['id'], $missed_order['platforms'][1]['name'], $missed_order['platforms'][1]['integrations'] ) );
        $this->assertSame( array( (string) $missed, '$18.00', 'USD', 'processing', 'Processing', 'not-confirmed' ), array( $missed_order['number'], $missed_order['amount'], $missed_order['currency'], $missed_order['order_status'], $missed_order['order_status_label'], $missed_order['tab'] ) );
        $this->assertMatchesRegularExpression( '/^(Today|Yesterday) /', $missed_order['created_label'] );
        $this->assertNotNull( $missed_order['thankyou_at'] );

        // Order numbers and amounts only: no name or email anywhere.
        $json = json_encode( $response['data'] );

        $this->assertStringNotContainsString( 'private.person', $json );
        $this->assertStringNotContainsString( 'Private', $json );
        $this->assertStringNotContainsString( 'Person', $json );
    }

    public function test_each_tab_and_page() {
        $steps = array(
            self::settings(),
            array( 'call', 'wcct_test_log_clear' ),
            // A minute apart, so newest first is one order.
            self::order( array( 'age' => 3600, 'rows' => self::rows( self::ALL ) ) ),
            self::order( array( 'age' => 3540, 'rows' => self::rows( self::ALL ) ) ),
            self::order( array( 'age' => 3480, 'rows' => self::rows( self::ALL ) ) ),
            self::order( array( 'age' => 3420, 'rows' => self::rows( array( 'ga4' ) ) ) ),
            self::order( array( 'age' => 3360, 'status' => 'on-hold', 'rows' => self::rows( self::ALL ) ) ),
            self::order( array( 'age' => 3300, 'thankyou_age' => null ) ),
            self::get( array( 'tab' => 'all' ) ),
            self::get( array( 'tab' => 'tracked' ) ),
            self::get( array( 'tab' => 'not-confirmed' ) ),
            self::get( array( 'tab' => 'pending' ) ),
            self::get( array( 'tab' => 'tracked', 'per_page' => 2 ) ),
            self::get( array( 'tab' => 'tracked', 'per_page' => 2, 'page' => 2 ) ),
            self::get( array( 'tab' => 'tracked', 'per_page' => 2, 'page' => 3 ) ),
            self::get( array( 'tab' => 'skipped' ) ),
            self::get( array( 'per_page' => 101 ) ),
        );
        $results = $this->rest( $steps );
        $ids     = array_slice( $results, 2, 6 );
        $list    = function ( $response ) {
            return array_column( $response['data']['orders'], 'id' );
        };

        $this->assertSame( array_reverse( $ids ), $list( $results[8] ) );
        $this->assertSame( array( $ids[2], $ids[1], $ids[0] ), $list( $results[9] ) );
        $this->assertSame( array( $ids[3] ), $list( $results[10] ) );
        $this->assertSame( array( $ids[4] ), $list( $results[11] ) );

        // The No check-in order shows under All only.
        foreach ( array( 9, 10, 11 ) as $index ) {
            $this->assertNotContains( $ids[5], $list( $results[ $index ] ) );
        }

        $this->assertSame( array( array( $ids[2], $ids[1] ), 3, 2 ), array( $list( $results[12] ), $results[12]['data']['total'], $results[12]['data']['pages'] ) );
        $this->assertSame( array( $ids[0] ), $list( $results[13] ) );
        $this->assertSame( array(), $list( $results[14] ) );
        $this->assertSame( array( 400, 400 ), array( $results[15]['status'], $results[16]['status'] ) );

        foreach ( array_slice( $results, 8, 7 ) as $response ) {
            $this->assertSame( array( 'all' => 6, 'tracked' => 3, 'not-confirmed' => 1, 'pending' => 1 ), $response['data']['counts'] );
        }
    }

    public function test_a_check_in_waits_ten_minutes_before_it_is_not_confirmed() {
        $check_in = function ( $platform, $loaded ) {
            return array( 'request', 'POST', '/wcct/v1/events', array( 'order_id' => 3001, 'key' => 'wc_order_ctguest0001', 'platform' => $platform, 'loaded' => $loaded, 'reason' => $loaded ? '' : 'timeout' ), 'logged_out' );
        };

        $steps = array( self::settings(), array( 'call', 'wcct_test_log_clear', array( 30 ) ) );

        foreach ( self::ALL as $platform ) {
            $steps[] = $check_in( $platform, 'facebook' !== $platform );
        }

        $steps[] = self::get();
        $steps[] = array( 'call', 'wcct_test_log_age_rows', array( 3001, 601 ) );
        $steps[] = self::get();
        $steps[] = self::get( array( 'tab' => 'not-confirmed' ) );

        // The service the Doctor and the dashboard call (#116, #117, #118).
        foreach ( array( array( 3001, 'facebook' ), array( 3001, 'ga4' ), array( 3001, 'google' ), array( 3001, '' ), array( 3002, '' ), array( 999999, '' ) ) as $args ) {
            $steps[] = array( 'call', self::STATUSES . '::for_order', $args );
        }

        $results = $this->rest( $steps );
        $before  = $results[7]['data']['orders'][0];
        $after   = $results[9]['data']['orders'][0];

        $this->assertSame( array( 3001, 'waiting', 'waiting' ), array( $before['id'], $before['status'], $before['platforms'][0]['status'] ) );
        $this->assertNotNull( $before['settles_at'] );
        $this->assertSame( array( 3001, 'not_confirmed', 'not_confirmed', 'confirmed' ), array( $after['id'], $after['status'], $after['platforms'][0]['status'], $after['platforms'][1]['status'] ) );
        $this->assertNull( $after['settles_at'] );
        $this->assertSame( array( 3001 ), array_column( $results[10]['data']['orders'], 'id' ) );
        $this->assertSame( array( 'not_confirmed', 'confirmed', 'confirmed', 'not_confirmed', '', '' ), array_slice( $results, 11 ) );
    }

    public function test_a_pause_counts_only_for_orders_placed_after_it_and_a_platform_switched_off_is_left_out() {
        $settings = WCCT_Test_Settings::lite_all();

        unset( $settings['custom'] );

        $results = $this->rest(
            array(
                array( 'set_option', 'wcct_settings', $settings ),
                array( 'set_option', 'wcct_paused_platforms', array( 'twitter' ) ),
                array( 'set_option', 'wcct_paused_since', array( 'twitter' => time() - 1800 ) ),
                array( 'call', 'wcct_test_log_clear' ),
                // Placed before the pause.
                self::order( array( 'rows' => array( array( 'facebook', true ), array( 'ga4', true ), array( 'adwords', true ) ) ) ),
                self::order( array( 'thankyou_age' => null ) ),
                // Placed after it.
                self::order( array( 'age' => 900, 'thankyou_age' => null ) ),
                self::get(),
            )
        );

        $orders = self::by_order( end( $results ) );
        $first  = end( $results )['data']['orders'][ array_search( $results[4], array_column( end( $results )['data']['orders'], 'id' ), true ) ];

        // X didn't check in while the others did: listed as No check-in, never judged.
        $this->assertSame( array( 'confirmed', '', array( 'facebook' => 'confirmed', 'google' => 'confirmed', 'twitter' => 'no_checkin' ) ), $orders[ $results[4] ] );
        $this->assertSame( array( false, false, true ), array_column( $first['platforms'], 'unknown' ) );
        $this->assertSame( array( 'no_checkin', 'not_reached', array( 'facebook' => 'no_checkin', 'google' => 'no_checkin', 'twitter' => 'no_checkin' ) ), $orders[ $results[5] ] );
        $this->assertSame( array( 'no_checkin', 'not_reached', array( 'facebook' => 'no_checkin', 'google' => 'no_checkin', 'twitter' => 'paused' ) ), $orders[ $results[6] ] );
    }

    public function test_the_cached_list_is_dropped_on_a_check_in_and_the_route_reads_fresh() {
        $count = array( 'call', 'wcct_test_log_cached_count' );

        $results = $this->rest(
            array(
                self::settings(),
                array( 'call', 'wcct_test_log_clear', array( 600 ) ),
                $count,
                self::order(),
                $count,
                self::get(),
                array( 'request', 'POST', '/wcct/v1/events', array( 'order_id' => 3001, 'key' => 'wc_order_ctguest0001', 'platform' => 'facebook', 'loaded' => true ), 'logged_out' ),
                $count,
            )
        );

        $this->assertSame( array( 1, 1 ), array( $results[2], $results[4] ), 'the new order waits for the cache' );
        $this->assertSame( 2, $results[5]['data']['total'], 'the route reads fresh' );
        $this->assertSame( 2, $results[7], 'a check-in drops the cache' );
    }

    public function test_no_orders_gives_an_empty_log() {
        $results = $this->rest( array( self::settings(), array( 'call', 'wcct_test_log_clear' ), self::get() ) );

        $this->assertSame( array( array(), 0, 0 ), array( $results[2]['data']['orders'], $results[2]['data']['total'], $results[2]['data']['pages'] ) );
        $this->assertSame( 600, $results[2]['data']['settle'] );
    }
}
