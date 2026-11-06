<?php

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Platforms\XSettings;

/**
 * Tests for the X settings reader
 */
class XSettingsTest extends TestCase {

    private function v1( array $first ) {
        return array(
            'enabled' => true,
            $first,
        );
    }

    public function test_reads_the_3_0_keys_saved_by_the_settings_form() {
        $values = XSettings::from_v1(
            $this->v1(
                array(
                    'pixel_id' => 'o8x2k',
                    'events'   => array(
                        'Purchase'          => 'on',
                        'purchase_event_id' => 'tw-o8x2k-o8x3a',
                        'AddToCart'         => 'on',
                        'cart_event_id'     => 'tw-o8x2k-o8x3b',
                    ),
                )
            )
        );

        $this->assertSame( 'o8x2k', $values['pixel_id'] );
        $this->assertSame( array( 'page_view', 'purchase', 'add_to_cart' ), $values['events'] );
        $this->assertSame( 'tw-o8x2k-o8x3a', XSettings::event_id( $values, 'purchase' ) );
        $this->assertSame( 'tw-o8x2k-o8x3b', XSettings::event_id( $values, 'add_to_cart' ) );
        $this->assertSame( array(), $values['needs_resetup'] );
    }

    public function test_the_2_x_universal_tag_becomes_the_pixel_id_and_purchase_needs_a_resetup() {
        $values = XSettings::from_v1(
            $this->v1(
                array(
                    'events' => array(
                        'Purchase'         => 'on',
                        'universal_tag_id' => 'ct0x1',
                    ),
                )
            )
        );

        $this->assertSame( 'ct0x1', $values['pixel_id'] );
        $this->assertSame( '', XSettings::event_id( $values, 'purchase' ) );
        $this->assertSame( array( 'purchase' ), $values['needs_resetup'] );
    }

    public function test_legacy_labels_need_a_resetup_and_are_never_sent() {
        $values = XSettings::from_v1(
            $this->v1(
                array(
                    'events' => array(
                        'universal_tag_id'   => 'ct0x1',
                        'AddToCart'          => 'on',
                        'AddtoCart-label'    => 'ctadd01',
                        'Registration'       => 'on',
                        'registration-label' => 'tw-ct0x1-looks1',
                    ),
                )
            )
        );

        $this->assertSame( array( 'add_to_cart', 'sign_up' ), $values['needs_resetup'] );
        $this->assertSame( '', XSettings::event_id( $values, 'add_to_cart' ) );
        $this->assertSame( '', XSettings::event_id( $values, 'sign_up' ) );
    }

    public function test_a_new_event_id_clears_the_resetup_flag() {
        $values = XSettings::from_v1(
            $this->v1(
                array(
                    'events' => array(
                        'AddToCart'       => 'on',
                        'AddtoCart-label' => 'ctadd01',
                        'cart_event_id'   => 'tw-ct0x1-ctadd',
                    ),
                )
            )
        );

        $this->assertSame( array(), $values['needs_resetup'] );
        $this->assertSame( 'tw-ct0x1-ctadd', XSettings::event_id( $values, 'add_to_cart' ) );
    }

    /**
     * @dataProvider rows_that_stay_off
     */
    public function test_a_row_stays_off_without_its_switch_or_a_valid_id( array $events ) {
        $values = XSettings::from_v1( $this->v1( array( 'pixel_id' => 'o8x2k', 'events' => $events ) ) );

        $this->assertSame( '', XSettings::event_id( $values, 'begin_checkout' ) );
    }

    public function rows_that_stay_off() {
        return array(
            'no id'       => array( array( 'InitiateCheckout' => 'on' ) ),
            'empty id'    => array( array( 'InitiateCheckout' => 'on', 'checkout_event_id' => '  ' ) ),
            'switch off'  => array( array( 'checkout_event_id' => 'tw-o8x2k-o8x3c' ) ),
            'bad format'  => array( array( 'InitiateCheckout' => 'on', 'checkout_event_id' => 'o8x3c' ) ),
            'script'      => array( array( 'InitiateCheckout' => 'on', 'checkout_event_id' => "tw-a-b');alert(1)//" ) ),
        );
    }

    public function test_bad_data_reads_as_nothing_set() {
        foreach ( array( null, 'x', array(), array( 'enabled' => true ), array( array( 'events' => 'x' ) ) ) as $saved ) {
            $values = XSettings::from_v1( $saved );

            $this->assertSame( '', $values['pixel_id'] );
            $this->assertSame( array( 'page_view' ), $values['events'] );
            $this->assertSame( '', XSettings::event_id( $values, 'purchase' ) );
        }
    }

    public function test_reads_the_3_0_settings_shape() {
        $values = XSettings::from_v3(
            array(
                'pixel_id'          => 'o8x2k',
                'purchase_event_id' => 'tw-o8x2k-o8x3a',
                'signup_event_id'   => 'tw-o8x2k-o8x3d',
                'events'            => array( 'page_view', 'purchase', 'sign_up', 5 ),
            )
        );

        $this->assertSame( '', $values['cart_event_id'] );
        $this->assertSame( array( 'page_view', 'purchase', 'sign_up' ), $values['events'] );
        $this->assertSame( 'tw-o8x2k-o8x3d', XSettings::event_id( $values, 'sign_up' ) );
        $this->assertSame( '', XSettings::event_id( $values, 'unknown' ) );
    }

    public function test_3_0_resetup_flags_clear_once_the_event_has_an_id() {
        $values = XSettings::from_v3( array( 'cart_event_id' => 'tw-o8x2k-o8x3b', 'needs_resetup' => array( 'purchase', 'add_to_cart', 'sign_up' ) ) );

        $this->assertSame( array( 'purchase', 'sign_up' ), $values['needs_resetup'] );
    }

    public function test_a_save_keeps_the_2_x_keys_the_form_no_longer_posts() {
        $stored = array( 'enabled' => true, array( 'events' => array( 'universal_tag_id' => 'ct0x1', 'AddtoCart-label' => 'ctadd01', 'Purchase' => 'on' ) ) );
        $posted = array( 'enabled' => true, array( 'pixel_id' => 'ct0x1', 'events' => array( 'Purchase' => 'on' ) ) );
        $kept   = XSettings::keep_legacy( $posted, $stored );

        $this->assertSame( array( 'Purchase' => 'on', 'universal_tag_id' => 'ct0x1', 'AddtoCart-label' => 'ctadd01' ), $kept[0]['events'] );
        $this->assertSame( array( 'purchase', 'add_to_cart' ), XSettings::from_v1( $kept )['needs_resetup'] );
        $this->assertSame( $posted, XSettings::keep_legacy( $posted, false ) );
    }
}
