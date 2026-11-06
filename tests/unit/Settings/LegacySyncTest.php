<?php

require_once dirname( __DIR__ ) . '/Fakes/settings-functions.php';

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Settings\LegacySync;

/**
 * Tests for writing 2.x settings page saves through to the 3.0 settings
 */
class LegacySyncTest extends TestCase {

    /**
     * Event checkboxes the Lite 2.x settings page shows.
     */
    const LITE_PAGE = array(
        'facebook' => array( 'AddToCart', 'InitiateCheckout', 'Purchase', 'Registration' ),
        'ga4'      => array( 'view_item', 'add_to_cart', 'begin_checkout', 'purchase' ),
        'adwords'  => array( 'Purchase' ),
        'twitter'  => array( 'Purchase' ),
    );

    protected function setUp(): void {
        $GLOBALS['wcct_test_options'] = array();
    }

    protected function tearDown(): void {
        unset( $GLOBALS['wcct_test_options'], $GLOBALS['wcct_test_filters'] );
    }

    private function sync( array $v3, array $v1, bool $scripts = true ) {
        $GLOBALS['wcct_test_options']['wcct_settings_v3'] = $v3;

        LegacySync::sync( $v1, self::LITE_PAGE, $scripts );

        return $GLOBALS['wcct_test_options']['wcct_settings_v3'];
    }

    public function test_custom_switch_and_scripts_write_through() {
        $v3 = array(
            'platforms' => array(
                'custom' => array(
                    'thankyou_enabled' => true,
                    'thankyou_script'  => '<script>old()</script>',
                    'header_enabled'   => true,
                    'header_script'    => '<script>hd()</script>',
                    'signup_enabled'   => true,
                    'signup_script'    => '<script>su()</script>',
                    'customer_details' => true,
                ),
            ),
        );

        $doc = $this->sync( $v3, array( 'custom' => array( 'enabled' => true, 'checkout' => '<script>new()</script>', 'registration' => '' ) ) );

        $this->assertSame(
            array(
                'thankyou_enabled' => true,
                'thankyou_script'  => '<script>new()</script>',
                'header_enabled'   => true,
                'header_script'    => '<script>hd()</script>',
                'signup_enabled'   => false,
                'signup_script'    => '',
                'customer_details' => true,
            ),
            $doc['platforms']['custom']
        );

        $doc = $this->sync( $doc, array( 'custom' => array( 'enabled' => false, 'checkout' => '<script>new()</script>', 'registration' => '' ) ) );

        $this->assertFalse( $doc['platforms']['custom']['thankyou_enabled'] );
        $this->assertTrue( $doc['platforms']['custom']['header_enabled'], 'the 3.0-only header stays' );
    }

    public function test_without_unfiltered_html_the_3_0_scripts_stay_and_the_switch_still_applies() {
        $v3  = array( 'platforms' => array( 'custom' => array( 'thankyou_enabled' => true, 'thankyou_script' => '<script>rest()</script>' ) ) );
        $doc = $this->sync( $v3, array( 'custom' => array( 'enabled' => false, 'checkout' => '<script>old()</script>' ) ), false );

        $this->assertSame( array( 'thankyou_enabled' => false, 'thankyou_script' => '<script>rest()</script>', 'signup_enabled' => false ), $doc['platforms']['custom'] );
    }

    public function test_google_ids_label_mode_and_shown_events_write_through() {
        $v3 = array(
            'platforms' => array(
                'google' => array(
                    'measurement_id'     => 'G-OLD',
                    'ads_id'             => 'AW-111',
                    'purchase_label'     => 'OldLabel',
                    'registration_label' => 'RegLabel',
                    'tag_id'             => 'GT-KEEP',
                    'mode'               => 'both',
                    'events'             => array( 'view_product', 'purchase', 'search' ),
                    'value_mode'         => 'subtotal',
                ),
            ),
        );
        $v1 = array(
            'ga4'     => array( 'enabled' => true, 0 => array( 'measurement_id' => 'G-NEW', 'events' => array( 'view_item' => 'on', 'add_to_cart' => 'on' ) ) ),
            'adwords' => array( 'enabled' => false, 0 => array( 'account_id' => 'AW-222', 'events' => array( 'Purchase-label' => 'NewLabel' ) ) ),
        );

        $google = $this->sync( $v3, $v1 )['platforms']['google'];

        $this->assertSame( 'G-NEW', $google['measurement_id'] );
        $this->assertSame( 'AW-222', $google['ads_id'] );
        $this->assertSame( 'NewLabel', $google['purchase_label'] );
        $this->assertSame( 'analytics', $google['mode'], 'Ads switched off on the 2.x page' );
        // Purchase unticked turns it off; Search has no 2.x checkbox and stays.
        $this->assertSame( array( 'search', 'view_product', 'add_to_cart' ), $google['events'] );
        $this->assertSame( 'GT-KEEP', $google['tag_id'] );
        $this->assertSame( 'RegLabel', $google['registration_label'] );
        $this->assertSame( 'subtotal', $google['value_mode'] );
    }

    public function test_google_keys_of_an_integration_the_2_x_option_lacks_are_kept() {
        $v3 = array( 'platforms' => array( 'google' => array( 'measurement_id' => 'G-OLD', 'ads_id' => 'AW-111', 'purchase_label' => 'Label', 'mode' => 'ads' ) ) );
        $v1 = array( 'ga4' => array( 'enabled' => true, 0 => array( 'measurement_id' => 'G-NEW' ) ) );

        $google = $this->sync( $v3, $v1 )['platforms']['google'];

        $this->assertSame( 'G-NEW', $google['measurement_id'] );
        $this->assertSame( 'AW-111', $google['ads_id'] );
        $this->assertSame( 'Label', $google['purchase_label'] );
        $this->assertSame( 'ads', $google['mode'] );
    }

    public function test_3_0_only_keys_and_platforms_survive_a_2_x_save() {
        $v3 = array(
            'consent_mode' => true,
            'platforms'    => array(
                'facebook' => array( 'pixel_id' => '111111111111111', 'events' => array( 'view_product', 'search', 'purchase' ), 'paused' => true, 'product_id' => 'sku' ),
                'twitter'  => array( 'pixel_id' => 'o8x2k', 'purchase_event_id' => 'tw-o8x2k-old', 'cart_event_id' => 'tw-o8x2k-cart', 'events' => array( 'page_view', 'purchase', 'add_to_cart' ), 'needs_resetup' => array( 'begin_checkout' ) ),
                'bing_ads' => array( 'tag_id' => '12345' ),
            ),
        );
        $v1 = array(
            'facebook' => array( 'enabled' => true, 0 => array( 'pixel_id' => '222222222222222', 'events' => array( 'AddToCart' => 'on' ) ) ),
            'twitter'  => array( 'enabled' => true, 0 => array( 'pixel_id' => 'o8x2k', 'events' => array( 'purchase_event_id' => 'tw-o8x2k-new' ) ) ),
        );

        $doc = $this->sync( $v3, $v1 );

        $this->assertTrue( $doc['consent_mode'] );
        $this->assertSame( array( 'tag_id' => '12345' ), $doc['platforms']['bing_ads'] );
        $this->assertSame(
            array( 'pixel_id' => '222222222222222', 'events' => array( 'view_product', 'search', 'add_to_cart' ), 'paused' => true, 'product_id' => 'sku' ),
            $doc['platforms']['facebook']
        );

        $twitter = $doc['platforms']['twitter'];

        $this->assertSame( 'tw-o8x2k-new', $twitter['purchase_event_id'] );
        $this->assertSame( 'tw-o8x2k-cart', $twitter['cart_event_id'], 'the Lite page shows no Add to cart ID' );
        $this->assertSame( array( 'page_view', 'add_to_cart' ), $twitter['events'] );
        $this->assertSame( array( 'begin_checkout' ), $twitter['needs_resetup'] );
    }

    public function test_nothing_is_written_before_3_0_settings_exist_or_while_the_migration_runs() {
        LegacySync::sync( array( 'custom' => array( 'enabled' => true, 'checkout' => '<script>ty()</script>' ) ), self::LITE_PAGE, true );
        $this->assertArrayNotHasKey( 'wcct_settings_v3', $GLOBALS['wcct_test_options'] );

        $GLOBALS['wcct_test_options']['wcct_migration_lock'] = '1|abc';
        $v3 = array( 'platforms' => array( 'custom' => array( 'thankyou_script' => '<script>keep()</script>' ) ) );

        $this->assertSame( $v3, $this->sync( $v3, array( 'custom' => array( 'enabled' => true, 'checkout' => '<script>ty()</script>' ) ) ) );
    }

    public function test_bad_data_never_fatals() {
        $this->assertSame( array( 'platforms' => 'x' ), $this->sync( array( 'platforms' => 'x' ), array() ) );

        $doc = $this->sync( array( 'platforms' => 'x' ), array( 'custom' => 'x', 'ga4' => array( 'enabled' => true, 0 => 'x' ) ) );

        $this->assertSame( '', $doc['platforms']['google']['measurement_id'] );

        LegacySync::sync( 'not an array', self::LITE_PAGE, true );
        $this->assertSame( $doc, $GLOBALS['wcct_test_options']['wcct_settings_v3'] );
    }

    public function test_the_page_events_are_the_checkboxes_the_user_can_change() {
        $facebook = new class() extends WCCT_Test_Fake_Integration {
            public function __construct() {
                parent::__construct( 'facebook' );
            }

            public function get_settings() {
                return array(
                    'id'     => array( 'name' => 'pixel_id', 'type' => 'text' ),
                    'events' => array(
                        'name'    => 'events',
                        'options' => array(
                            'AddToCart'       => 'Add to Cart',
                            'Purchase'        => array( 'label' => 'Purchase' ),
                            'ViewContent-pro' => array( 'label' => 'View Product', 'profeature' => true ),
                        ),
                    ),
                );
            }
        };

        $this->assertSame( array( 'facebook' => array( 'AddToCart', 'Purchase' ) ), LegacySync::page_events( array( $facebook, new WCCT_Test_Fake_Integration( 'custom' ), 'not an integration' ) ) );
    }
}
