/**
 * The plan the store is on and where upgrades are bought: Pro is sold only on WooCommerce.com.
 */

// Fallbacks when wcctAdmin is missing; PHP's Admin\Upgrade is the source.
const PRODUCT_URL =
    'https://woocommerce.com/products/woocommerce-conversion-tracking-pro/';
const SUBSCRIPTIONS_URL =
    'https://woocommerce.com/my-account/my-subscriptions/';

/**
 * Whether a Pro built for this Free (3.0 or later) is running; an older Pro is paused and doesn't count.
 */
export const isPro = (): boolean => !! window.wcctAdmin?.proActive;

/**
 * Whether a Pro older than 3.0 is installed and paused until it's updated.
 */
export const isProOutdated = (): boolean =>
    !! window.wcctAdmin?.proVersion && ! window.wcctAdmin?.proActive;

/**
 * Where an outdated Pro is updated: the plugins list filtered to updates.
 */
export const proUpdateUrl = (): string => window.wcctAdmin?.proUpdate || '';

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
