<?php
// phpcs:disable

/**
 * Tracking settings with fake IDs, in the shape the Lite settings page saves them
 */
class WCCT_Test_Settings {

    /**
     * Placeholders the Custom integration replaces on the thank-you page
     *
     * @var string
     */
    const CUSTOM_CHECKOUT = "<script>ctCustom('purchase', { id: '{customer_id}', email: '{customer_email}', first: '{customer_first_name}', last: '{customer_last_name}', number: '{order_number}', total: '{order_total}', subtotal: '{order_subtotal}', discount: '{order_discount}', shipping: '{order_shipping}', currency: '{currency}', payment: '{payment_method}', coupons: '{used_coupons}' });</script>";

    /**
     * Every Lite integration enabled, with all of its events
     *
     * @return array
     */
    public static function lite_all() {
        return array(
            'facebook' => array(
                'enabled' => true,
                array(
                    'pixel_id' => '111111111111111',
                    'events'   => array(
                        'AddToCart'        => 'on',
                        'InitiateCheckout' => 'on',
                        'Purchase'         => 'on',
                        'Registration'     => 'on',
                    ),
                ),
            ),
            'adwords'  => array(
                'enabled' => true,
                array(
                    'account_id' => 'AW-123456789',
                    'events'     => array(
                        'Purchase'       => 'on',
                        'Purchase-label' => 'CtPurchaseLabel',
                    ),
                ),
            ),
            'twitter'  => array(
                'enabled' => true,
                array(
                    'pixel_id' => 'ct0x1',
                    'events'   => array(
                        'Purchase'          => 'on',
                        'purchase_event_id' => 'tw-ct0x1-ctpur',
                    ),
                ),
            ),
            'ga4'      => array(
                'enabled' => true,
                array(
                    'measurement_id' => 'G-CTTEST1234',
                    'events'         => array(
                        'view_item'      => 'on',
                        'add_to_cart'    => 'on',
                        'begin_checkout' => 'on',
                        'purchase'       => 'on',
                        // Saved by 3.0's settings page (#92, #87).
                        'search'         => 'on',
                        'sign_up'        => 'on',
                    ),
                ),
            ),
            'custom'   => array(
                'enabled'      => true,
                'checkout'     => self::CUSTOM_CHECKOUT,
                'registration' => "<script>ctCustom('registration');</script>",
            ),
        );
    }

    /**
     * X as 2.x saved it: the universal tag ID and Purchase, no event ID
     *
     * @return array
     */
    public static function twitter_v2() {
        return array(
            'twitter' => array(
                'enabled' => true,
                array(
                    'events' => array(
                        'Purchase'         => 'on',
                        'universal_tag_id' => 'ct0x1',
                    ),
                ),
            ),
        );
    }

    /**
     * Settings with one integration enabled, or all of them for "all"
     *
     * @param array  $all         All integrations' settings.
     * @param string $integration Integration ID or "all".
     *
     * @return array
     */
    public static function only( array $all, $integration ) {
        return 'all' === $integration ? $all : array( $integration => $all[ $integration ] );
    }
}
