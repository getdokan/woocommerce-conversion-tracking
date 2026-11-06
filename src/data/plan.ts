/**
 * The plan the store is on and where upgrades are bought: Pro is sold only on WooCommerce.com.
 */

// Fallbacks when wcctAdmin is missing; PHP's Admin\Upgrade is the source.
const PRODUCT_URL =
    'https://woocommerce.com/products/woocommerce-conversion-tracking-pro/';
const SUBSCRIPTIONS_URL =
    'https://woocommerce.com/my-account/my-subscriptions/';

/**
 * Whether Pro is active.
 */
export const isPro = (): boolean => !! window.wcctAdmin?.proVersion;

/**
 * Every Upgrade, See pricing and Compare plans link: the Pro page on WooCommerce.com.
 */
export const upgradeUrl = (): string =>
    window.wcctAdmin?.upgrade?.upgrade || PRODUCT_URL;

/**
 * Manage subscription: the buyer's subscriptions on WooCommerce.com.
 */
export const manageUrl = (): string =>
    window.wcctAdmin?.upgrade?.manage || SUBSCRIPTIONS_URL;
