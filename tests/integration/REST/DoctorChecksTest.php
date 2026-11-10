<?php
// phpcs:disable

/**
 * Lite's seven Doctor checks through POST wcct/v1/doctor, each with a store state that triggers it and one that doesn't (jobs/doctor-checks.php)
 */
class DoctorChecksTest extends WCCT_Test_Rest_Case {

    const FREE_CHECKS = array( 'tracking-plugins', 'id-formats', 'consent-plugin', 'tag-manager', 'purchase-on', 'purchases-24h', 'consent-off' );

    private static function settings( array $platforms = array() ) {
        return array(
            'consent_mode' => false,
            'platforms'    => array_replace(
                array(
                    'facebook' => array( 'pixel_id' => '123456789012345' ),
                    'google'   => array( 'mode' => 'both', 'measurement_id' => 'G-ABC123', 'ads_id' => 'AW-123456', 'purchase_label' => 'abcDEF' ),
                    'twitter'  => array( 'pixel_id' => 'o8x2k', 'purchase_event_id' => 'tw-o8x2k-abc12' ),
                ),
                $platforms
            ),
        );
    }

    private static function run_doctor() {
        return array( 'request', 'POST', '/wcct/v1/doctor', array(), 'admin' );
    }

    /**
     * Findings of one check: found => the finding.
     */
    private static function of( array $result, $check ) {
        $found = array();

        foreach ( $result['data']['findings'] as $finding ) {
            if ( $check === $finding['check'] ) {
                $found[ $finding['found'] ] = $finding;
            }
        }

        return $found;
    }

    private static function working( array $result ) {
        return array_column( $result['data']['working'], 'label', 'check' );
    }

    private static function lite_findings( array $result ) {
        return array_values( array_filter( array_column( $result['data']['findings'], 'check' ), function ( $check ) { return 'feed-ids' !== $check; } ) );
    }

    public function test_a_clean_store_passes_every_check_and_counts_seven() {
        $results = $this->rest(
            array(
                array( 'set_option', 'wcct_settings_v3', self::settings() ),
                array( 'set_option', 'wcct_consent_mode', 'off' ),
                // Orders 3001 and 3002 of the test store are recent and paid, with no check-in.
                self::run_doctor(),
                array( 'global', 'wcct_test_doctor_requests' ),
            ),
            'doctor-checks'
        );

        $report = $results[2]['data'];

        $this->assertSame( self::pro_loaded() ? 8 : 7, $report['total'] );
        $this->assertSame( $report['total'], $report['checked'] );
        $this->assertSame( array( 'consent-off' ), self::lite_findings( $results[2] ), 'Only the consent note' );

        $working = self::working( $results[2] );

        $this->assertSame( 'No other tracking plugin sends to your platforms', $working['tracking-plugins'] );
        $this->assertSame( 'Your platform IDs look valid', $working['id-formats'] );
        $this->assertSame( 'Respect cookie choices is off, so no tag waits for a cookie choice', $working['consent-plugin'] );
        $this->assertSame( 'No Google Tag Manager plugin is active', $working['tag-manager'] );
        $this->assertSame( 'Purchase is on for every connected platform', $working['purchase-on'] );
        $this->assertSame( 'No purchase in the last 24 hours has checked in from the thank-you page yet', $working['purchases-24h'] );

        // The check-in probe: one cookie-less POST, answered by the real route as designed.
        $this->assertCount( 1, $results[3] );
        $this->assertStringEndsWith( '/wcct/v1/events', $results[3][0]['url'] );
        $this->assertSame( array( 'POST', array(), '{"type":"seen","token":"x"}' ), array( $results[3][0]['method'], $results[3][0]['cookies'], $results[3][0]['body'] ) );
    }

    public function test_other_tracking_plugins_and_a_tag_manager_plugin() {
        $results = $this->rest(
            array(
                array( 'set_option', 'wcct_settings_v3', self::settings() ),
                array( 'set_option', 'active_plugins', array( 'facebook-for-woocommerce/facebook-for-woocommerce.php', 'pixelyoursite/pixelyoursite.php', 'duracelltomi-google-tag-manager/duracelltomi-google-tag-manager-for-wordpress.php' ) ),
                array( 'set_option', 'gtm4wp-options', array( 'gtm-code' => 'GTM-ABC123' ) ),
                array( 'set_option', 'wc_facebook_pixel_id', '999999999999999' ),
                self::run_doctor(),
                // Meta for WooCommerce without its pixel, no GTM: only PixelYourSite is left.
                array( 'set_option', 'wc_facebook_pixel_id', null ),
                array( 'set_option', 'active_plugins', array( 'facebook-for-woocommerce/facebook-for-woocommerce.php', 'pixelyoursite/pixelyoursite.php' ) ),
                self::run_doctor(),
                // Meta paused: nothing overlaps on Meta.
                array( 'set_option', 'wcct_paused_platforms', array( 'facebook' ) ),
                self::run_doctor(),
            ),
            'doctor-checks'
        );

        $plugins = self::of( $results[4], 'tracking-plugins' );

        $this->assertSame(
            array(
                'Meta for WooCommerce (formerly Facebook for WooCommerce) also sends to Meta',
                'PixelYourSite is also active and may send to Meta',
                'PixelYourSite is also active and may send to Google',
            ),
            array_keys( $plugins )
        );
        $this->assertSame( array( 'sure', 'Duplicate', array( 'Meta', 'Double counting' ) ), array( $plugins['Meta for WooCommerce (formerly Facebook for WooCommerce) also sends to Meta']['confidence'], $plugins['Meta for WooCommerce (formerly Facebook for WooCommerce) also sends to Meta']['label'], $plugins['Meta for WooCommerce (formerly Facebook for WooCommerce) also sends to Meta']['tags'] ) );
        $this->assertSame( 'recheck', $plugins['PixelYourSite is also active and may send to Google']['action']['type'] );

        $gtm = self::of( $results[4], 'tag-manager' );

        $this->assertSame( array( 'A Google Tag Manager plugin is active' ), array_keys( $gtm ) );
        $this->assertSame( array( 'unsure', array( 'Google', 'Can\'t see inside', 'GTM4WP' ) ), array( $gtm['A Google Tag Manager plugin is active']['confidence'], $gtm['A Google Tag Manager plugin is active']['tags'] ) );

        $this->assertSame( array( 'PixelYourSite is also active and may send to Meta', 'PixelYourSite is also active and may send to Google' ), array_keys( self::of( $results[7], 'tracking-plugins' ) ) );
        $this->assertSame( array(), self::of( $results[7], 'tag-manager' ) );
        $this->assertSame( array( 'PixelYourSite is also active and may send to Google' ), array_keys( self::of( $results[9], 'tracking-plugins' ) ) );
    }

    public function test_id_formats_and_purchase_on() {
        $results = $this->rest(
            array(
                array( 'set_option', 'wcct_settings_v3', self::settings( array( 'facebook' => array( 'pixel_id' => 'EAAB123', 'events' => array( 'view_product' ) ), 'google' => array( 'mode' => 'both', 'measurement_id' => 'UA-1234-1', 'ads_id' => 'AW-123456', 'purchase_label' => '' ) ) ) ),
                self::run_doctor(),
            ),
            'doctor-checks'
        );

        $ids = self::of( $results[1], 'id-formats' );

        $this->assertSame( array( 'Your Meta Pixel ID doesn\'t look right', 'Your Google Analytics Measurement ID doesn\'t look right', 'Your Google Ads conversion label looks incomplete' ), array_keys( $ids ) );
        $this->assertSame( '#/platforms/facebook?field=pixel_id', $ids['Your Meta Pixel ID doesn\'t look right']['action']['url'] );
        $this->assertSame( 'Meta ignores events sent with an ID it doesn\'t know, so they aren\'t counted. Expected: 15 or 16 digits, numbers only.', $ids['Your Meta Pixel ID doesn\'t look right']['why'] );

        $purchase = self::of( $results[1], 'purchase-on' );

        $this->assertSame( array( 'Purchase is off for Meta' ), array_keys( $purchase ) );
        $this->assertSame( array( 'Open Meta', '#/platforms/facebook?field=events' ), array( $purchase['Purchase is off for Meta']['action']['label'], $purchase['Purchase is off for Meta']['action']['url'] ) );
    }

    public function test_cookie_choices_without_a_consent_plugin() {
        $results = $this->rest(
            array(
                array( 'set_option', 'wcct_settings_v3', self::settings() ),
                array( 'set_option', 'wcct_consent_mode', 'on' ),
                self::run_doctor(),
            ),
            'doctor-checks'
        );

        $consent = self::of( $results[2], 'consent-plugin' );

        $this->assertSame( array( 'Cookie choices are on, but no consent plugin is connected' ), array_keys( $consent ) );
        $this->assertSame( array( 'See compatible plugins', 'https://wordpress.org/plugins/wp-consent-api/', true ), array( $consent['Cookie choices are on, but no consent plugin is connected']['action']['label'], $consent['Cookie choices are on, but no consent plugin is connected']['action']['url'], $consent['Cookie choices are on, but no consent plugin is connected']['action']['external'] ) );
        $this->assertSame( array(), self::of( $results[2], 'consent-off' ), 'The switch is on' );
    }

    public function test_purchases_of_the_last_24_hours() {
        // Orders 0 (sent), 1 (not confirmed) and 2 (created in admin, paid on the pay page); placed after 3.0 first ran, as the Activity log counts.
        $results = $this->rest(
            array(
                array( 'set_option', 'wcct_count_once_since', time() - 7200 ),
                array( 'set_option', 'wcct_settings_v3', self::settings() ),
                array( 'call', 'wcct_test_doctor_order', array( 3600 ) ),
                array( 'call', 'wcct_test_doctor_order', array( 3600 ) ),
                array( 'call', 'wcct_test_doctor_order', array( 3600, 'admin' ) ),
                array( 'call', 'wcct_test_doctor_checkin', array( 0, 'facebook', array( 'loaded' => true, 'load' => 'pageA1' ) ) ),
                // The REST answer was lost and the page retried on admin-ajax: same page load, not a repeat.
                array( 'call', 'wcct_test_doctor_checkin', array( 0, 'facebook', array( 'loaded' => true, 'load' => 'pageA1' ) ) ),
                self::run_doctor(),
                array( 'call', 'wcct_test_doctor_checkin', array( 1, 'facebook', array( 'loaded' => false, 'reason' => 'timeout', 'load' => 'pageC3' ) ) ),
                array( 'call', 'wcct_test_doctor_checkin', array( 2, 'facebook', array( 'loaded' => false, 'reason' => 'timeout', 'load' => 'pageD4' ) ) ),
                // Another page load handed the same order over again: one repeat.
                array( 'call', 'wcct_test_doctor_checkin', array( 0, 'facebook', array( 'loaded' => true, 'load' => 'pageB2' ) ) ),
                array( 'call', 'wcct_test_doctor_checkin', array( 0, 'facebook', array( 'loaded' => true, 'load' => 'pageB2' ) ) ),
                // Backdate the not-confirmed check-ins past the 10 minutes.
                array( 'call', 'wcct_test_query', array( "UPDATE {log} SET thankyou_seen_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 20 MINUTE), created_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 20 MINUTE) WHERE library_loaded = 0" ) ),
                self::run_doctor(),
                array( 'option', 'wcct_order_log_repeats' ),
            ),
            'doctor-checks'
        );

        $sent = $results[2];

        $this->assertSame( array(), self::of( $results[7], 'purchases-24h' ), 'The admin-ajax retry is not a repeat' );
        $this->assertSame( 'Purchases in the last 24 hours were sent, and none of them twice by this plugin', self::working( $results[7] )['purchases-24h'] );

        $found = self::of( $results[13], 'purchases-24h' );

        // The admin order checked in from its thank-you page (paid on the pay page), so it counts, as in the Activity log.
        $this->assertSame( array( 'We couldn\'t confirm 2 purchases from the last 24 hours', '1 purchase was sent twice in the last 24 hours' ), array_keys( $found ) );
        $this->assertSame( array( array( 'Meta' ), '#/log' ), array( $found['We couldn\'t confirm 2 purchases from the last 24 hours']['tags'], $found['We couldn\'t confirm 2 purchases from the last 24 hours']['action']['url'] ) );
        $this->assertCount( 1, $results[14] );
        $this->assertSame( array( $sent, 'facebook' ), array( $results[14][0]['order_id'], $results[14][0]['platform'] ) );
    }

    public function test_no_purchases_reads_no_purchases_in_the_last_24_hours() {
        $results = $this->rest(
            array(
                array( 'set_option', 'wcct_settings_v3', self::settings() ),
                array( 'call', 'wcct_test_order_status', array( 3001, 'cancelled' ) ),
                array( 'call', 'wcct_test_order_status', array( 3002, 'failed' ) ),
                self::run_doctor(),
            ),
            'doctor-checks'
        );

        $this->assertSame( 'No purchases in the last 24 hours', self::working( $results[3] )['purchases-24h'] );
    }

    public function test_a_rest_api_closed_to_visitors_is_a_good_to_know_note() {
        $results = $this->rest(
            array(
                array( 'set_option', 'wcct_settings_v3', self::settings() ),
                array( 'set_option', 'wcct_test_block_rest', 1 ),
                self::run_doctor(),
                array( 'set_option', 'wcct_test_block_rest', null ),
                array( 'request', 'POST', '/wcct/v1/doctor', array( 'checks' => array( 'purchases-24h' ) ), 'admin' ),
            ),
            'doctor-checks'
        );

        $route = self::of( $results[2], 'purchases-24h' );

        $this->assertSame( array( 'Purchase check-ins use the backup route' ), array_keys( $route ) );
        $this->assertSame( array( 'info', 'unsure', 'Check-ins' ), array( $route['Purchase check-ins use the backup route']['severity'], $route['Purchase check-ins use the backup route']['confidence'], $route['Purchase check-ins use the backup route']['label'] ) );
        $this->assertSame( array(), self::of( $results[4], 'purchases-24h' ), 'Check again after allowing the route' );
    }
}
