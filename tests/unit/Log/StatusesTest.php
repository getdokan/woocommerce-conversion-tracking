<?php

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Log\CheckIn;
use StorePulse\ConversionTracking\Log\OrderLog;
use StorePulse\ConversionTracking\Log\Statuses;

/**
 * Tests for the statuses worked out on read (#113)
 */
class StatusesTest extends TestCase {

    const NOW = 1767225600;

    const ACTIVE = array( 'facebook', 'ga4', 'twitter' );

    protected function tearDown(): void {
        unset( $GLOBALS['wcct_test_filters'] );
    }

    /**
     * An order placed $age seconds ago from the checkout, paid, with the thank-you page seen.
     */
    private static function order( array $props = array(), $age = 3600 ) {
        return array_merge(
            array(
                'id'             => 1312,
                'number'         => '1312',
                'status'         => 'processing',
                'created_via'    => 'checkout',
                'created'        => self::NOW - $age,
                'paid'           => self::NOW - $age,
                'thankyou'       => self::NOW - $age + 5,
                'total'          => '200.00',
                'currency'       => 'USD',
                'renewal'        => false,
                'counted_before' => false,
            ),
            $props
        );
    }

    /**
     * A check-in row written $age seconds ago.
     */
    private static function row( $platform, $loaded, array $columns = array(), $age = 3600 ) {
        $at = OrderLog::datetime( self::NOW - $age );

        return array_merge(
            array(
                'order_id'         => 1312,
                'platform'         => $platform,
                'source'           => 'browser',
                'library_loaded'   => $loaded ? '1' : '0',
                'consent'          => 'off',
                'reason'           => $loaded ? '' : 'timeout',
                'event_id'         => 'wcct_1312_purchase',
                'thankyou_seen_at' => $at,
                'sent_at'          => $loaded ? $at : null,
                'created_at'       => $at,
                'updated_at'       => $at,
            ),
            $columns
        );
    }

    private static function all_sent( $age = 3600 ) {
        return array( self::row( 'facebook', true, array(), $age ), self::row( 'ga4', true, array(), $age ), self::row( 'twitter', true, array(), $age ) );
    }

    /**
     * Platform ID => status of a result.
     */
    private static function by_platform( $result ) {
        return array_combine( array_column( $result['platforms'], 'id' ), array_column( $result['platforms'], 'status' ) );
    }

    /**
     * Each case: order props, rows, active, paused => order status, cause, platform statuses.
     */
    public function cases() {
        $meta_blocked = array( self::row( 'facebook', false ), self::row( 'ga4', true ), self::row( 'twitter', true ) );

        return array(
            'checked in and confirmed: counted once'                 => array(
                array(),
                self::all_sent(),
                self::ACTIVE,
                array(),
                Statuses::CONFIRMED,
                '',
                array( 'facebook' => Statuses::CONFIRMED, 'google' => Statuses::CONFIRMED, 'twitter' => Statuses::CONFIRMED ),
            ),
            'checked in, Meta not confirmed after 10 minutes'        => array(
                array(),
                $meta_blocked,
                self::ACTIVE,
                array(),
                Statuses::NOT_CONFIRMED,
                '',
                array( 'facebook' => Statuses::NOT_CONFIRMED, 'google' => Statuses::CONFIRMED, 'twitter' => Statuses::CONFIRMED ),
            ),
            'checked in, Meta not handed over within 10 minutes'     => array(
                array(),
                array( self::row( 'facebook', false, array(), 120 ), self::row( 'ga4', true, array(), 120 ), self::row( 'twitter', true, array(), 120 ) ),
                self::ACTIVE,
                array(),
                Statuses::WAITING,
                '',
                array( 'facebook' => Statuses::WAITING, 'google' => Statuses::CONFIRMED, 'twitter' => Statuses::CONFIRMED ),
            ),
            'no check-in after 10 minutes, no thank-you page visit'  => array(
                array( 'thankyou' => 0 ),
                array(),
                self::ACTIVE,
                array(),
                Statuses::NO_CHECKIN,
                Statuses::CAUSE_NOT_REACHED,
                array( 'facebook' => Statuses::NO_CHECKIN, 'google' => Statuses::NO_CHECKIN, 'twitter' => Statuses::NO_CHECKIN ),
            ),
            'no check-in after 10 minutes, thank-you page rendered'  => array(
                array(),
                array(),
                self::ACTIVE,
                array(),
                Statuses::NO_CHECKIN,
                Statuses::CAUSE_NOT_REPORTED,
                array( 'facebook' => Statuses::NO_CHECKIN, 'google' => Statuses::NO_CHECKIN, 'twitter' => Statuses::NO_CHECKIN ),
            ),
            'no check-in yet, under 10 minutes'                      => array(
                array( 'created' => self::NOW - 300, 'paid' => self::NOW - 300, 'thankyou' => 0 ),
                array(),
                self::ACTIVE,
                array(),
                Statuses::WAITING,
                '',
                array( 'facebook' => Statuses::WAITING, 'google' => Statuses::WAITING, 'twitter' => Statuses::WAITING ),
            ),
            'paid off-site 9 minutes after the order: still waiting' => array(
                array( 'paid' => self::NOW - 60, 'thankyou' => 0 ),
                array(),
                self::ACTIVE,
                array(),
                Statuses::WAITING,
                '',
                array( 'facebook' => Statuses::WAITING, 'google' => Statuses::WAITING, 'twitter' => Statuses::WAITING ),
            ),
            'consent declined on Meta and X: skipped by design'     => array(
                array(),
                array( self::row( 'facebook', false, array( 'consent' => 'denied' ) ), self::row( 'twitter', false, array( 'consent' => 'denied' ) ) ),
                array( 'facebook', 'twitter' ),
                array(),
                Statuses::SKIPPED,
                Statuses::CAUSE_CONSENT,
                array( 'facebook' => Statuses::SKIPPED, 'twitter' => Statuses::SKIPPED ),
            ),
            'consent declined, Google pinged cookieless: counted'    => array(
                array(),
                array( self::row( 'facebook', false, array( 'consent' => 'denied' ) ), self::row( 'ga4', true, array( 'consent' => 'denied' ) ) ),
                array( 'facebook', 'ga4' ),
                array(),
                Statuses::CONFIRMED,
                '',
                array( 'facebook' => Statuses::SKIPPED, 'google' => Statuses::CONFIRMED ),
            ),
            'pending payment, sent on the thank-you page'            => array(
                array( 'status' => 'on-hold', 'paid' => 0 ),
                self::all_sent(),
                self::ACTIVE,
                array(),
                Statuses::PENDING,
                '',
                array( 'facebook' => Statuses::PENDING, 'google' => Statuses::PENDING, 'twitter' => Statuses::PENDING ),
            ),
            'pending payment, Meta not handed over'                  => array(
                array( 'status' => 'pending', 'paid' => 0 ),
                $meta_blocked,
                self::ACTIVE,
                array(),
                Statuses::PENDING,
                '',
                array( 'facebook' => Statuses::PENDING, 'google' => Statuses::PENDING, 'twitter' => Statuses::PENDING ),
            ),
            'failed order: nothing sent, by design'                  => array(
                array( 'status' => 'failed', 'paid' => 0 ),
                array(),
                self::ACTIVE,
                array(),
                Statuses::SKIPPED,
                Statuses::CAUSE_ORDER_STATUS,
                array( 'facebook' => Statuses::SKIPPED, 'google' => Statuses::SKIPPED, 'twitter' => Statuses::SKIPPED ),
            ),
            'cancelled after it was handed over: still counted'      => array(
                array( 'status' => 'cancelled' ),
                self::all_sent(),
                self::ACTIVE,
                array(),
                Statuses::CONFIRMED,
                '',
                array( 'facebook' => Statuses::CONFIRMED, 'google' => Statuses::CONFIRMED, 'twitter' => Statuses::CONFIRMED ),
            ),
            'paused platform: nothing sent to it'                    => array(
                array(),
                array( self::row( 'ga4', true ) ),
                array( 'ga4' ),
                array( 'facebook' ),
                Statuses::CONFIRMED,
                '',
                array( 'google' => Statuses::CONFIRMED, 'facebook' => Statuses::PAUSED ),
            ),
            'every platform paused'                                  => array(
                array(),
                array(),
                array(),
                array( 'facebook', 'twitter' ),
                Statuses::SKIPPED,
                Statuses::CAUSE_PAUSED,
                array( 'facebook' => Statuses::PAUSED, 'twitter' => Statuses::PAUSED ),
            ),
            'a platform without a row is listed but not judged'      => array(
                array(),
                array( self::row( 'facebook', true ) ),
                self::ACTIVE,
                array(),
                Statuses::CONFIRMED,
                '',
                array( 'facebook' => Statuses::CONFIRMED, 'google' => Statuses::NO_CHECKIN, 'twitter' => Statuses::NO_CHECKIN ),
            ),
            'created in admin, paid on the pay page: it was sent'    => array(
                array( 'created_via' => 'admin' ),
                self::all_sent(),
                self::ACTIVE,
                array(),
                Statuses::CONFIRMED,
                '',
                array( 'facebook' => Statuses::CONFIRMED, 'google' => Statuses::CONFIRMED, 'twitter' => Statuses::CONFIRMED ),
            ),
            'a hidden page inside 10 minutes: still waiting'         => array(
                array(),
                array( self::row( 'facebook', false, array( 'reason' => 'hidden' ), 120 ) ),
                array( 'facebook' ),
                array(),
                Statuses::WAITING,
                '',
                array( 'facebook' => Statuses::WAITING ),
            ),
            'paused after the order was placed: not paused for it'   => array(
                array( 'thankyou' => 0 ),
                array(),
                array( 'ga4' ),
                array( 'facebook' => self::NOW - 60 ),
                Statuses::NO_CHECKIN,
                Statuses::CAUSE_NOT_REACHED,
                array( 'google' => Statuses::NO_CHECKIN, 'facebook' => Statuses::NO_CHECKIN ),
            ),
            'paused before the order was placed'                     => array(
                array( 'thankyou' => 0 ),
                array(),
                array( 'ga4' ),
                array( 'facebook' => self::NOW - 7200 ),
                Statuses::NO_CHECKIN,
                Statuses::CAUSE_NOT_REACHED,
                array( 'google' => Statuses::NO_CHECKIN, 'facebook' => Statuses::PAUSED ),
            ),
            'created 2 days ago, paid and checked in today'          => array(
                array( 'created' => self::NOW - 2 * 86400, 'paid' => self::NOW - 3600 ),
                self::all_sent(),
                self::ACTIVE,
                array(),
                Statuses::CONFIRMED,
                '',
                array( 'facebook' => Statuses::CONFIRMED, 'google' => Statuses::CONFIRMED, 'twitter' => Statuses::CONFIRMED ),
            ),
            'Google Analytics confirmed, Ads not: not confirmed'     => array(
                array(),
                array( self::row( 'ga4', true ), self::row( 'adwords', false ) ),
                array( 'ga4', 'adwords' ),
                array(),
                Statuses::NOT_CONFIRMED,
                '',
                array( 'google' => Statuses::NOT_CONFIRMED ),
            ),
            'a reload confirmed it later: counted once'              => array(
                array(),
                array( self::row( 'facebook', true, array( 'reason' => '', 'thankyou_seen_at' => OrderLog::datetime( self::NOW - 3600 ), 'sent_at' => OrderLog::datetime( self::NOW - 1800 ) ) ) ),
                array( 'facebook' ),
                array(),
                Statuses::CONFIRMED,
                '',
                array( 'facebook' => Statuses::CONFIRMED ),
            ),
        );
    }

    /**
     * @dataProvider cases
     */
    public function test_status_rules( array $order, array $rows, array $active, array $paused, $status, $cause, array $platforms ) {
        $result = Statuses::evaluate( self::order( $order ), $rows, $active, $paused, self::NOW );

        $this->assertNotNull( $result );
        $this->assertSame( array( $status, $cause ), array( $result['status'], $result['cause'] ) );
        $this->assertSame( $platforms, self::by_platform( $result ) );
    }

    /**
     * Each case: order props and rows of an order the log never lists.
     */
    public function excluded() {
        return array(
            'created in admin'                        => array( array( 'created_via' => 'admin' ), array() ),
            'POS'                                     => array( array( 'created_via' => 'pos' ), array() ),
            'WooCommerce POS app'                     => array( array( 'created_via' => 'pos-rest-api' ), array() ),
            'REST API'                                => array( array( 'created_via' => 'rest-api' ), array() ),
            'subscription renewal by created_via'     => array( array( 'created_via' => 'subscription' ), array() ),
            'subscription renewal by order meta'      => array( array( 'renewal' => true ), array() ),
            'created before 3.0 counted it'           => array( array( 'counted_before' => true ), array() ),
            'checkout draft'                          => array( array( 'status' => 'checkout-draft' ), array() ),
            'unpaid, never reached the thank-you page' => array( array( 'status' => 'pending', 'paid' => 0, 'thankyou' => 0 ), array() ),
        );
    }

    /**
     * @dataProvider excluded
     */
    public function test_excluded_orders_show_nothing( array $order, array $rows ) {
        $this->assertNull( Statuses::evaluate( self::order( $order ), $rows, self::ACTIVE, array(), self::NOW ) );
    }

    public function test_an_unpaid_status_filtered_out_of_the_sending_ones_is_skipped() {
        $GLOBALS['wcct_test_filters']['wcct_unpaid_order_statuses'] = function () {
            return array( 'pending' );
        };

        $result = Statuses::evaluate( self::order( array( 'status' => 'on-hold', 'paid' => 0 ) ), array(), self::ACTIVE, array(), self::NOW );

        $this->assertSame( array( Statuses::SKIPPED, Statuses::CAUSE_ORDER_STATUS ), array( $result['status'], $result['cause'] ) );

        // Still pending payment where it was sent before the filter changed.
        $this->assertSame( Statuses::PENDING, Statuses::evaluate( self::order( array( 'status' => 'on-hold', 'paid' => 0 ) ), self::all_sent(), self::ACTIVE, array(), self::NOW )['status'] );
    }

    public function test_a_hidden_check_in_upgraded_by_the_timeout_reads_the_timeout() {
        $row     = self::row( 'facebook', false, array( 'reason' => 'hidden', 'updated_at' => OrderLog::datetime( self::NOW - 3600 ) ) );
        $changes = CheckIn::merge( $row, array( 'loaded' => false, 'reason' => 'timeout' ), self::NOW - 3590 );
        $result  = Statuses::evaluate( self::order(), array( array_merge( $row, $changes ) ), array( 'facebook' ), array(), self::NOW );

        $this->assertSame( array( Statuses::NOT_CONFIRMED, 'timeout' ), array( $result['platforms'][0]['status'], $result['platforms'][0]['cause'] ) );
    }

    public function test_an_unjudged_platform_never_makes_the_order_not_confirmed_or_no_check_in() {
        $result = Statuses::evaluate( self::order(), array( self::row( 'facebook', true ) ), self::ACTIVE, array(), self::NOW );

        $this->assertSame( array( false, true, true ), array_column( $result['platforms'], 'unknown' ) );
        $this->assertSame( Statuses::CONFIRMED, $result['status'] );
    }

    public function test_the_window_includes_orders_paid_later() {
        $old = self::NOW - 2 * 86400;

        $this->assertTrue( Statuses::in_window( self::NOW - 3600, array(), self::NOW ) );
        $this->assertFalse( Statuses::in_window( $old, array(), self::NOW ) );
        $this->assertTrue( Statuses::in_window( $old, array( self::row( 'facebook', true ) ), self::NOW ), 'paid on the pay page today' );
        $this->assertFalse( Statuses::in_window( $old, array( self::row( 'facebook', true, array(), 2 * 86400 ) ), self::NOW ) );
    }

    public function test_no_platform_to_judge_shows_nothing() {
        $this->assertNull( Statuses::evaluate( self::order(), array(), array(), array(), self::NOW ) );
        $this->assertNull( Statuses::evaluate( self::order( array( 'status' => 'failed' ) ), array(), array(), array(), self::NOW ) );
    }

    public function test_the_skipped_sources_are_filterable() {
        $GLOBALS['wcct_test_filters']['wcct_log_skipped_created_via'] = function ( $values ) {
            return array_merge( $values, array( 'funnel-builder' ) );
        };

        $this->assertNull( Statuses::evaluate( self::order( array( 'created_via' => 'funnel-builder' ) ), array(), self::ACTIVE, array(), self::NOW ) );
        $this->assertNotNull( Statuses::evaluate( self::order( array( 'created_via' => 'store-api' ) ), array(), self::ACTIVE, array(), self::NOW ) );
    }

    public function test_a_not_confirmed_platform_keeps_its_evidence() {
        $result = Statuses::evaluate( self::order(), array( self::row( 'facebook', false, array( 'reason' => 'hidden' ) ) ), array( 'facebook' ), array(), self::NOW );
        $meta   = $result['platforms'][0];

        $this->assertSame( array( 'facebook', array( 'facebook' ), 'hidden', false, 0 ), array( $meta['id'], $meta['integrations'], $meta['cause'], $meta['sent'], $meta['sent_at'] ) );
        $this->assertSame( self::NOW - 3600, $meta['checked_in_at'] );
        $this->assertSame( 'wcct_1312_purchase', $meta['event_id'] );
        $this->assertSame( 0, $result['settles_at'] );
    }

    public function test_a_waiting_order_says_when_it_settles() {
        $result = Statuses::evaluate( self::order(), array( self::row( 'facebook', false, array(), 120 ) ), array( 'facebook' ), array(), self::NOW );

        $this->assertSame( self::NOW - 120 + Statuses::SETTLE, $result['settles_at'] );

        $result = Statuses::evaluate( self::order( array( 'thankyou' => 0 ), 60 ), array(), array( 'facebook' ), array(), self::NOW );

        $this->assertSame( self::NOW - 60 + Statuses::SETTLE, $result['settles_at'] );
    }

    public function test_google_integrations_merge_into_one_platform() {
        $result = Statuses::evaluate( self::order(), array( self::row( 'ga4', true, array(), 3000 ), self::row( 'adwords', true, array(), 3600 ) ), array( 'ga4', 'adwords' ), array(), self::NOW );
        $google = $result['platforms'][0];

        $this->assertCount( 1, $result['platforms'] );
        $this->assertSame( array( 'google', array( 'ga4', 'adwords' ), Statuses::CONFIRMED, true ), array( $google['id'], $google['integrations'], $google['status'], $google['sent'] ) );
        $this->assertSame( self::NOW - 3600, $google['sent_at'] );
    }

    public function test_rows_from_another_source_are_ignored() {
        $result = Statuses::evaluate( self::order(), array( self::row( 'facebook', true, array( 'source' => 'server' ) ) ), array( 'facebook' ), array(), self::NOW );

        $this->assertSame( Statuses::NO_CHECKIN, $result['status'] );
    }

    public function test_tabs_and_counts() {
        $orders = array(
            array( 'status' => Statuses::CONFIRMED ),
            array( 'status' => Statuses::CONFIRMED ),
            array( 'status' => Statuses::NOT_CONFIRMED ),
            array( 'status' => Statuses::PENDING ),
            array( 'status' => Statuses::NO_CHECKIN ),
            array( 'status' => Statuses::SKIPPED ),
            array( 'status' => Statuses::WAITING ),
        );

        $this->assertSame(
            array( 'all' => 7, 'tracked' => 2, 'not-confirmed' => 1, 'pending' => 1 ),
            Statuses::counts( $orders )
        );
        $this->assertTrue( Statuses::in_tab( array( 'status' => Statuses::NO_CHECKIN ), Statuses::TAB_ALL ) );

        foreach ( array( Statuses::TAB_TRACKED, Statuses::TAB_NOT_CONFIRMED, Statuses::TAB_PENDING ) as $tab ) {
            $this->assertFalse( Statuses::in_tab( array( 'status' => Statuses::NO_CHECKIN ), $tab ), 'No check-in shows under All only' );
            $this->assertFalse( Statuses::in_tab( array( 'status' => Statuses::SKIPPED ), $tab ) );
        }

        $this->assertTrue( Statuses::in_tab( array( 'status' => Statuses::NOT_CONFIRMED ), Statuses::TAB_NOT_CONFIRMED ) );
        $this->assertFalse( Statuses::in_tab( array( 'status' => Statuses::CONFIRMED ), 'unknown' ) );
    }
}
