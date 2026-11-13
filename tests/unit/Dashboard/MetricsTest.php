<?php

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Dashboard\Metrics;

/**
 * Tests for the Dashboard numbers: counts, the hourly chart, platform card states and what to do next (#118)
 */
class MetricsTest extends TestCase {

    // 2026-01-01 10:42:00 UTC.
    const NOW = 1767264120;

    /**
     * An evaluated order created $age seconds ago, with platform ID => status.
     */
    private static function order( $id, $status, $age, array $platforms = array(), array $unknown = array() ) {
        $entries = array();

        foreach ( $platforms as $platform => $platform_status ) {
            $entries[] = array(
                'id'      => $platform,
                'status'  => $platform_status,
                'unknown' => in_array( $platform, $unknown, true ),
            );
        }

        return array(
            'id'        => $id,
            'number'    => (string) $id,
            'status'    => $status,
            'created'   => self::NOW - $age,
            'platforms' => $entries,
        );
    }

    private static function orders() {
        $all = array( 'facebook' => 'confirmed', 'google' => 'confirmed', 'twitter' => 'confirmed' );

        return array(
            self::order( 1312, 'confirmed', 60, $all ),
            self::order( 1311, 'not_confirmed', 11 * 60, array_merge( $all, array( 'facebook' => 'not_confirmed' ) ) ),
            self::order( 1310, 'confirmed', 44 * 60, $all ),
            self::order( 1308, 'pending', 90 * 60, array( 'facebook' => 'pending', 'google' => 'pending', 'twitter' => 'pending' ) ),
            self::order( 1307, 'skipped', 16 * 3600, array( 'facebook' => 'skipped' ) ),
            // X didn't check in while the others did: listed, never judged.
            self::order( 1306, 'confirmed', 17 * 3600 + 47 * 60, array( 'facebook' => 'confirmed', 'google' => 'confirmed', 'twitter' => 'no_checkin' ), array( 'twitter' ) ),
            self::order( 1304, 'no_checkin', 20 * 3600, array( 'facebook' => 'no_checkin', 'google' => 'no_checkin', 'twitter' => 'no_checkin' ) ),
            self::order( 1303, 'waiting', 120, array( 'facebook' => 'waiting' ) ),
            // Created two days ago, paid and checked in today.
            self::order( 1200, 'not_confirmed', 2 * 86400, array( 'facebook' => 'confirmed', 'twitter' => 'not_confirmed' ) ),
        );
    }

    public function test_purchases_tracked_are_confirmed_and_not_confirmed_orders() {
        $tally = Metrics::tally( self::orders() );

        $this->assertSame(
            array( 5, 3, 2, 1, 1, 1 ),
            array( $tally['purchases'], $tally['sent'], $tally['not_confirmed'], $tally['no_checkin'], $tally['pending'], $tally['waiting'] )
        );
        $this->assertSame(
            array(
                'facebook' => array( 'confirmed' => 4, 'not_confirmed' => 1 ),
                'google'   => array( 'confirmed' => 4, 'not_confirmed' => 0 ),
                'twitter'  => array( 'confirmed' => 3, 'not_confirmed' => 1 ),
            ),
            $tally['platforms']
        );
        $this->assertSame(
            array(
                array( 'id' => 1311, 'number' => '1311', 'platforms' => array( 'facebook' ) ),
                array( 'id' => 1200, 'number' => '1200', 'platforms' => array( 'twitter' ) ),
            ),
            $tally['not_confirmed_orders']
        );
    }

    public function test_an_empty_day() {
        $tally = Metrics::tally( array() );
        $hours = Metrics::hourly( array(), self::NOW );

        $this->assertSame( array( 0, 0, 0, array() ), array( $tally['purchases'], $tally['not_confirmed'], $tally['no_checkin'], $tally['not_confirmed_orders'] ) );
        $this->assertCount( 24, $hours );
        $this->assertSame( 0, array_sum( array_column( $hours, 'sent' ) ) + array_sum( array_column( $hours, 'not_confirmed' ) ) );
    }

    public function test_the_hourly_chart_buckets_by_created_and_draws_sent_and_not_confirmed_only() {
        $hours = Metrics::hourly( self::orders(), self::NOW );

        $this->assertCount( 24, $hours );
        // The last hour is the current one (10:00), the first starts 23 hours before it.
        $this->assertSame( array( 1767178800, '11:00' ), array( $hours[0]['start'], Metrics::hour_label( $hours[0]['start'], new DateTimeZone( 'UTC' ) ) ) );
        $this->assertSame( array( 1767261600, '10:00' ), array( $hours[23]['start'], Metrics::hour_label( $hours[23]['start'], new DateTimeZone( 'UTC' ) ) ) );

        // 1312 (10:41) and 1311 (10:31) in the current hour, 1310 (09:58) in the one before.
        $this->assertSame( array( 1, 1 ), array( $hours[23]['sent'], $hours[23]['not_confirmed'] ) );
        $this->assertSame( array( 1, 0 ), array( $hours[22]['sent'], $hours[22]['not_confirmed'] ) );
        // 1306 at 16:55 yesterday.
        $this->assertSame( array( 1, 0 ), array( $hours[5]['sent'], $hours[5]['not_confirmed'] ) );
        // 1200, created before the window without a check-in time, counts in its first hour.
        $this->assertSame( array( 0, 1 ), array( $hours[0]['sent'], $hours[0]['not_confirmed'] ) );

        // Pending, skipped, waiting and No check-in orders aren't drawn: the bars add up to the purchases tracked.
        $this->assertSame( 3, array_sum( array_column( $hours, 'sent' ) ) );
        $this->assertSame( 2, array_sum( array_column( $hours, 'not_confirmed' ) ) );
    }

    public function test_hours_start_on_the_sites_clock() {
        // UTC+5:30: 16:12 on the site's clock, so the current hour is 16:00 there.
        $hours = Metrics::hourly( array( self::order( 1, 'confirmed', 13 * 60 ) ), self::NOW, 19800 );

        $zone = new DateTimeZone( 'Asia/Kolkata' );

        $this->assertSame( '16:00', Metrics::hour_label( $hours[23]['start'], $zone ) );
        $this->assertSame( self::NOW - 12 * 60, $hours[23]['start'] );
        $this->assertSame( '17:00', Metrics::hour_label( $hours[0]['start'], $zone ) );
        // 15:59 on the site's clock falls in the hour before.
        $this->assertSame( array( 1, 0 ), array( $hours[22]['sent'], $hours[23]['sent'] ) );
    }

    public function test_an_order_paid_later_counts_at_its_check_in() {
        $order = self::order( 1200, 'confirmed', 2 * 86400, array( 'facebook' => 'confirmed', 'google' => 'confirmed' ) );

        $order['platforms'][0]['checked_in_at'] = self::NOW - 3 * 3600 - 60;
        $order['platforms'][1]['checked_in_at'] = self::NOW - 3 * 3600;

        $hours = Metrics::hourly( array( $order ), self::NOW );

        // 07:41, the first check-in, in the 07:00 hour.
        $this->assertSame( 1, $hours[20]['sent'] );
        $this->assertSame( 1, array_sum( array_column( $hours, 'sent' ) ) );

        // Without check-in times, the thank-you page visit.
        unset( $order['platforms'] );
        $order['thankyou'] = self::NOW - 5 * 3600;

        $this->assertSame( self::NOW - 5 * 3600, Metrics::chart_time( $order, $hours[0]['start'] ) );
        $this->assertSame( self::NOW - 60, Metrics::chart_time( array( 'created' => self::NOW - 60 ), $hours[0]['start'] ) );
    }

    public function test_hour_labels_follow_a_daylight_saving_change() {
        // Berlin moved from 02:00 CET to 03:00 CEST on 2026-03-29 (01:00 UTC); now is 10:40 CEST.
        $zone  = new DateTimeZone( 'Europe/Berlin' );
        $now   = 1774772400 + 20 * 60;
        $hours = Metrics::hourly( array( array( 'id' => 1, 'status' => 'confirmed', 'created' => 1774745400 ) ), $now, $zone->getOffset( new DateTimeImmutable( '@' . $now ) ) );
        $label = function ( $hour ) use ( $zone ) {
            return Metrics::hour_label( $hour['start'], $zone );
        };
        $labels = array_map( $label, $hours );

        $this->assertSame( '10:00', end( $labels ) );
        // 01:00 CET is followed by 03:00 CEST; the hours stay an hour apart.
        $at = array_search( '01:00', $labels, true );

        $this->assertSame( '03:00', $labels[ $at + 1 ] );
        $this->assertNotContains( '02:00', $labels );
        $this->assertSame( 3600, $hours[ $at + 1 ]['start'] - $hours[ $at ]['start'] );
        // 23 hours back across the change is 10:00 CET yesterday.
        $this->assertSame( '10:00', $labels[0] );
        // An order at 01:50 CET (00:50 UTC) is drawn in the 01:00 bar.
        $this->assertSame( 1, $hours[ $at ]['sent'] );
    }

    /**
     * @dataProvider states
     */
    public function test_platform_card_states( array $platform, $state ) {
        $this->assertSame( $state, Metrics::state( $platform ) );
    }

    public function states() {
        $last = array( 'order_number' => '1312', 'time' => self::NOW - 120 );

        return array(
            'not set up'                   => array( array( 'connected' => false, 'last_purchase' => $last ), 'not_set_up' ),
            'paused'                       => array( array( 'connected' => true, 'paused' => true, 'not_confirmed' => 2 ), 'paused' ),
            'a not confirmed purchase'     => array( array( 'connected' => true, 'not_confirmed' => 1, 'confirmed' => 4, 'last_purchase' => $last ), 'attention' ),
            'a setting to fix'             => array( array( 'connected' => true, 'needs_fix' => true, 'last_purchase' => $last ), 'attention' ),
            'confirmed today'              => array( array( 'connected' => true, 'confirmed' => 1, 'last_purchase' => $last ), 'working' ),
            'last purchase before the day' => array( array( 'connected' => true, 'confirmed' => 0, 'last_purchase' => $last ), 'working' ),
            'nothing yet'                  => array( array( 'connected' => true, 'confirmed' => 0, 'last_purchase' => null ), 'waiting' ),
        );
    }

    public function test_findings_that_open_a_platform_page() {
        $finding = function ( $url ) {
            return array( 'action' => array( 'url' => $url ) );
        };

        $this->assertSame( 'google', Metrics::finding_platform( $finding( '#/platforms/google?field=ads_label' ) ) );
        $this->assertSame( 'twitter', Metrics::finding_platform( $finding( '#/platforms/twitter' ) ) );
        $this->assertSame( 'bing_ads', Metrics::finding_platform( $finding( '#/platforms/bing_ads/' ) ) );
        $this->assertSame( '', Metrics::finding_platform( $finding( '#/doctor' ) ) );
        $this->assertSame( '', Metrics::finding_platform( $finding( 'https://example.com/#/platforms/google' ) ) );
        $this->assertSame( '', Metrics::finding_platform( array() ) );
    }

    public function test_open_findings_are_warnings_not_dismissed() {
        $report = array(
            'findings' => array(
                array( 'hash' => 'a', 'severity' => 'warning', 'dismissed' => false ),
                array( 'hash' => 'b', 'severity' => 'warning', 'dismissed' => true ),
                array( 'hash' => 'c', 'severity' => 'info', 'dismissed' => false ),
            ),
        );

        $this->assertSame( array( 'a' ), array_column( Metrics::open_findings( $report ), 'hash' ) );
        $this->assertSame( array(), Metrics::open_findings( array() ) );
    }

    private static function card( $id, $state, array $props = array() ) {
        return array_merge(
            array(
                'id'            => $id,
                'name'          => ucfirst( $id ),
                'state'         => $state,
                'not_confirmed' => 0,
                'needs_resetup' => false,
                'finding'       => null,
            ),
            $props
        );
    }

    private static function finding( $hash, $found, $url, array $action = array() ) {
        return array(
            'hash'   => $hash,
            'found'  => $found,
            'action' => array_merge( array( 'type' => 'link', 'url' => $url, 'confirm' => '' ), $action ),
        );
    }

    public function test_what_to_do_next_is_empty_when_everything_is_green() {
        $platforms = array( self::card( 'facebook', 'working' ), self::card( 'google', 'waiting' ), self::card( 'twitter', 'paused' ), self::card( 'custom', 'not_set_up' ) );

        $this->assertSame( array(), Metrics::next_steps( $platforms, array(), true ) );
    }

    public function test_what_to_do_next_lists_an_action_for_every_amber_state() {
        $label     = self::finding( str_repeat( 'a', 32 ), 'Your Google Ads conversion label looks incomplete.', '#/platforms/google?field=ads_label' );
        $meta      = self::finding( str_repeat( 'b', 32 ), 'Facebook for WooCommerce also sends purchases to Meta.', 'https://example.test/wp-admin/admin.php?page=wc-facebook', array( 'confirm' => 'Open its settings?' ) );
        $consent   = self::finding( str_repeat( 'c', 32 ), 'No cookie plugin is connected.', '#/platforms/facebook?field=consent' );
        $platforms = array(
            self::card( 'facebook', 'attention', array( 'not_confirmed' => 1 ) ),
            self::card( 'google', 'attention', array( 'finding' => $label ) ),
            self::card( 'twitter', 'attention', array( 'needs_resetup' => true, 'name' => 'X' ) ),
            self::card( 'custom', 'working' ),
        );
        $steps     = Metrics::next_steps( $platforms, array( $label, $meta, $consent ), true );

        $this->assertSame(
            array( 'not-confirmed-facebook', 'doctor-' . $label['hash'], 'resetup-twitter', 'cookie-choices', 'doctor-' . $meta['hash'], 'doctor-' . $consent['hash'] ),
            array_column( $steps, 'id' )
        );
        $this->assertSame( array( '#/log?tab=not-confirmed', 'Open the Activity log' ), array( $steps[0]['link'], $steps[0]['link_label'] ) );
        $this->assertSame( array( 'Your Google Ads conversion label looks incomplete.', '#/platforms/google?field=ads_label', 'Open Google' ), array( $steps[1]['text'], $steps[1]['link'], $steps[1]['link_label'] ) );
        $this->assertSame( array( 'Give X a new purchase event ID; the old codes were retired.', '#/platforms/twitter', 'Open X' ), array( $steps[2]['text'], $steps[2]['link'], $steps[2]['link_label'] ) );
        $this->assertSame( array( '#/platforms?section=cookie-choices', 'Check cookie choices' ), array( $steps[3]['link'], $steps[3]['link_label'] ) );
        // A link that leaves the app (and asks first) goes through the Doctor page.
        $this->assertSame( array( '#/doctor', "See the Doctor's note" ), array( $steps[4]['link'], $steps[4]['link_label'] ) );
        $this->assertSame( array( '#/platforms/facebook?field=consent', "See the Doctor's note" ), array( $steps[5]['link'], $steps[5]['link_label'] ) );
    }

    public function test_a_doctor_that_never_ran_is_a_next_step() {
        $steps = Metrics::next_steps( array( self::card( 'facebook', 'working' ) ), array(), false );

        $this->assertSame( array( array( 'id' => 'doctor', 'text' => 'Let the Doctor check for duplicate tracking.', 'link' => '#/doctor', 'link_label' => 'Check my store' ) ), $steps );
    }

    public function test_at_most_five_doctor_findings_are_listed() {
        $findings = array();

        for ( $i = 0; $i < 7; $i++ ) {
            $findings[] = self::finding( str_repeat( (string) $i, 32 ), "Finding $i", '#/doctor' );
        }

        $this->assertCount( 5, Metrics::next_steps( array(), $findings, true ) );
    }
}
