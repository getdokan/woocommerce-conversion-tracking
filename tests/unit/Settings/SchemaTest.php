<?php

require_once dirname( __DIR__ ) . '/Fakes/settings-functions.php';

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Settings\Schema;

/**
 * Tests for the settings schema
 */
class SchemaTest extends TestCase {

    protected function tearDown(): void {
        unset( $GLOBALS['wcct_test_filters'] );
    }

    public function test_lite_lists_its_platforms_in_order() {
        $this->assertSame( array( 'facebook', 'google', 'twitter', 'custom' ), array_keys( Schema::get()['platforms'] ) );
    }

    public function test_every_platform_page_is_well_formed_for_plugin_ui() {
        foreach ( Schema::get()['platforms'] as $id => $platform ) {
            $ids      = array();
            $sections = array();

            foreach ( $platform['schema'] as $element ) {
                $this->assertNotContains( $element['id'], $ids, "Duplicate ID {$element['id']} on {$id}" );
                $ids[] = $element['id'];

                if ( 'section' === $element['type'] ) {
                    $this->assertSame( $id, $element['page_id'] );
                    $sections[] = $element['id'];
                }
            }

            $this->assertSame( $id, $platform['schema'][0]['id'] );
            $this->assertSame( 'page', $platform['schema'][0]['type'] );

            foreach ( Schema::fields( $platform['schema'] ) as $field ) {
                $this->assertContains( $field['section_id'], $sections, "Orphan field {$field['id']} on {$id}" );
            }
        }
    }

    public function test_consent_is_one_global_switch_off_by_default() {
        $global = Schema::fields( Schema::get()['global'] );

        // Consent is the only global setting besides the alert switches (#117).
        $this->assertSame( array( 'consent_mode' ), array_keys( array_diff_key( $global, array_flip( array( 'alert_not_confirmed', 'alert_doctor_new' ) ) ) ) );
        $this->assertSame( 'switch', $global['consent_mode']['variant'] );
        $this->assertFalse( $global['consent_mode']['default'] );

        foreach ( Schema::get()['platforms'] as $platform ) {
            $this->assertArrayNotHasKey( 'consent_mode', Schema::fields( $platform['schema'] ) );
        }
    }

    public function test_google_is_one_platform_over_ga4_and_adwords() {
        $google = Schema::get()['platforms']['google'];

        $this->assertSame( array( 'ga4', 'adwords' ), $google['integrations'] );
        $this->assertSame( array( 'mode', 'measurement_id', 'ads_id', 'purchase_label', 'enhanced_conversions', 'events', 'tag_id', 'value_mode', 'product_id_mode', 'skip_roles' ), array_keys( Schema::fields( $google['schema'] ) ) );
        $this->assertSame( array( 'analytics', 'ads', 'both' ), array_column( Schema::fields( $google['schema'] )['mode']['options'], 'value' ) );
    }

    public function test_enhanced_conversions_is_a_locked_pro_switch_off_by_default() {
        $field = Schema::fields( Schema::get()['platforms']['google']['schema'] )['enhanced_conversions'];

        $this->assertSame( 'switch', $field['variant'] );
        $this->assertFalse( $field['default'] );
        $this->assertTrue( $field['pro'] );
        $this->assertTrue( $field['disabled'] );
        $this->assertFalse( Schema::unlock_fields( array( $field ), array( 'enhanced_conversions' ) )[0]['disabled'] );
    }

    public function test_platforms_that_send_product_ids_have_product_id_sent_defaulting_to_the_id() {
        foreach ( array( 'facebook', 'google', 'twitter' ) as $id ) {
            $field = Schema::fields( Schema::get()['platforms'][ $id ]['schema'] )['product_id_mode'];

            $this->assertSame( 'select', $field['variant'] );
            $this->assertSame( 'id', $field['default'] );
            $this->assertSame( array( 'id', 'sku' ), array_column( $field['options'], 'value' ) );
            $this->assertSame( $id . '_advanced', $field['section_id'] );
        }

        $this->assertArrayNotHasKey( 'product_id_mode', Schema::fields( Schema::get()['platforms']['custom']['schema'] ) );
    }

    public function test_output_reserves_an_empty_credentials_block_per_platform() {
        foreach ( Schema::for_output( Schema::get(), '__return_true_for_test' )['platforms'] as $platform ) {
            $this->assertSame( array(), $platform['credentials'] );
            $this->assertNotEmpty( $platform['integrations'] );
        }
    }

    public function test_output_drops_server_keys_and_locks_fields_the_user_cant_change() {
        $deny  = Schema::for_output( Schema::get(), function () {
            return false;
        } );
        $allow = Schema::for_output( Schema::get(), '__return_true_for_test' );

        $custom = $this->field( $deny, 'custom', 'thankyou_script' );
        $this->assertArrayNotHasKey( 'capability', $custom );
        $this->assertTrue( $custom['readonly'] );
        $this->assertTrue( $custom['disabled'] );

        $this->assertArrayNotHasKey( 'readonly', $this->field( $allow, 'custom', 'thankyou_script' ) );
        $this->assertArrayNotHasKey( 'readonly', $this->field( $deny, 'facebook', 'pixel_id' ) );
    }

    public function test_every_custom_field_needs_unfiltered_html() {
        foreach ( Schema::fields( Schema::get()['platforms']['custom']['schema'] ) as $field ) {
            $this->assertSame( 'unfiltered_html', $field['capability'], $field['id'] );
        }
    }

    public function test_events_default_to_recommended_and_pro_events_are_locked() {
        $events = Schema::fields( Schema::get()['platforms']['facebook']['schema'] )['events'];
        $locked = array_column( array_filter( $events['options'], function ( $option ) {
            return $option['locked'];
        } ), 'value' );

        $this->assertSame( array( 'view_product', 'add_to_cart', 'begin_checkout', 'purchase' ), $events['default'] );
        $this->assertSame( array( 'view_category', 'add_to_wishlist' ), array_values( $locked ) );
    }

    public function test_x_rows_wait_for_their_event_id_and_unlocking_turns_on_the_recommended_ones() {
        $twitter = Schema::get()['platforms']['twitter']['schema'];
        $events  = Schema::fields( $twitter )['events'];

        $this->assertSame( array( 'page_view', 'purchase' ), $events['default'], 'Free: Add to cart and Start checkout are locked' );
        $this->assertSame( array( '', 'cart_event_id', 'checkout_event_id', 'purchase_event_id', 'signup_event_id' ), array_column( $events['options'], 'needs' ) );

        $unlocked = Schema::fields( Schema::unlock_options( $twitter, 'events' ) )['events'];

        $this->assertSame( array( 'page_view', 'add_to_cart', 'begin_checkout', 'purchase' ), $unlocked['default'] );
        // Meta's Pro events aren't recommended, so its default stays.
        $this->assertSame( array( 'view_product', 'add_to_cart', 'begin_checkout', 'purchase' ), Schema::fields( Schema::unlock_options( Schema::get()['platforms']['facebook']['schema'], 'events' ) )['events']['default'] );
    }

    public function test_a_filter_adds_a_platform_and_unlocks_options() {
        $GLOBALS['wcct_test_filters']['wcct_settings_schema'] = function ( $schema ) {
            $schema['platforms']['bing_ads'] = array(
                'label'    => 'Microsoft',
                'pro'      => true,
                'priority' => 40,
                'schema'   => array(
                    array( 'id' => 'bing_ads', 'type' => 'page' ),
                    Schema::section( 'bing_ads', 'bing_ads_connection', 'Connection' ),
                    Schema::id_field( 'bing_ads_connection', 'tag_id', 'UET tag ID', '', '', '^[0-9]{7,9}$', 10 ),
                ),
            );
            $schema['platforms']['broken'] = 'not a platform';
            $schema['platforms']['facebook']['schema'] = Schema::unlock_options( $schema['platforms']['facebook']['schema'], 'events' );

            return $schema;
        };

        $schema = Schema::get();

        $this->assertSame( array( 'facebook', 'google', 'twitter', 'bing_ads', 'custom' ), array_keys( $schema['platforms'] ) );
        $this->assertSame( array( 'bing_ads' ), $schema['platforms']['bing_ads']['integrations'] );
        $this->assertSame( array(), array_filter( array_column( Schema::fields( $schema['platforms']['facebook']['schema'] )['events']['options'], 'locked' ) ) );
    }

    public function test_a_broken_filter_falls_back_to_lite() {
        $GLOBALS['wcct_test_filters']['wcct_settings_schema'] = function () {
            return null;
        };

        $this->assertSame( array( 'facebook', 'google', 'twitter', 'custom' ), array_keys( Schema::get()['platforms'] ) );
    }

    private function field( array $output, string $platform, string $id ): array {
        foreach ( $output['platforms'] as $item ) {
            if ( $platform === $item['id'] ) {
                foreach ( $item['schema'] as $element ) {
                    if ( $id === $element['id'] ) {
                        return $element;
                    }
                }
            }
        }

        $this->fail( "No field {$platform}.{$id}" );
    }

    public function test_the_setup_wizard_asks_for_the_connection_ids_only() {
        $wizard = array();

        foreach ( Schema::get()['platforms'] as $id => $platform ) {
            foreach ( Schema::fields( $platform['schema'] ) as $field ) {
                if ( isset( $field['onboarding'] ) ) {
                    $this->assertSame( 'id', $field['format'], "{$id}.{$field['id']}" );
                    $this->assertIsBool( $field['onboarding']['required'], "{$id}.{$field['id']}" );
                    $wizard[ $id ][ $field['id'] ] = $field['onboarding']['required'];
                }
            }
        }

        // The Ads label goes with the Ads ID.
        $this->assertSame( 'ads_id', Schema::fields( Schema::get()['platforms']['google']['schema'] )['purchase_label']['onboarding']['with'] );

        // X takes the Purchase event ID; the rest wait for the Platforms page.
        $this->assertSame(
            array(
                'facebook' => array( 'pixel_id' => true ),
                'google'   => array(
                    'measurement_id' => true,
                    'ads_id'         => false,
                    'purchase_label' => false,
                ),
                'twitter'  => array(
                    'pixel_id'          => true,
                    'purchase_event_id' => true,
                ),
            ),
            $wizard
        );
    }

    public function test_value_mode_on_meta_ads_and_x_defaults_by_install_type() {
        $platforms = Schema::get()['platforms'];

        foreach ( array( 'facebook', 'google', 'twitter' ) as $platform ) {
            $field = Schema::fields( $platforms[ $platform ]['schema'] )['value_mode'];

            $this->assertSame( 'select', $field['variant'], $platform );
            $this->assertSame( array( 'items', 'shipping', 'all' ), array_column( $field['options'], 'value' ), $platform );
            $this->assertSame( 'items', $field['default'], $platform );
        }

        $GLOBALS['wcct_test_options']['wcct_install_type'] = 'upgrade';

        $default = Schema::fields( Schema::get()['platforms']['facebook']['schema'] )['value_mode']['default'];

        unset( $GLOBALS['wcct_test_options'] );

        $this->assertSame( 'all', $default );
    }

    public function test_skip_roles_on_meta_google_and_x_defaults_by_install_type() {
        $platforms = Schema::get()['platforms'];

        foreach ( array( 'facebook', 'google', 'twitter' ) as $platform ) {
            $field = Schema::fields( $platforms[ $platform ]['schema'] )['skip_roles'];

            $this->assertSame( 'select', $field['variant'], $platform );
            $this->assertSame( array( 'admin-shop', 'admin', 'none' ), array_column( $field['options'], 'value' ), $platform );
            $this->assertSame( 'admin-shop', $field['default'], $platform );
            $this->assertSame( $platform . '_advanced', $field['section_id'], $platform );
        }

        $this->assertArrayNotHasKey( 'skip_roles', Schema::fields( $platforms['custom']['schema'] ) );

        $GLOBALS['wcct_test_options']['wcct_install_type'] = 'upgrade';

        $default = Schema::fields( Schema::get()['platforms']['facebook']['schema'] )['skip_roles']['default'];

        unset( $GLOBALS['wcct_test_options'] );

        $this->assertSame( 'none', $default );
    }
}

function __return_true_for_test() {
    return true;
}
