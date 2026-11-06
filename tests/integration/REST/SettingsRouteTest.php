<?php
// phpcs:disable

use PHPUnit\Framework\TestCase;

/**
 * The wcct/v1 settings routes in a real store
 */
class SettingsRouteTest extends TestCase {

    const V1 = array(
        'facebook' => array( 'enabled' => true, 0 => array( 'pixel_id' => '111111111111111', 'events' => array( 'Purchase' => 'on' ) ) ),
        'custom'   => array( 'enabled' => true, 'checkout' => '<script>old()</script>', 'registration' => '' ),
    );

    public function test_only_users_who_can_manage_conversion_tracking_get_in() {
        $paths    = array( array( 'GET', '/wcct/v1/settings' ), array( 'GET', '/wcct/v1/settings/schema' ), array( 'POST', '/wcct/v1/settings' ) );
        $expected = array(
            'admin'        => 200,
            'shop_manager' => 403,
            'subscriber'   => 403,
            'customer'     => 403,
            'guest'        => 401,
        );
        $requests = array();

        foreach ( array_keys( $expected ) as $as ) {
            foreach ( $paths as $path ) {
                $requests[] = array( 'as' => $as, 'method' => $path[0], 'path' => $path[1], 'body' => 'POST' === $path[0] ? array( 'consent_mode' => true ) : null );
            }
        }

        $result = $this->run_job( $requests );
        $index  = 0;

        foreach ( $expected as $as => $status ) {
            foreach ( $paths as $path ) {
                $this->assertSame( $status, $result['responses'][ $index++ ]['status'], "$as {$path[0]} {$path[1]}" );
            }
        }
    }

    public function test_a_granted_shop_manager_gets_in_and_a_subscriber_still_does_not() {
        $result = $this->run_job(
            array(
                array( 'as' => 'shop_manager', 'method' => 'GET', 'path' => '/wcct/v1/settings' ),
                array( 'as' => 'shop_manager', 'method' => 'POST', 'path' => '/wcct/v1/settings', 'body' => array( 'platforms' => array( 'facebook' => array( 'pixel_id' => '222222222222222' ) ) ) ),
                array( 'as' => 'subscriber', 'method' => 'GET', 'path' => '/wcct/v1/settings' ),
            ),
            array(),
            'shop_manager'
        );

        $this->assertSame( 200, $result['responses'][0]['status'] );
        $this->assertSame( 200, $result['responses'][1]['status'] );
        $this->assertSame( '222222222222222', $result['responses'][1]['data']['platforms']['facebook']['pixel_id'] );
        $this->assertSame( 403, $result['responses'][2]['status'] );
    }

    public function test_custom_scripts_need_unfiltered_html() {
        $result = $this->run_job(
            array(
                array( 'as' => 'shop_manager', 'method' => 'POST', 'path' => '/wcct/v1/settings', 'body' => array( 'platforms' => array( 'custom' => array( 'thankyou_script' => '<script>evil()</script>' ) ) ) ),
                array( 'as' => 'admin', 'method' => 'POST', 'path' => '/wcct/v1/settings', 'body' => array( 'platforms' => array( 'custom' => array( 'thankyou_script' => '<script>admin()</script>' ) ) ) ),
            ),
            array( 'wcct_settings' => self::V1 ),
            'shop_manager'
        );

        $this->assertSame( 403, $result['responses'][0]['status'] );
        $this->assertSame( 'wcct_rest_forbidden_fields', $result['responses'][0]['data']['code'] );
        $this->assertSame( array( 'platforms.custom.thankyou_script' ), $result['responses'][0]['data']['data']['fields'] );
        $this->assertSame( 200, $result['responses'][1]['status'] );
        $this->assertSame( '<script>admin()</script>', $result['responses'][1]['data']['platforms']['custom']['thankyou_script'] );
        $this->assertStringNotContainsString( 'evil', (string) $result['after']['v3'] );
    }

    public function test_customer_details_start_off_unless_the_2_x_checkout_script_uses_a_customer_placeholder() {
        $read = function ( array $options ) {
            $result = $this->run_job( array( array( 'as' => 'admin', 'method' => 'GET', 'path' => '/wcct/v1/settings' ) ), $options );

            return $result['responses'][0]['data']['platforms']['custom']['customer_details'];
        };
        $v1   = function ( $checkout ) {
            return array( 'wcct_settings' => array( 'custom' => array( 'enabled' => true, 'checkout' => $checkout, 'registration' => '{customer_email}' ) ) );
        };

        $this->assertFalse( $read( array() ), 'new install' );
        $this->assertTrue( $read( $v1( '<script>t("{customer_email}", "{order_total}")</script>' ) ), 'upgrader with a customer placeholder' );
        $this->assertFalse( $read( $v1( '<script>t("{order_total}")</script>' ) ), 'upgrader without one' );
    }

    public function test_without_unfiltered_html_the_custom_script_is_read_only_and_every_change_is_refused() {
        $custom = array( 'thankyou_enabled' => true, 'thankyou_script' => '<script>a()</script>', 'customer_details' => false );
        $result = $this->run_job(
            array(
                array( 'as' => 'shop_manager', 'method' => 'GET', 'path' => '/wcct/v1/settings/schema' ),
                array( 'as' => 'shop_manager', 'method' => 'GET', 'path' => '/wcct/v1/settings' ),
                array( 'as' => 'shop_manager', 'method' => 'POST', 'path' => '/wcct/v1/settings', 'body' => array( 'platforms' => array( 'custom' => array( 'customer_details' => true, 'header_enabled' => true ) ) ) ),
                // Posting the stored values back changes nothing, so it passes.
                array( 'as' => 'shop_manager', 'method' => 'POST', 'path' => '/wcct/v1/settings', 'body' => array( 'platforms' => array( 'custom' => $custom ) ) ),
                array( 'as' => 'admin', 'method' => 'GET', 'path' => '/wcct/v1/settings/schema' ),
            ),
            array( 'wcct_settings_v3' => array( 'platforms' => array( 'custom' => $custom ) ) ),
            'shop_manager'
        );

        $locked = function ( array $schema ) {
            $platforms = array_column( $schema['platforms'], 'schema', 'id' );
            $fields    = array();

            foreach ( $platforms['custom'] as $element ) {
                if ( 'field' === $element['type'] ) {
                    $fields[ $element['id'] ] = ! empty( $element['readonly'] ) && ! empty( $element['disabled'] );
                }
            }

            return $fields;
        };

        $this->assertSame( array_fill_keys( array( 'thankyou_enabled', 'thankyou_script', 'header_enabled', 'header_script', 'signup_enabled', 'signup_script', 'customer_details' ), true ), $locked( $result['responses'][0]['data'] ) );
        $this->assertNotContains( true, $locked( $result['responses'][4]['data'] ), 'administrators can edit' );
        $this->assertSame( '<script>a()</script>', $result['responses'][1]['data']['platforms']['custom']['thankyou_script'], 'scripts stay readable' );
        $this->assertSame( 403, $result['responses'][2]['status'] );
        $this->assertSame( array( 'platforms.custom.header_enabled', 'platforms.custom.customer_details' ), $result['responses'][2]['data']['data']['fields'] );
        $this->assertSame( 200, $result['responses'][3]['status'] );
        $this->assertSame( serialize( array( 'platforms' => array( 'custom' => $custom ) ) ), $result['after']['v3'] );
    }

    public function test_a_save_writes_wcct_settings_v3_and_leaves_wcct_settings_untouched() {
        $result = $this->run_job(
            array(
                array( 'as' => 'admin', 'method' => 'GET', 'path' => '/wcct/v1/settings' ),
                array( 'as' => 'admin', 'method' => 'POST', 'path' => '/wcct/v1/settings', 'body' => array( 'consent_mode' => true, 'platforms' => array( 'facebook' => array( 'pixel_id' => '333333333333333', 'events' => array( 'purchase', 'search' ) ) ) ) ),
                array( 'as' => 'admin', 'method' => 'GET', 'path' => '/wcct/v1/settings' ),
            ),
            array( 'wcct_settings' => self::V1 )
        );

        // Read from the 2.x option until the first save.
        $this->assertSame( '111111111111111', $result['responses'][0]['data']['platforms']['facebook']['pixel_id'] );
        $this->assertSame( 200, $result['responses'][1]['status'] );
        $this->assertSame( '333333333333333', $result['responses'][2]['data']['platforms']['facebook']['pixel_id'] );
        $this->assertSame( array( 'purchase', 'search' ), $result['responses'][2]['data']['platforms']['facebook']['events'] );
        $this->assertTrue( $result['responses'][2]['data']['consent_mode'] );
        $this->assertSame( '<script>old()</script>', $result['responses'][2]['data']['platforms']['custom']['thankyou_script'] );

        $this->assertSame( serialize( self::V1 ), $result['before']['v1'] );
        $this->assertSame( $result['before']['v1'], $result['after']['v1'] );
        $this->assertNull( $result['before']['v3'] );
        $this->assertNotNull( $result['after']['v3'] );
    }

    public function test_a_save_waits_while_the_migration_is_due() {
        $result = $this->run_job(
            array( array( 'as' => 'admin', 'method' => 'POST', 'path' => '/wcct/v1/settings', 'body' => array( 'consent_mode' => true ) ) ),
            // Another request holds the migration lock.
            array( 'wcct_settings' => self::V1, 'wcct_migration_lock' => time() . '|test' )
        );

        $this->assertSame( 409, $result['responses'][0]['status'] );
        $this->assertSame( 'wcct_rest_migration_pending', $result['responses'][0]['data']['code'] );
        $this->assertNull( $result['after']['v3'] );
    }

    public function test_invalid_ids_are_rejected_and_pasted_ids_are_normalised() {
        $result = $this->run_job(
            array(
                array( 'as' => 'admin', 'method' => 'POST', 'path' => '/wcct/v1/settings', 'body' => array( 'platforms' => array( 'google' => array( 'ads_id' => 'AW-123/label' ) ) ) ),
                array( 'as' => 'admin', 'method' => 'POST', 'path' => '/wcct/v1/settings', 'body' => array( 'platforms' => array( 'google' => array( 'measurement_id' => ' g-abc123 ' ), 'twitter' => array( 'purchase_event_id' => 'TW-O8X2K-O8X3A' ) ) ) ),
            )
        );

        $this->assertSame( 400, $result['responses'][0]['status'] );
        $this->assertSame( array( 'platforms.google.ads_id' ), array_keys( $result['responses'][0]['data']['data']['errors'] ) );
        $this->assertSame( 200, $result['responses'][1]['status'] );
        $this->assertSame( 'G-ABC123', $result['responses'][1]['data']['platforms']['google']['measurement_id'] );
        $this->assertSame( 'tw-o8x2k-o8x3a', $result['responses'][1]['data']['platforms']['twitter']['purchase_event_id'] );
    }

    private function run_job( array $requests, array $options = array(), string $grant = '' ): array {
        $result = WCCT_Test_WP::run(
            array(
                'type'     => 'file',
                'file'     => dirname( __DIR__ ) . '/jobs/rest-settings.php',
                'rollback' => true,
                'options'  => $options,
                'args'     => array(
                    'requests' => $requests,
                    'grant'    => $grant,
                ),
            )
        );

        $this->assertNull( $result['error'] );

        return $result['value'];
    }
}
