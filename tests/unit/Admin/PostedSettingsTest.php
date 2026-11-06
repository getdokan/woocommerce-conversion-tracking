<?php

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Admin\PostedSettings;

/**
 * Tests for cleaning the posted settings form before it is saved
 */
class PostedSettingsTest extends TestCase {

    /**
     * Field definitions as the integrations' get_settings() return them
     *
     * @return array
     */
    private function fields() {
        return array(
            'facebook' => array(
                'id'     => array( 'type' => 'text', 'name' => 'pixel_id', 'label' => 'Pixel ID' ),
                'events' => array(
                    'type'    => 'multicheck',
                    'name'    => 'events',
                    'options' => array(
                        'AddToCart'       => 'Add to Cart',
                        'Purchase'        => 'Purchase',
                        'ViewContent-pro' => array( 'label' => 'View Product', 'profeature' => true ),
                    ),
                ),
            ),
            'adwords'  => array(
                'id'     => array( 'type' => 'text', 'name' => 'account_id', 'label' => 'Account ID' ),
                'events' => array(
                    'type'    => 'multicheck',
                    'name'    => 'events',
                    'options' => array(
                        'Purchase' => array( 'label' => 'Purchase', 'event_label_box' => true, 'label_name' => 'Purchase-label' ),
                    ),
                ),
            ),
            'custom'   => array(
                array( 'type' => 'textarea', 'name' => 'checkout' ),
                array( 'type' => 'textarea', 'name' => 'registration' ),
            ),
            'extra'    => array(
                array( 'type' => 'checkbox', 'name' => 'debug' ),
            ),
        );
    }

    public function test_the_shape_the_settings_page_saves_is_kept() {
        $script = "<script>ctCustom('{order_number}', \"x\");</script>";
        $posted = array(
            'facebook' => array(
                'enabled' => '1',
                0         => array( 'pixel_id' => '111111111111111', 'events' => array( 'AddToCart' => 'on', 'Purchase' => 'on' ) ),
            ),
            'adwords'  => array(
                'enabled' => '1',
                0         => array( 'account_id' => 'AW-123456789', 'events' => array( 'Purchase' => 'on', 'Purchase-label' => 'CtPurchaseLabel' ) ),
            ),
            'custom'   => array( 'enabled' => '1', 'checkout' => $script, 'registration' => '' ),
        );

        $this->assertSame(
            array(
                'facebook' => array(
                    'enabled' => true,
                    0         => array( 'pixel_id' => '111111111111111', 'events' => array( 'AddToCart' => 'on', 'Purchase' => 'on' ) ),
                ),
                'adwords'  => array(
                    'enabled' => true,
                    0         => array( 'account_id' => 'AW-123456789', 'events' => array( 'Purchase' => 'on', 'Purchase-label' => 'CtPurchaseLabel' ) ),
                ),
                'custom'   => array( 'enabled' => true, 'checkout' => $script, 'registration' => '' ),
            ),
            PostedSettings::clean( $posted, $this->fields() )
        );
    }

    public function test_unexpected_keys_are_not_stored() {
        $posted = array(
            'facebook'  => array(
                'enabled'  => '1',
                'injected' => '<script>x</script>',
                0          => array(
                    'pixel_id' => '111',
                    'other'    => 'x',
                    'events'   => array( 'Purchase' => 'on', 'Unknown' => 'on', 'Purchase-label' => 'x' ),
                ),
            ),
            'unknown'   => array( 'enabled' => '1', 0 => array( 'id' => '1' ) ),
            'custom'    => array( 'checkout' => 'a', 'extra' => 'b' ),
            'not-array' => 'x',
        );

        $this->assertSame(
            array(
                'facebook' => array(
                    'enabled' => true,
                    0         => array( 'pixel_id' => '111', 'events' => array( 'Purchase' => 'on' ) ),
                ),
                'custom'   => array( 'checkout' => 'a', 'enabled' => false ),
            ),
            PostedSettings::clean( $posted, $this->fields() )
        );
    }

    public function test_values_are_coerced_to_their_field_type() {
        $clean = PostedSettings::clean(
            array(
                'facebook' => array(
                    0 => array(
                        'pixel_id' => array( '111' ),
                        'events'   => array( 'AddToCart' => array( 'on' ), 'Purchase' => 'yes' ),
                    ),
                ),
                'adwords'  => array(
                    0 => array( 'account_id' => " AW-1\r\n", 'events' => array( 'Purchase-label' => array( 'x' ) ) ),
                ),
                'custom'   => array( 'checkout' => array( 'x' ) ),
                'extra'    => array( 'debug' => 'on' ),
            ),
            $this->fields()
        );

        $this->assertSame( array( 'pixel_id' => '', 'events' => array() ), $clean['facebook'][0] );
        $this->assertSame( array( 'account_id' => 'AW-1', 'events' => array( 'Purchase-label' => '' ) ), $clean['adwords'][0] );
        $this->assertSame( '', $clean['custom']['checkout'] );
        $this->assertSame( array( 'debug' => 'on', 'enabled' => false ), $clean['extra'] );
    }

    public function test_pro_teaser_options_are_dropped() {
        $clean = PostedSettings::clean(
            array( 'facebook' => array( 0 => array( 'events' => array( 'ViewContent-pro' => 'on', 'Purchase' => 'on' ) ) ) ),
            $this->fields()
        );

        $this->assertSame( array( 'Purchase' => 'on' ), $clean['facebook'][0]['events'] );
    }

    public function test_extra_pixel_groups_are_numbered_in_order() {
        $clean = PostedSettings::clean(
            array(
                'facebook' => array(
                    'enabled' => '1',
                    0         => array( 'pixel_id' => '111' ),
                    3         => array( 'pixel_id' => '222' ),
                    'x'       => array( 'pixel_id' => '333' ),
                    5         => 'not a group',
                ),
            ),
            $this->fields()
        );

        $this->assertSame(
            array(
                'enabled' => true,
                0         => array( 'pixel_id' => '111' ),
                1         => array( 'pixel_id' => '222' ),
            ),
            $clean['facebook']
        );
    }

    public function test_an_integration_switched_off_is_saved_disabled() {
        $clean = PostedSettings::clean( array( 'facebook' => array( 0 => array( 'pixel_id' => '111' ) ) ), $this->fields() );

        $this->assertFalse( $clean['facebook']['enabled'] );
    }
}
