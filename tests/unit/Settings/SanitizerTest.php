<?php

require_once dirname( __DIR__ ) . '/Fakes/settings-functions.php';

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Settings\Sanitizer;
use StorePulse\ConversionTracking\Settings\Schema;

/**
 * Tests for the per-field cleaning on save
 */
class SanitizerTest extends TestCase {

    public function test_id_fields_keep_only_id_characters() {
        $field = Schema::id_field( 's', 'pixel_id', 'Pixel ID', '', '', '', 10 );

        $this->assertSame( '123alertdocument.cookie//', Sanitizer::field( $field, " 123');alert(document.cookie);// ", '' ) );
        $this->assertSame( 'AW-123/AbC-12_x', Sanitizer::field( $field, 'AW-123/AbC-12_x', '' ) );
        $this->assertSame( '1234', Sanitizer::field( $field, 1234, '' ) );
        $this->assertSame( '', Sanitizer::field( $field, null, 'old' ) );
    }

    public function test_id_fields_must_match_their_pattern_once_cleaned_and_cased() {
        $google = Schema::fields( Schema::lite()['platforms']['google']['schema'] );
        $x      = Schema::fields( Schema::lite()['platforms']['twitter']['schema'] );

        $this->assertSame( 'G-ABC123', Sanitizer::field( $google['measurement_id'], ' g-abc123 ', '' ) );
        $this->assertSame( 'AW-123456789', Sanitizer::field( $google['ads_id'], 'aw-123456789', '' ) );
        $this->assertSame( 'GT-XYZ', Sanitizer::field( $google['tag_id'], 'gt-xyz', '' ) );
        $this->assertSame( 'AbC_label-1', Sanitizer::field( $google['purchase_label'], 'AbC_label-1', '' ) );
        $this->assertSame( 'tw-o8x2k-o8x3a', Sanitizer::field( $x['purchase_event_id'], 'TW-O8X2K-O8X3A', '' ) );
        $this->assertSame( 'o8x2k', Sanitizer::field( $x['pixel_id'], 'O8X2K', '' ) );
        $this->assertSame( '', Sanitizer::field( $google['measurement_id'], '', 'G-OLD' ) );

        $this->expectException( InvalidArgumentException::class );
        Sanitizer::field( $google['ads_id'], 'AW-123/label', '' );
    }

    public function test_a_meta_pixel_id_must_be_digits() {
        $this->expectException( InvalidArgumentException::class );

        // An access token, not a Pixel ID.
        Sanitizer::field( Schema::fields( Schema::lite()['platforms']['facebook']['schema'] )['pixel_id'], 'EAAB12345', '' );
    }

    public function test_ids_of_an_unusual_length_save() {
        $meta = Schema::fields( Schema::lite()['platforms']['facebook']['schema'] );
        $x    = Schema::fields( Schema::lite()['platforms']['twitter']['schema'] );

        $this->assertSame( '1', Sanitizer::field( $meta['pixel_id'], '1', '' ) );
        $this->assertSame( str_repeat( '1', 40 ), Sanitizer::field( $meta['pixel_id'], str_repeat( '1', 40 ), '' ) );
        $this->assertSame( 'abc', Sanitizer::field( $x['pixel_id'], 'ABC', '' ) );
        $this->assertSame( 'o8x2k0o8x2k', Sanitizer::field( $x['pixel_id'], 'o8x2k0o8x2k', '' ) );
        $this->assertSame( array( 15, 16 ), $meta['pixel_id']['typical_length'] );
        $this->assertSame( array( 5, 5 ), $x['pixel_id']['typical_length'] );
    }

    /**
     * @dataProvider over_40_characters
     */
    public function test_every_id_field_is_capped_at_40_characters( $platform, $field, $value ) {
        $fields = Schema::fields( Schema::lite()['platforms'][ $platform ]['schema'] );

        $this->assertSame( strtolower( substr( $value, 0, 40 ) ), strtolower( Sanitizer::field( $fields[ $field ], substr( $value, 0, 40 ), '' ) ) );

        $this->expectException( InvalidArgumentException::class );
        Sanitizer::field( $fields[ $field ], $value, '' );
    }

    public function over_40_characters() {
        return array(
            'Meta Pixel ID'       => array( 'facebook', 'pixel_id', str_repeat( '1', 41 ) ),
            'Measurement ID'      => array( 'google', 'measurement_id', 'G-' . str_repeat( 'A', 39 ) ),
            'Google tag ID'       => array( 'google', 'tag_id', 'GT-' . str_repeat( 'A', 38 ) ),
            'Ads conversion ID'   => array( 'google', 'ads_id', 'AW-' . str_repeat( '1', 38 ) ),
            'Ads label'           => array( 'google', 'purchase_label', str_repeat( 'a', 41 ) ),
            'X Pixel ID'          => array( 'twitter', 'pixel_id', str_repeat( 'a', 41 ) ),
            'X Purchase event ID' => array( 'twitter', 'purchase_event_id', 'tw-o8x2k-' . str_repeat( 'a', 32 ) ),
        );
    }

    public function test_a_bare_ads_conversion_id_gets_aw() {
        $google = Schema::fields( Schema::lite()['platforms']['google']['schema'] );

        $this->assertSame( 'AW-123456789', Sanitizer::field( $google['ads_id'], ' 123456789 ', '' ) );
        $this->assertSame( 'AW-123456789', Sanitizer::field( $google['ads_id'], 'AW-123456789', '' ) );
        // Only where the prefix makes it fit: other fields and other values are left alone.
        $this->assertSame( '123', Sanitizer::with_prefix( array( 'pattern' => '^[0-9]+$' ), '123' ) );
        $this->assertSame( 'ABC', Sanitizer::with_prefix( $google['ads_id'], 'ABC' ) );

        $this->expectException( InvalidArgumentException::class );
        Sanitizer::field( $google['ads_id'], 'G-123', '' );
    }

    public function test_ids_are_capped_at_40_characters() {
        $field = Schema::id_field( 's', 'label', 'Label', '', '', '', 10 );

        $this->assertSame( 40, Sanitizer::MAX_ID_LENGTH );
        $this->assertSame( 40, strlen( Sanitizer::field( $field, str_repeat( 'a', 40 ), '' ) ) );

        $this->expectException( InvalidArgumentException::class );
        Sanitizer::field( $field, str_repeat( 'a', 41 ), '' );
    }

    public function test_text_rejects_arrays() {
        $this->expectException( InvalidArgumentException::class );

        Sanitizer::field( Schema::id_field( 's', 'pixel_id', 'Pixel ID', '', '', '', 10 ), array( 'x' ), '' );
    }

    public function test_scripts_are_kept_as_written() {
        $script = "<script>\r\nfbq('track', '{order_number}');\r\n</script>";
        $field  = array( 'id' => 'thankyou_script', 'type' => 'field', 'variant' => 'textarea', 'format' => 'script' );

        $this->assertSame( $script, Sanitizer::field( $field, $script, '' ) );
    }

    public function test_plain_textareas_are_stripped() {
        $field = array( 'id' => 'note', 'type' => 'field', 'variant' => 'textarea' );

        $this->assertSame( 'hi', Sanitizer::field( $field, '<b>hi</b>', '' ) );
    }

    public function test_switches_take_the_usual_spellings() {
        $field = array( 'id' => 'consent_mode', 'type' => 'field', 'variant' => 'switch' );

        foreach ( array( true, 1, '1', 'true', 'on', 'yes' ) as $on ) {
            $this->assertTrue( Sanitizer::field( $field, $on, false ) );
        }

        foreach ( array( false, 0, '0', 'false', 'off', 'no', '' ) as $off ) {
            $this->assertFalse( Sanitizer::field( $field, $off, true ) );
        }

        $this->expectException( InvalidArgumentException::class );
        Sanitizer::field( $field, 'maybe', false );
    }

    public function test_choices_must_be_an_option() {
        $mode = Schema::fields( Schema::lite()['platforms']['google']['schema'] )['mode'];

        $this->assertSame( 'ads', Sanitizer::field( $mode, 'ads', 'both' ) );

        $this->expectException( InvalidArgumentException::class );
        Sanitizer::field( $mode, 'tag-manager', 'both' );
    }

    public function test_events_are_options_in_schema_order() {
        $events = Schema::fields( Schema::lite()['platforms']['facebook']['schema'] )['events'];

        $this->assertSame( array( 'add_to_cart', 'purchase' ), Sanitizer::field( $events, array( 'purchase', 'add_to_cart', 'purchase' ), array() ) );
        $this->assertSame( array(), Sanitizer::field( $events, array(), array( 'purchase' ) ) );
    }

    public function test_unknown_events_are_rejected() {
        $events = Schema::fields( Schema::lite()['platforms']['facebook']['schema'] )['events'];

        $this->expectException( InvalidArgumentException::class );
        Sanitizer::field( $events, array( 'purchase', '<script>' ), array() );
    }

    public function test_events_must_be_a_list() {
        $events = Schema::fields( Schema::lite()['platforms']['facebook']['schema'] )['events'];

        $this->expectException( InvalidArgumentException::class );
        Sanitizer::field( $events, 'purchase', array() );
    }

    public function test_locked_events_keep_their_stored_state() {
        $events = Schema::fields( Schema::lite()['platforms']['facebook']['schema'] )['events'];

        // Free can't turn a Pro event on, and doesn't wipe one Pro saved.
        $this->assertSame( array( 'purchase' ), Sanitizer::field( $events, array( 'purchase', 'view_category' ), array() ) );
        $this->assertSame( array( 'purchase', 'add_to_wishlist' ), Sanitizer::field( $events, array( 'purchase' ), array( 'add_to_wishlist' ) ) );
    }

    public function test_always_on_events_stay_on() {
        $events = Schema::fields( Schema::lite()['platforms']['twitter']['schema'] )['events'];

        $this->assertSame( array( 'page_view', 'purchase' ), Sanitizer::field( $events, array( 'purchase' ), array() ) );
    }

    public function test_a_sanitize_callback_takes_over() {
        $field = array(
            'id'                => 'pixels',
            'type'              => 'field',
            'variant'           => 'wcct_pixels',
            'sanitize_callback' => function ( $value, $field, $current ) {
                return array( 'value' => $value, 'field' => $field['id'], 'current' => $current );
            },
        );

        $this->assertSame( array( 'value' => 'a', 'field' => 'pixels', 'current' => 'b' ), Sanitizer::field( $field, 'a', 'b' ) );
    }

    public function test_a_custom_variant_without_a_callback_is_rejected() {
        $this->expectException( InvalidArgumentException::class );

        Sanitizer::field( array( 'id' => 'x', 'type' => 'field', 'variant' => 'wcct_pixels' ), array(), array() );
    }
}
