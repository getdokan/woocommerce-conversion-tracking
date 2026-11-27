<?php

namespace StorePulse\ConversionTracking\Admin;

/**
 * Where every Upgrade, See pricing, Compare plans and Manage subscription link goes: Pro is sold only on WooCommerce.com.
 *
 * @since PLUGIN_SINCE
 */
class Upgrade {

    /**
     * The Pro product page on WooCommerce.com; Upgrade, See pricing and Compare plans link here.
     *
     * @var string
     */
    const PRODUCT_URL = 'https://woocommerce.com/products/woocommerce-conversion-tracking-pro/';

    /**
     * The buyer's subscriptions on WooCommerce.com; Manage subscription links here.
     *
     * @var string
     */
    const SUBSCRIPTIONS_URL = 'https://woocommerce.com/my-account/my-subscriptions/';

    /**
     * Filter for the links.
     *
     * @var string
     */
    const FILTER = 'wcct_upgrade_links';

    /**
     * The upgrade and manage-subscription links; a filtered value that isn't an https URL falls back to the default.
     *
     * @return array { upgrade: string, manage: string }
     */
    public static function links() {
        $defaults = array(
            'upgrade' => self::PRODUCT_URL,
            'manage'  => self::SUBSCRIPTIONS_URL,
        );

        /**
         * Filter the links every Upgrade and Manage subscription button opens.
         *
         * @since PLUGIN_SINCE
         *
         * @param array $links { upgrade, manage } URLs.
         */
        $links = apply_filters( self::FILTER, $defaults );
        $links = is_array( $links ) ? $links : array();

        foreach ( $defaults as $key => $url ) {
            if ( isset( $links[ $key ] ) && self::is_https_url( $links[ $key ] ) ) {
                $defaults[ $key ] = $links[ $key ];
            }
        }

        return $defaults;
    }

    /**
     * The upgrade link (the WooCommerce.com product page).
     *
     * @return string
     */
    public static function url() {
        return self::links()['upgrade'];
    }

    /**
     * The manage-subscription link (WooCommerce.com subscriptions).
     *
     * @return string
     */
    public static function manage_url() {
        return self::links()['manage'];
    }

    /**
     * Whether a value is an absolute https URL.
     *
     * @param mixed $url Value.
     *
     * @return bool
     */
    private static function is_https_url( $url ) {
        return is_string( $url ) && 0 === strpos( $url, 'https://' ) && false !== filter_var( $url, FILTER_VALIDATE_URL );
    }
}
