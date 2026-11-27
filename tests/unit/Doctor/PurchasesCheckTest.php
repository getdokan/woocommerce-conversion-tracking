<?php

require_once __DIR__ . '/DoctorCheckTestCase.php';

use StorePulse\ConversionTracking\Doctor\Check;
use StorePulse\ConversionTracking\Doctor\Checks\PurchasesCheck;
use StorePulse\ConversionTracking\Doctor\Context;
use StorePulse\ConversionTracking\Log\RepeatSends;
use StorePulse\ConversionTracking\Log\Statuses;

/**
 * The last 24 hours' purchases: sent, none twice, and the check-in route for logged-out visitors
 */
class PurchasesCheckTest extends DoctorCheckTestCase {

    const NOW = 1800000000;

    /**
     * A check-in row written $age seconds ago.
     */
    private static function row( $order_id, $platform, array $columns = array(), $age = 3500 ) {
        $at = gmdate( 'Y-m-d H:i:s', self::NOW - $age );

        return array_merge(
            array(
                'order_id'         => $order_id,
                'platform'         => $platform,
                'library_loaded'   => 1,
                'consent'          => 'off',
                'reason'           => '',
                'thankyou_seen_at' => $at,
                'created_at'       => $at,
            ),
            $columns
        );
    }

    /**
     * Orders placed $age seconds ago from the checkout, evaluated as the Activity log does, with their rows.
     *
     * @param int[]   $ages Order ID => age.
     * @param array[] $rows Rows of any of them.
     */
    private static function orders( array $ages, array $rows = array() ) {
        $orders = array();

        foreach ( $ages as $id => $age ) {
            $summary = array(
                'id'          => $id,
                'number'      => (string) $id,
                'status'      => 'processing',
                'created_via' => 'checkout',
                'created'     => self::NOW - $age,
                'paid'        => self::NOW - $age,
                'thankyou'    => 0,
            );
            $result  = Statuses::evaluate(
                $summary,
                array_values(
                    array_filter(
                        $rows,
                        function ( $row ) use ( $id ) {
                            return $id === $row['order_id'];
                        }
                    )
                ),
                array( 'facebook' ),
                array(),
                self::NOW
            );

            if ( $result ) {
                $orders[] = $result;
            }
        }

        return $orders;
    }

    /**
     * Context whose loopback answers with a status and an error code.
     */
    private static function answering( $status, $code = '' ) {
        return new Context(
            5,
            function ( $url, $args ) use ( $status, $code ) {
                $GLOBALS['wcct_test_requests'][] = array( $url, $args );

                return array(
                    'response' => array( 'code' => $status ),
                    'body'     => $code ? json_encode( array( 'code' => $code ) ) : '<html></html>',
                );
            }
        );
    }

    private function report( array $summary, $route = null ) {
        $check    = new PurchasesCheck();
        $findings = array_map(
            function ( $finding ) {
                return $finding->to_array();
            },
            $check->report( $summary, array(), $route )
        );

        return array( $check->label(), $findings );
    }

    protected function tearDown(): void {
        parent::tearDown();
        unset( $GLOBALS['wcct_test_requests'] );
    }

    public function test_it_is_a_remote_check() {
        $this->assertSame( Check::REMOTE, ( new PurchasesCheck() )->scope() );
    }

    public function test_the_counts_follow_the_activity_log_statuses() {
        $summary = PurchasesCheck::summarise(
            self::orders(
                array( 1 => 3600, 2 => 3600, 3 => 120, 4 => 3600, 5 => 3600 ),
                array(
                    self::row( 1, 'facebook' ),
                    self::row( 2, 'facebook', array( 'library_loaded' => 0, 'reason' => 'timeout' ) ),
                    self::row( 3, 'facebook', array( 'library_loaded' => 0 ), 60 ),
                    self::row( 4, 'facebook', array( 'library_loaded' => 0, 'consent' => 'denied' ) ),
                )
            ),
            array()
        );

        // Counted once, not confirmed, still within 10 minutes, declined cookies (by design), no check-in.
        $this->assertSame( array( 'orders' => 5, 'sent' => 1, 'pending' => 1, 'not_confirmed' => array( '2:facebook' ), 'repeats' => array() ), $summary );
    }

    public function test_no_purchases_reads_no_purchases_in_the_last_24_hours() {
        list( $label, $findings ) = $this->report( PurchasesCheck::summarise( array(), array() ) );

        $this->assertSame( 'No purchases in the last 24 hours', $label );
        $this->assertSame( array(), $findings );
    }

    public function test_purchases_all_sent_once_give_nothing() {
        $summary = PurchasesCheck::summarise(
            self::orders( array( 11 => 3600, 12 => 7200, 13 => 120 ), array( self::row( 11, 'facebook' ), self::row( 11, 'ga4' ), self::row( 12, 'facebook' ), self::row( 13, 'facebook', array( 'library_loaded' => 0 ), 100 ), self::row( 99, 'facebook', array( 'library_loaded' => 0 ) ) ) ),
            array()
        );

        $this->assertSame( array( 'orders' => 3, 'sent' => 2, 'pending' => 1, 'not_confirmed' => array(), 'repeats' => array() ), $summary );

        list( $label, $findings ) = $this->report( $summary );

        $this->assertSame( 'Purchases in the last 24 hours were sent, and none of them twice by this plugin', $label );
        $this->assertSame( array(), $findings );
    }

    public function test_orders_with_no_check_in_are_not_a_problem() {
        list( $label, $findings ) = $this->report( PurchasesCheck::summarise( self::orders( array( 21 => 3600 ) ), array() ) );

        $this->assertSame( 'No purchase in the last 24 hours has checked in from the thank-you page yet', $label );
        $this->assertSame( array(), $findings );
    }

    public function test_not_confirmed_and_twice_sent_purchases_give_findings() {
        $summary = PurchasesCheck::summarise(
            self::orders( array( 31 => 3600, 32 => 3600 ), array( self::row( 31, 'facebook', array( 'library_loaded' => 0, 'reason' => 'timeout' ) ), self::row( 31, 'twitter', array( 'library_loaded' => 0 ) ), self::row( 32, 'facebook' ) ) ),
            array( array( 'order_id' => 32, 'platform' => 'facebook', 'time' => self::NOW - 100 ) )
        );

        $states = array(
            'facebook' => array( 'id' => 'facebook', 'label' => 'Meta', 'integrations' => array( 'facebook' ) ),
            'twitter'  => array( 'id' => 'twitter', 'label' => 'X', 'integrations' => array( 'twitter' ) ),
        );
        $check    = new PurchasesCheck();
        $findings = array_map(
            function ( $finding ) {
                return $finding->to_array();
            },
            $check->report( $summary, $states )
        );

        $this->assertSame( array( '31:facebook', '31:twitter' ), $summary['not_confirmed'] );
        $this->assertSame( 'We couldn\'t confirm 1 purchase from the last 24 hours', $findings[0]['found'] );
        $this->assertSame( 'The thank-you page loaded, but we couldn\'t confirm the purchase was sent to Meta and X. The Activity log shows each order.', $findings[0]['why'] );
        $this->assertSame( array( 'Meta', 'X' ), $findings[0]['tags'] );
        $this->assertSame( array( 'link', '#/log' ), array( $findings[0]['action']['type'], $findings[0]['action']['url'] ) );
        $this->assertSame( '1 purchase was sent twice in the last 24 hours', $findings[1]['found'] );
        $this->assertSame( array( 'Duplicate', array( 'Meta' ), 'sure' ), array( $findings[1]['label'], $findings[1]['tags'], $findings[1]['confidence'] ) );
    }

    public function test_the_ten_minutes_run_from_the_check_in_not_from_the_order() {
        // A slow payment: the order is an hour old, but its thank-you page checked in a minute ago.
        $miss = array( 'library_loaded' => 0, 'reason' => 'timeout' );

        $this->assertSame( 1, PurchasesCheck::summarise( self::orders( array( 1 => 3600 ), array( self::row( 1, 'facebook', $miss, 60 ) ) ), array() )['pending'] );
        $this->assertSame( array( '1:facebook' ), PurchasesCheck::summarise( self::orders( array( 1 => 3600 ), array( self::row( 1, 'facebook', $miss, 700 ) ) ), array() )['not_confirmed'] );
    }

    public function test_only_pending_check_ins_say_they_are_still_being_confirmed() {
        list( $label, $findings ) = $this->report( PurchasesCheck::summarise( self::orders( array( 51 => 120 ), array( self::row( 51, 'facebook', array( 'library_loaded' => 0 ), 100 ) ) ), array() ) );

        $this->assertSame( 'The latest purchases are still being confirmed; check again in a few minutes', $label );
        $this->assertSame( array(), $findings );
    }

    public function test_findings_keep_their_hash_while_the_same_orders_are_involved() {
        $miss   = array( 'library_loaded' => 0 );
        $first  = $this->report( PurchasesCheck::summarise( self::orders( array( 61 => 3600 ), array( self::row( 61, 'facebook', $miss ) ) ), array() ) )[1];
        $second = $this->report( PurchasesCheck::summarise( self::orders( array( 61 => 3600 ), array( self::row( 61, 'facebook', $miss ), self::row( 61, 'twitter', $miss ) ) ), array() ) )[1];
        $third  = $this->report( PurchasesCheck::summarise( self::orders( array( 61 => 3600, 62 => 3600 ), array( self::row( 61, 'facebook', $miss ), self::row( 62, 'facebook', $miss ) ) ), array() ) )[1];

        $this->assertSame( $first[0]['hash'], $second[0]['hash'], 'Another platform of the same order' );
        $this->assertNotSame( $first[0]['hash'], $third[0]['hash'], 'Another order' );
    }

    public function test_the_route_answering_as_designed_gives_nothing() {
        $this->assertNull( PurchasesCheck::route_answer( self::answering( 403, 'wcct_rest_invalid_token' ) ) );

        $request = $GLOBALS['wcct_test_requests'][0];

        $this->assertSame( 'https://shop.test/wp-json/wcct/v1/events', $request[0] );
        $this->assertSame( array( 'POST', array(), '{"type":"seen","token":"x"}' ), array( $request[1]['method'], $request[1]['cookies'], $request[1]['body'] ) );
        $this->assertNull( PurchasesCheck::route_answer( self::answering( 429, 'wcct_rest_throttled' ) ) );
        $this->assertNull( PurchasesCheck::route_answer( self::answering( 429 ) ), 'Any rate limit means the request got through' );
        $this->assertNull( PurchasesCheck::route_answer( self::answering( 301 ) ), 'A redirect: we can\'t tell' );
    }

    public function test_a_blocked_route_is_a_good_to_know_note() {
        foreach ( array( array( 401, 'rest_not_logged_in' ), array( 404, 'rest_no_route' ), array( 403, 'rest_forbidden' ), array( 403, '' ) ) as $answer ) {
            $route = PurchasesCheck::route_answer( self::answering( $answer[0], $answer[1] ) );

            $this->assertSame( array( 'code' => $answer[0], 'error' => $answer[1] ), $route );
        }

        list( , $findings ) = $this->report( PurchasesCheck::summarise( array(), array() ), $route );

        $this->assertSame( 'Purchase check-ins use the backup route', $findings[0]['found'] );
        $this->assertSame( array( 'info', 'unsure', 'recheck' ), array( $findings[0]['severity'], $findings[0]['confidence'], $findings[0]['action']['type'] ) );
    }

    public function test_no_answer_at_all_is_not_a_finding() {
        $this->assertNull( PurchasesCheck::route_answer( new Context( 0 ) ), 'Budget spent' );
        $this->assertNull(
            PurchasesCheck::route_answer(
                new Context(
                    5,
                    function () {
                        return new WP_Error( 'http_request_failed' );
                    }
                )
            ),
            'Loopback requests fail'
        );
    }

    public function test_repeat_sends_are_kept_once_per_order_and_platform_for_24_hours() {
        RepeatSends::record( 41, 'facebook', self::NOW - 90000 );
        RepeatSends::record( 42, 'facebook', self::NOW - 60 );
        RepeatSends::record( 42, 'facebook', self::NOW );
        RepeatSends::record( 42, 'ga4', self::NOW );

        $this->assertSame(
            array(
                array( 'order_id' => 42, 'platform' => 'facebook', 'time' => self::NOW - 60 ),
                array( 'order_id' => 42, 'platform' => 'ga4', 'time' => self::NOW ),
            ),
            RepeatSends::since( self::NOW - 86400 )
        );
        $this->assertCount( 2, $GLOBALS['wcct_test_options'][ RepeatSends::OPTION ], 'Older entries are dropped on the next write' );
    }
}
