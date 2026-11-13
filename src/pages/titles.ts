/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { Route } from '../router';

export type PageInfo = {
    title: string;
    description?: string;
};

/**
 * Heading and description of the page a route shows; the heading also titles the browser tab.
 *
 * @param {Route} route Route.
 */
export const pageInfo = ( route: Route ): PageInfo => {
    switch ( route.name ) {
        case 'platforms':
            return {
                title: __( 'Platforms', 'woocommerce-conversion-tracking' ),
                description: __(
                    'Connect each place you run ads. The recommended setup is on by default, so most stores only need an ID.',
                    'woocommerce-conversion-tracking'
                ),
            };
        case 'catalog':
            return {
                title: __( 'Catalog', 'woocommerce-conversion-tracking' ),
                description: __(
                    'Send your products to Meta so dynamic ads and shops always show the right item, price and stock.',
                    'woocommerce-conversion-tracking'
                ),
            };
        case 'log':
            return {
                title: __( 'Activity log', 'woocommerce-conversion-tracking' ),
                description: __(
                    'What happened to each order. Order numbers and amounts only, never customer details.',
                    'woocommerce-conversion-tracking'
                ),
            };
        case 'doctor':
            return {
                title: __(
                    'Tracking Doctor',
                    'woocommerce-conversion-tracking'
                ),
                description: __(
                    'Finds duplicate or broken tracking and says what to do. It never changes your settings or other plugins.',
                    'woocommerce-conversion-tracking'
                ),
            };
        case 'settings':
            return {
                title: __( 'Settings', 'woocommerce-conversion-tracking' ),
                description: __(
                    'Alerts, who can manage tracking, setup and your plan.',
                    'woocommerce-conversion-tracking'
                ),
            };
        case 'whats-new':
            return {
                title: __( "What's new", 'woocommerce-conversion-tracking' ),
            };
        case 'onboarding':
            return { title: __( 'Setup', 'woocommerce-conversion-tracking' ) };
        default:
            return {
                title: __( 'Dashboard', 'woocommerce-conversion-tracking' ),
                description: __(
                    'Is my tracking working? Last 24 hours.',
                    'woocommerce-conversion-tracking'
                ),
            };
    }
};
