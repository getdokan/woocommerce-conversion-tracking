<?php
// phpcs:disable

/**
 * The Dashboard's metrics route (#118), the Not confirmed badge and the alert switches (#117), from orders and rows seeded in the test store
 */
class MetricsRouteTest extends WCCT_Test_Rest_Case {

    const ROUTE = '/wcct/v1/metrics';

    // Every platform that checks in with lite_all(): Meta, Google Ads, GA4, X, Custom.
    const ALL = array( 'facebook', 'adwords', 'ga4', 'twitter', 'custom' );

    protected function setUp(): void {
        if ( self::pro_loaded() ) {
            $this->markTestSkipped( 'Free only.' );
        }
    }

    /**
     * The 2.x settings the storefront reads, and the same settings as 3.0 (as the migration writes them).
     */
    private static function settings() {
        return array(
            array( 'set_option', 'wcct_settings', WCCT_Test_Settings::lite_all() ),
            array( 'call', 'wcct_test_v3_from_v1' ),
            array( 'set_option', 'wcct_connected_at', null ),
            array( 'set_option', 'wcct_doctor_results', null ),
            array( 'set_option', 'wcct_doctor_new', null ),
            array( 'call', 'wcct_test_last_purchase_clear' ),
            array( 'call', 'delete_transient', array( 'wcct_alert_badge' ) ),
        );
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

    private static function get( $user = 'admin' ) {
        return array( 'request', 'GET', self::ROUTE, null, $user );
    }

    private static function by_id( array $platforms ) {
        return array_column( $platforms, null, 'id' );
    }

    public function test_only_managers_read_the_metrics() {
        $steps = self::settings();
        $users = array( 'admin' => 200, 'shop_manager' => 403, 'customer' => 403, 'subscriber' => 403, 'seller' => 403, 'logged_out' => 401 );

        foreach ( array_keys( $users ) as $user ) {
            $steps[] = self::get( $user );
        }

        $this->assertSame( array_values( $users ), array_slice( self::statuses( $this->rest( $steps ) ), count( self::settings() ) ) );
    }

    public function test_numbers_chart_cards_and_next_steps_from_real_orders() {
        $steps = array_merge(
            self::settings(),
            array(
                array( 'call', 'wcct_test_log_clear' ),
                // Counted once, 50 minutes ago.
                self::order( array( 'age' => 3000, 'rows' => self::rows( self::ALL, array(), 2990 ) ) ),
                // Meta's script blocked, an hour ago.
                self::order( array( 'rows' => self::rows( array( 'adwords', 'ga4', 'twitter', 'custom' ) ) ) ),
                // No thank-you page visit.
                self::order( array( 'thankyou_age' => null ) ),
                // Pending payment, sent on the thank-you page.
                self::order( array( 'status' => 'on-hold', 'rows' => self::rows( self::ALL ) ) ),
                // Placed two minutes ago, still settling.
                self::order( array( 'age' => 120, 'thankyou_age' => null ) ),
                // Admin orders never count.
                self::order( array( 'created_via' => 'admin' ) ),
                // Meta's last purchase was handed over before today's window (kept past the prune).
                array( 'set_option', 'wcct_last_purchase_facebook', array( 'last_purchase_at' => time() - 3 * 86400, 'order_id' => 1290, 'order_number' => '1290' ) ),
                array( 'set_option', 'wcct_last_purchase_ga4', array( 'last_purchase_at' => time() - 120, 'order_id' => 1312, 'order_number' => '1312' ) ),
                self::get(),
                array( 'call', 'wcct_test_badge_orders' ),
                array( 'option', '_transient_wcct_alert_badge' ),
                // WordPress's own wording for two minutes.
                array( 'call', 'human_time_diff', array( 0, 120 ) ),
            )
        );
        $results = $this->rest( $steps );
        $offset  = count( self::settings() ) + 1;

        list( $counted, $missed, $not_reached, $pending, $waiting ) = array_slice( $results, $offset, 5 );

        $response = $results[ $offset + 8 ];
        $data     = $response['data'];

        $this->assertSame( 200, $response['status'] );
        $this->assertSame(
            array( 2, 1, 1, 1, 1, 1 ),
            array( $data['purchases'], $data['sent'], $data['not_confirmed'], $data['no_checkin'], $data['pending'], $data['waiting'] )
        );
        $this->assertSame( array( 'facebook' => 1 ), $data['not_confirmed_by'] );

        // 24 hours; only sent and not confirmed orders are drawn, No check-in never.
        $this->assertCount( 24, $data['hourly'] );
        $this->assertSame( 1, array_sum( array_column( $data['hourly'], 'sent' ) ) );
        $this->assertSame( 1, array_sum( array_column( $data['hourly'], 'not_confirmed' ) ) );
        $this->assertMatchesRegularExpression( '/^\d\d:00$/', $data['hourly'][0]['label'] );
        $this->assertSame( $data['hourly'][22]['start'], gmdate( 'c', strtotime( $data['hourly'][23]['start'] ) - 3600 ) );

        // The card names the order and the platform.
        $this->assertSame( array( array( 'id' => $missed, 'number' => (string) $missed, 'platforms' => array( 'facebook' ) ) ), $data['not_confirmed_orders'] );
        $this->assertSame( array( 'not_confirmed' => true, 'doctor_new' => true ), $data['alerts'] );

        $platforms = self::by_id( $data['platforms'] );

        $this->assertSame( array( 'facebook', 'google', 'twitter', 'custom' ), array_column( $data['platforms'], 'id' ) );
        $this->assertSame( array( 'attention', 1 ), array( $platforms['facebook']['state'], $platforms['facebook']['not_confirmed'] ) );
        $this->assertSame( "We couldn't confirm 1 purchase in the last 24 hours.", $platforms['facebook']['note']['text'] );
        $this->assertSame( '#/log?tab=not-confirmed', $platforms['facebook']['note']['link'] );
        $this->assertSame( '1290', $platforms['facebook']['last_purchase']['order_number'], 'The last purchase survives the 24-hour prune' );

        $this->assertSame( 'working', $platforms['google']['state'] );
        $this->assertSame( array( 1312, '1312' ), array( $platforms['google']['last_purchase']['order_id'], $platforms['google']['last_purchase']['order_number'] ) );
        $this->assertSame( 'Last purchase ' . $results[ $offset + 11 ] . ' ago, #1312', $platforms['google']['note']['text'] );
        $this->assertSame( 'working', $platforms['twitter']['state'] );
        $this->assertNull( $platforms['twitter']['last_purchase'] );
        $this->assertSame( 4, $data['platforms_connected'] );
        $this->assertSame( 3, $data['platforms_working'] );

        // What to do next: Meta's not confirmed purchase and the Doctor that never ran.
        $this->assertSame( array( 'not-confirmed-facebook', 'cookie-choices', 'doctor' ), array_column( $data['next_steps'], 'id' ) );
        $this->assertSame( '#/platforms?section=cookie-choices', $data['next_steps'][1]['link'] );
        $this->assertSame( array( null, '', 0, 0 ), array( $data['doctor']['ran_at'], $data['doctor']['ran_ago'], $data['doctor']['open'], $data['doctor']['new'] ) );

        // The menu badge reads only the check-ins that weren't handed over, and the route left it the same count.
        $this->assertSame( array( $missed ), $results[ $offset + 9 ] );
        $this->assertSame( array( 'count' => 1 ), $results[ $offset + 10 ] );

        // No customer data anywhere.
        $json = json_encode( $data );

        $this->assertStringNotContainsString( 'private.person', $json );
        $this->assertStringNotContainsString( 'Private', $json );
    }

    public function test_waiting_for_first_event_and_connected_ago() {
        $results = $this->rest(
            array_merge(
                self::settings(),
                array(
                    array( 'call', 'wcct_test_log_clear' ),
                    // Connected through the settings route five minutes ago; known before for the others.
                    array( 'set_option', 'wcct_connected_at', array( 'facebook' => 0, 'google' => time() - 300, 'twitter' => 0, 'custom' => 0 ) ),
                    self::get(),
                    array( 'call', 'human_time_diff', array( 0, 300 ) ),
                )
            )
        );
        $five      = array_pop( $results );
        $data      = end( $results )['data'];
        $platforms = self::by_id( $data['platforms'] );

        $this->assertSame( array( 0, 0, 0 ), array( $data['purchases'], $data['not_confirmed'], $data['no_checkin'] ) );
        $this->assertSame( 'waiting', $platforms['google']['state'] );
        $this->assertSame( "Connected $five ago. This updates when the first purchase checks in. Place a test order to see it now.", $platforms['google']['note']['text'] );
        // Connected before we kept times: no "Connected … ago".
        $this->assertSame( 'This updates when the first purchase checks in. Place a test order to see it now.', $platforms['facebook']['note']['text'] );
        $this->assertSame( 0, $data['platforms_working'] );
        $this->assertSame( array( 'doctor' ), array_column( $data['next_steps'], 'id' ) );
    }

    public function test_paused_and_not_set_up_platforms() {
        $results = $this->rest(
            array_merge(
                self::settings(),
                array(
                    array( 'call', 'wcct_test_log_clear' ),
                    array( 'request', 'POST', '/wcct/v1/settings', array( 'platforms' => array( 'twitter' => array( 'pixel_id' => '', 'purchase_event_id' => '' ) ) ), 'admin' ),
                    array( 'request', 'POST', '/wcct/v1/settings/pause', array( 'platform' => 'facebook', 'paused' => true ), 'admin' ),
                    self::get(),
                    array( 'option', 'wcct_connected_at' ),
                )
            )
        );
        $count     = count( $results );
        $platforms = self::by_id( $results[ $count - 2 ]['data']['platforms'] );

        $this->assertSame( 'paused', $platforms['facebook']['state'] );
        $this->assertSame( 'Tracking is paused. Nothing is sent until you resume it.', $platforms['facebook']['note']['text'] );
        $this->assertSame( array( 'not_set_up', false, null ), array( $platforms['twitter']['state'], $platforms['twitter']['connected'], $platforms['twitter']['note'] ) );
        $this->assertSame( 3, $results[ $count - 2 ]['data']['platforms_connected'] );
        // Disconnected platforms are forgotten, so connecting again shows "Connected … ago".
        $this->assertArrayNotHasKey( 'twitter', $results[ $count - 1 ] );
    }

    public function test_a_platform_connected_through_the_settings_route_gets_its_time() {
        $results = $this->rest(
            array_merge(
                self::settings(),
                array(
                    array( 'request', 'POST', '/wcct/v1/settings', array( 'platforms' => array( 'twitter' => array( 'pixel_id' => '', 'purchase_event_id' => '' ) ) ), 'admin' ),
                    array( 'request', 'POST', '/wcct/v1/settings', array( 'platforms' => array( 'twitter' => array( 'pixel_id' => 'ct0x2' ) ) ), 'admin' ),
                    array( 'option', 'wcct_connected_at' ),
                )
            )
        );
        $times = end( $results );

        $this->assertSame( array( 'facebook', 'google', 'custom', 'twitter' ), array_keys( $times ) );
        $this->assertSame( 0, $times['facebook'], 'Connected before we kept times' );
        $this->assertGreaterThan( time() - 60, $times['twitter'] );
    }

    public function test_the_not_confirmed_switch_hides_the_card_and_the_badge_but_not_the_status() {
        $steps   = array_merge(
            self::settings(),
            array(
                array( 'call', 'wcct_test_log_clear' ),
                self::order( array( 'rows' => self::rows( array( 'adwords', 'ga4', 'twitter', 'custom' ) ) ) ),
                array( 'request', 'POST', '/wcct/v1/settings', array( 'alert_not_confirmed' => false ), 'admin' ),
                self::get(),
                array( 'call', 'wcct_test_badge_count' ),
                array( 'call', 'wcct_test_menu_title' ),
                array( 'request', 'GET', '/wcct/v1/log', array( 'tab' => 'not-confirmed' ), 'admin' ),
                array( 'request', 'POST', '/wcct/v1/settings', array( 'alert_not_confirmed' => true ), 'admin' ),
                array( 'call', 'wcct_test_badge_count' ),
                array( 'call', 'wcct_test_menu_title' ),
            )
        );
        $results = $this->rest( $steps );
        $offset  = count( self::settings() ) + 2;

        $this->assertSame( 200, $results[ $offset ]['status'] );
        $this->assertFalse( $results[ $offset ]['data']['alert_not_confirmed'] );

        $data = $results[ $offset + 1 ]['data'];

        $this->assertSame( array( 'not_confirmed' => false, 'doctor_new' => true ), $data['alerts'] );
        $this->assertSame( array(), $data['not_confirmed_orders'], 'No card' );
        $this->assertSame( 1, $data['not_confirmed'], 'Still counted' );
        $this->assertSame( 0, $results[ $offset + 2 ], 'No badge' );
        $this->assertSame( 'Conversion Tracking', $results[ $offset + 3 ] );
        $this->assertSame( array( 'not-confirmed' => 1 ), array_intersect_key( $results[ $offset + 4 ]['data']['counts'], array( 'not-confirmed' => 0 ) ), 'The log still shows the status' );

        $this->assertSame( 1, $results[ $offset + 6 ] );
        $this->assertStringContainsString( 'Conversion Tracking&nbsp;<span class="awaiting-mod count-1 wcct-alert-badge"><span class="pending-count" aria-hidden="true">1</span><span class="screen-reader-text">1 purchase not confirmed</span>', $results[ $offset + 7 ] );
    }

    public function test_the_doctor_switch_hides_something_new() {
        $results = $this->rest(
            array_merge(
                self::settings(),
                array(
                    array( 'set_option', 'wcct_doctor_new', array( '0123456789abcdef0123456789abcdef' ) ),
                    array( 'request', 'POST', '/wcct/v1/settings', array( 'alert_doctor_new' => false ), 'admin' ),
                    self::get(),
                    array( 'call', 'apply_filters', array( 'wcct_doctor_recheck_on_activation', true ) ),
                )
            )
        );
        $count = count( $results );

        $this->assertSame( 0, $results[ $count - 2 ]['data']['doctor']['new'] );
        $this->assertFalse( $results[ $count - 1 ], 'The quiet re-check is off' );
    }

    public function test_the_badge_shows_99_plus_and_skips_a_count_cut_short() {
        $results = $this->rest(
            array_merge(
                self::settings(),
                array(
                    array( 'call', array( 'StorePulse\ConversionTracking\Alerts\Badge', 'bubble' ), array( 150 ) ),
                    array( 'call', array( 'StorePulse\ConversionTracking\Alerts\Badge', 'remember' ), array( 3, true ) ),
                    array( 'option', '_transient_wcct_alert_badge' ),
                    array( 'call', array( 'StorePulse\ConversionTracking\Alerts\Badge', 'remember' ), array( 3, false ) ),
                    array( 'option', '_transient_wcct_alert_badge' ),
                )
            )
        );
        $offset  = count( self::settings() );

        $this->assertStringContainsString( '<span class="pending-count" aria-hidden="true">99+</span><span class="screen-reader-text">150 purchases not confirmed</span>', $results[ $offset ] );
        $this->assertSame( array( false, null ), array( $results[ $offset + 1 ], $results[ $offset + 2 ] ) );
        $this->assertSame( array( true, array( 'count' => 3 ) ), array( $results[ $offset + 3 ], $results[ $offset + 4 ] ) );
    }
}
