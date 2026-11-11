<?php

require_once dirname( __DIR__ ) . '/Fakes/settings-functions.php';

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Settings\Schema;
use StorePulse\ConversionTracking\Settings\Values;

/**
 * Tests for reading and applying the settings values
 */
class ValuesTest extends TestCase {

    public function test_get_returns_defaults_and_an_empty_credentials_block() {
        $values = Values::get( Schema::get(), array() );

        $this->assertFalse( $values['consent_mode'] );
        $this->assertSame( array( 'facebook', 'google', 'twitter', 'custom' ), array_keys( $values['platforms'] ) );
        $this->assertSame( '', $values['platforms']['facebook']['pixel_id'] );
        $this->assertSame( 'both', $values['platforms']['google']['mode'] );
        // X Add to cart and Start checkout are Pro, so off by default on Free.
        $this->assertSame( array( 'page_view', 'purchase' ), $values['platforms']['twitter']['events'] );

        foreach ( $values['platforms'] as $platform ) {
            $this->assertFalse( $platform['paused'] );
            $this->assertInstanceOf( stdClass::class, $platform['credentials'] );
            $this->assertSame( array(), (array) $platform['credentials'] );
        }
    }

    public function test_every_field_round_trips() {
        $schema = Schema::get();
        $input  = $this->sample( $schema );
        $result = Values::apply( $schema, array(), $input, array( $this, 'can' ) );

        $this->assertSame( array(), $result['errors'] );
        $this->assertSame( array(), $result['forbidden'] );

        $values = Values::get( $schema, $result['doc'] );

        foreach ( $input['platforms'] as $id => $posted ) {
            unset( $values['platforms'][ $id ]['credentials'], $values['platforms'][ $id ]['paused'], $values['platforms'][ $id ]['needs_resetup'] );
            // Locked fields aren't posted and keep their defaults.
            $this->assertSame( $posted, array_intersect_key( $values['platforms'][ $id ], $posted ), $id );
        }

        $this->assertTrue( $values['consent_mode'] );
    }

    public function test_locked_x_event_ids_keep_what_is_stored_on_free() {
        $doc    = array( 'platforms' => array( 'twitter' => array( 'cart_event_id' => 'tw-o8x2k-aaaaa' ) ) );
        $posted = array(
            'platforms' => array(
                'twitter' => array(
                    'purchase_event_id' => 'tw-o8x2k-bbbbb',
                    'cart_event_id'     => 'tw-o8x2k-ccccc',
                    'signup_event_id'   => 'tw-o8x2k-ddddd',
                ),
            ),
        );
        $result = Values::apply( Schema::get(), $doc, $posted, array( $this, 'can' ) );

        $this->assertSame( 'tw-o8x2k-bbbbb', $result['doc']['platforms']['twitter']['purchase_event_id'] );
        $this->assertSame( 'tw-o8x2k-aaaaa', $result['doc']['platforms']['twitter']['cart_event_id'] );
        $this->assertArrayNotHasKey( 'signup_event_id', $result['doc']['platforms']['twitter'] );
    }

    public function test_needs_resetup_is_read_only_and_clears_once_the_event_has_an_id() {
        $doc    = array( 'platforms' => array( 'twitter' => array( 'purchase_event_id' => 'tw-o8x2k-bbbbb', 'needs_resetup' => array( 'purchase', 'add_to_cart' ) ) ) );
        $values = Values::get( Schema::get(), $doc );

        $this->assertSame( array( 'add_to_cart' ), $values['platforms']['twitter']['needs_resetup'] );
        $this->assertSame( array(), $values['platforms']['facebook']['needs_resetup'] );

        $result = Values::apply( Schema::get(), $doc, array( 'platforms' => array( 'twitter' => array( 'needs_resetup' => array() ) ) ), array( $this, 'can' ) );

        $this->assertSame( array( 'purchase', 'add_to_cart' ), $result['doc']['platforms']['twitter']['needs_resetup'] );
    }

    public function test_only_posted_keys_change_and_unknown_stored_keys_are_kept() {
        $doc = array(
            'consent_mode' => true,
            'platforms'    => array(
                'facebook' => array( 'pixel_id' => '111111111111111', 'events' => array( 'purchase' ), 'pixels' => array( array( 'pixel_id' => '2' ) ) ),
                'bing_ads' => array( 'tag_id' => '12345678' ),
            ),
        );

        $result = Values::apply( Schema::get(), $doc, array( 'platforms' => array( 'facebook' => array( 'pixel_id' => '222222222222222' ) ) ), array( $this, 'can' ) );

        $this->assertSame( '222222222222222', $result['doc']['platforms']['facebook']['pixel_id'] );
        $this->assertSame( array( 'purchase' ), $result['doc']['platforms']['facebook']['events'] );
        $this->assertSame( array( array( 'pixel_id' => '2' ) ), $result['doc']['platforms']['facebook']['pixels'] );
        $this->assertSame( array( 'tag_id' => '12345678' ), $result['doc']['platforms']['bing_ads'] );
        $this->assertTrue( $result['doc']['consent_mode'] );
    }

    public function test_paused_is_read_only() {
        $doc    = array( 'platforms' => array( 'twitter' => array( 'pixel_id' => 'o8x2k', 'paused' => true ) ) );
        $result = Values::apply( Schema::get(), $doc, array( 'platforms' => array( 'twitter' => array( 'paused' => false, 'pixel_id' => 'ab12c' ) ) ), array( $this, 'can' ) );

        $this->assertTrue( $result['doc']['platforms']['twitter']['paused'] );
        $this->assertTrue( Values::get( Schema::get(), $result['doc'] )['platforms']['twitter']['paused'] );
    }

    public function test_unknown_platforms_and_keys_are_ignored() {
        $result = Values::apply(
            Schema::get(),
            array(),
            array(
                'unknown'   => 'x',
                'platforms' => array(
                    'bing_ads' => array( 'tag_id' => '1' ),
                    'facebook' => array( 'nope' => 'x', 'credentials' => array( 'token' => 'secret' ) ),
                ),
            ),
            array( $this, 'can' )
        );

        $this->assertSame( array( 'platforms' => array( 'facebook' => array() ) ), $result['doc'] );
    }

    public function test_invalid_values_are_reported_per_field() {
        $result = Values::apply(
            Schema::get(),
            array(),
            array(
                'consent_mode' => 'maybe',
                'platforms'    => array(
                    'google'   => array( 'mode' => 'tag-manager', 'measurement_id' => 'G-OK' ),
                    'facebook' => 'not an object',
                ),
            ),
            array( $this, 'can' )
        );

        $this->assertSame( array( 'consent_mode', 'platforms.google.mode', 'platforms.facebook' ), array_keys( $result['errors'] ) );
    }

    public function test_platforms_must_be_an_object() {
        $result = Values::apply( Schema::get(), array(), array( 'platforms' => 'x' ), array( $this, 'can' ) );

        $this->assertArrayHasKey( 'platforms', $result['errors'] );
    }

    public function test_custom_script_changes_need_unfiltered_html() {
        $doc    = array( 'platforms' => array( 'custom' => array( 'thankyou_script' => "<script>\na();\n</script>", 'thankyou_enabled' => true ) ) );
        $cannot = function ( $cap ) {
            return 'unfiltered_html' !== $cap;
        };

        $changed = Values::apply( Schema::get(), $doc, array( 'platforms' => array( 'custom' => array( 'thankyou_script' => '<script>b();</script>' ) ) ), $cannot );
        $this->assertSame( array( 'platforms.custom.thankyou_script' ), $changed['forbidden'] );

        $toggled = Values::apply( Schema::get(), $doc, array( 'platforms' => array( 'custom' => array( 'thankyou_enabled' => false ) ) ), $cannot );
        $this->assertSame( array( 'platforms.custom.thankyou_enabled' ), $toggled['forbidden'] );

        // The form posts back what it loaded, with browser line breaks.
        $same = Values::apply(
            Schema::get(),
            $doc,
            array(
                'platforms' => array(
                    'custom'   => array( 'thankyou_script' => "<script>\r\na();\r\n</script>", 'thankyou_enabled' => true, 'header_script' => '' ),
                    'facebook' => array( 'pixel_id' => '123456789012345' ),
                ),
            ),
            $cannot
        );
        $this->assertSame( array(), $same['forbidden'] );
        $this->assertSame( "<script>\na();\n</script>", $same['doc']['platforms']['custom']['thankyou_script'] );
        $this->assertSame( '123456789012345', $same['doc']['platforms']['facebook']['pixel_id'] );

        $allowed = Values::apply( Schema::get(), $doc, array( 'platforms' => array( 'custom' => array( 'thankyou_script' => '<script>b();</script>' ) ) ), array( $this, 'can' ) );
        $this->assertSame( '<script>b();</script>', $allowed['doc']['platforms']['custom']['thankyou_script'] );
    }

    public function can() {
        return true;
    }

    /**
     * A non-default value for every field.
     */
    private function sample( array $schema ): array {
        $input = array( 'consent_mode' => true, 'platforms' => array() );

        foreach ( $schema['platforms'] as $id => $platform ) {
            foreach ( Schema::fields( $platform['schema'] ) as $key => $field ) {
                if ( ! empty( $field['disabled'] ) ) {
                    continue;
                }

                $input['platforms'][ $id ][ $key ] = $this->value_for( $field );
            }
        }

        return $input;
    }

    private function value_for( array $field ) {
        switch ( $field['variant'] ) {
            case 'switch':
                return ! $field['default'];
            case 'text':
                $samples = array(
                    Schema::id_pattern( '[0-9]+' )                  => '123456789012345',
                    Schema::id_pattern( 'GT?-[A-Z0-9]+' )           => 'G-ABC123',
                    Schema::id_pattern( 'AW-[0-9]+' )               => 'AW-123456789',
                    Schema::id_pattern( '[A-Za-z0-9_-]+' )          => 'AbC_label-1',
                    Schema::id_pattern( 'GT-[A-Z0-9]+' )            => 'GT-ABC123',
                    Schema::id_pattern( '[a-z0-9]+' )               => 'o8x2k',
                    Schema::id_pattern( 'tw-[a-z0-9]+-[a-z0-9]+' )  => 'tw-o8x2k-' . substr( md5( $field['id'] ), 0, 5 ),
                );

                if ( isset( $samples[ $field['pattern'] ] ) ) {
                    return $samples[ $field['pattern'] ];
                }
                break;
            case 'textarea':
                return "<script>console.log('{order_number}', \"{$field['id']}\");</script>";
            case 'radio_capsule':
            case 'select':
                foreach ( $field['options'] as $option ) {
                    if ( $option['value'] !== $field['default'] ) {
                        return $option['value'];
                    }
                }
                break;
            case 'multicheck':
                return array_values( array_column( array_filter( $field['options'], function ( $option ) {
                    return ! $option['locked'] && ! $option['recommended'] || $option['always'];
                } ), 'value' ) );
        }

        $this->fail( 'No sample for ' . $field['id'] );
    }
}
