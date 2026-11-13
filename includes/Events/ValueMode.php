<?php

namespace StorePulse\ConversionTracking\Events;

use StorePulse\ConversionTracking\Install;

/**
 * Per-platform order value mode: what the Purchase value includes.
 */
class ValueMode {

    /**
     * Items only: line items after discounts plus fees, without tax or shipping.
     */
    const ITEMS = 'items';

    /**
     * Items and shipping, without tax.
     */
    const SHIPPING = 'shipping';

    /**
     * Items, tax and shipping: the order total.
     */
    const ALL = 'all';

    /**
     * Platforms that sent the order total before 3.0 and keep it for upgraders (D8).
     */
    const UPGRADE_KEEPS_TOTAL = array( 'facebook', 'adwords', 'twitter', 'bing_ads' );

    /**
     * Every mode.
     *
     * @return string[]
     */
    public static function modes() {
        return array( self::ITEMS, self::SHIPPING, self::ALL );
    }

    /**
     * Whether a value is a known mode.
     *
     * @param mixed $mode Raw mode.
     *
     * @return bool
     */
    public static function is_mode( $mode ) {
        return is_string( $mode ) && in_array( $mode, self::modes(), true );
    }

    /**
     * Where each integration's mode is stored: its platform key in the 3.0 settings. GA4 has none, it always sends the item total.
     */
    const SETTINGS_KEYS = array(
        'facebook' => 'facebook',
        'adwords'  => 'google',
        'twitter'  => 'twitter',
        'bing_ads' => 'bing_ads',
    );

    /**
     * The integration's mode: the stored one, else the default.
     *
     * @param string $platform Integration ID.
     *
     * @return string
     */
    public static function for_platform( $platform ) {
        if ( ! is_string( $platform ) || ! isset( self::SETTINGS_KEYS[ $platform ] ) ) {
            return self::ITEMS;
        }

        $settings = get_option( 'wcct_settings_v3', array() );
        $key      = self::SETTINGS_KEYS[ $platform ];
        $stored   = is_array( $settings ) && isset( $settings['platforms'][ $key ]['value_mode'] ) ? $settings['platforms'][ $key ]['value_mode'] : '';
        $mode     = self::is_mode( $stored ) ? $stored : self::default_for( $platform, Install::is_upgrade() );

        /**
         * Order value mode for an integration: items, shipping (items and shipping) or all (items, tax and shipping). Not used for GA4.
         *
         * @since 3.0.0
         *
         * @param string $mode     Mode.
         * @param string $platform Integration ID.
         */
        $mode = apply_filters( 'wcct_value_mode', $mode, $platform );

        return self::is_mode( $mode ) ? $mode : self::ITEMS;
    }

    /**
     * Default mode: items only, except the order total for upgraders on the platforms that sent it before.
     *
     * @param string $platform Integration ID.
     * @param bool   $upgraded Whether the store was upgraded from 2.x.
     *
     * @return string
     */
    public static function default_for( $platform, $upgraded ) {
        return $upgraded && in_array( $platform, self::UPGRADE_KEEPS_TOTAL, true ) ? self::ALL : self::ITEMS;
    }

    /**
     * The Purchase value for a mode, from the order total, its tax and its shipping.
     *
     * @param float  $total    Order total.
     * @param float  $tax      Total tax, shipping and fee tax included.
     * @param float  $shipping Shipping without tax.
     * @param string $mode     Mode.
     * @param int    $decimals Price decimals.
     *
     * @return float
     */
    public static function value( $total, $tax, $shipping, $mode, $decimals = 2 ) {
        $total    = (float) $total;
        $tax      = (float) $tax;
        $shipping = (float) $shipping;

        // Fees count as items; WooCommerce stores tax-inclusive prices without their tax, so items never include tax.
        switch ( $mode ) {
            case self::ALL:
                $value = $total;
                break;

            case self::SHIPPING:
                $value = $total - $tax;
                break;

            default:
                $value = $total - $tax - $shipping;
        }

        return Event::amount( $value, $decimals );
    }
}
