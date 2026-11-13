/**
 * External dependencies
 */
import type { StatusTone } from '@wcct/components';

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { LogOrder, LogStatus, LogTab } from '../../data/log';

/**
 * Pill tone per order status: Counted once green, Not confirmed and Pending amber, the rest grey.
 */
export const TONES: Record< LogStatus, StatusTone > = {
    confirmed: 'ok',
    not_confirmed: 'warn',
    pending: 'warn',
    no_checkin: 'off',
    waiting: 'off',
    skipped: 'off',
    paused: 'off',
};

/**
 * A tab's label.
 *
 * @param {LogTab} tab Tab.
 */
export const tabLabel = ( tab: LogTab ): string => {
    switch ( tab ) {
        case 'tracked':
            return __( 'Tracked', 'woocommerce-conversion-tracking' );
        case 'not-confirmed':
            return __( 'Not confirmed', 'woocommerce-conversion-tracking' );
        case 'pending':
            return __( 'Pending', 'woocommerce-conversion-tracking' );
        default:
            return __( 'All', 'woocommerce-conversion-tracking' );
    }
};

/**
 * Names joined for a sentence in the admin language, for example "Meta and X".
 *
 * @param {string[]} names Names.
 */
export const joinNames = ( names: string[] ): string => {
    try {
        return new Intl.ListFormat( document.documentElement.lang || 'en', {
            type: 'conjunction',
        } ).format( names );
    } catch {
        return names.join( ', ' );
    }
};

/**
 * Names of the platforms in a status, joined for a sentence.
 *
 * @param {LogOrder}  order  Order.
 * @param {LogStatus} status Platform status.
 */
export const platformNames = ( order: LogOrder, status: LogStatus ): string =>
    joinNames(
        order.platforms
            .filter( ( platform ) => platform.status === status )
            .map( ( platform ) => platform.name )
    );

/**
 * The status pill text.
 *
 * @param {LogOrder} order Order.
 */
export const statusLabel = ( order: LogOrder ): string => {
    switch ( order.status ) {
        case 'confirmed':
            return __( 'Counted once', 'woocommerce-conversion-tracking' );
        case 'not_confirmed':
            return sprintf(
                /* translators: %s: platform names, for example Meta */
                __( 'Not confirmed on %s', 'woocommerce-conversion-tracking' ),
                platformNames( order, 'not_confirmed' )
            );
        case 'no_checkin':
            return __( 'No check-in', 'woocommerce-conversion-tracking' );
        case 'pending':
            return __( 'Pending payment', 'woocommerce-conversion-tracking' );
        case 'waiting':
            return __( 'Checking', 'woocommerce-conversion-tracking' );
        default:
            return __( 'Skipped', 'woocommerce-conversion-tracking' );
    }
};

/**
 * A neutral line for an order status that never sends, for example "Order status: On hold".
 *
 * @param {LogOrder} order Order.
 */
export const orderStatusText = ( order: LogOrder ): string =>
    sprintf(
        /* translators: %s: order status, for example On hold */
        __( 'Order status: %s', 'woocommerce-conversion-tracking' ),
        order.order_status_label
    );

/**
 * What the row is: a purchase, a failed or cancelled order, or another status that never sends.
 *
 * @param {LogOrder} order Order.
 */
export const eventLabel = ( order: LogOrder ): string => {
    if ( order.status !== 'skipped' || order.cause !== 'order_status' ) {
        return __( 'Purchase', 'woocommerce-conversion-tracking' );
    }

    if ( order.order_status === 'failed' ) {
        return __( 'Failed order', 'woocommerce-conversion-tracking' );
    }

    if ( order.order_status === 'cancelled' ) {
        return __( 'Cancelled order', 'woocommerce-conversion-tracking' );
    }

    return orderStatusText( order );
};

/**
 * The Sent column's text where it shows no logos; empty when it shows them.
 *
 * @param {LogOrder} order Order.
 */
export const sentText = ( order: LogOrder ): string => {
    switch ( order.status ) {
        case 'pending':
            return __(
                'Waiting for payment',
                'woocommerce-conversion-tracking'
            );
        case 'skipped':
            return __(
                'Nothing sent, by design',
                'woocommerce-conversion-tracking'
            );
        case 'no_checkin':
            return order.cause === 'not_reported'
                ? __(
                      'Not checked: the thank-you page didn’t report back',
                      'woocommerce-conversion-tracking'
                  )
                : __(
                      'Not checked: no thank-you page visit',
                      'woocommerce-conversion-tracking'
                  );
    }

    if ( ! order.platforms.some( ( platform ) => platform.sent ) ) {
        if ( order.status !== 'waiting' ) {
            return __( 'Nothing confirmed', 'woocommerce-conversion-tracking' );
        }

        return order.platforms.some( ( platform ) => platform.checked_in_at )
            ? __(
                  'Waiting for the platforms to confirm',
                  'woocommerce-conversion-tracking'
              )
            : __(
                  'Waiting for the thank-you page',
                  'woocommerce-conversion-tracking'
              );
    }

    return '';
};

/**
 * The first step of a confirmed hand-over, in the platform's words.
 *
 * @param {string} id Platform ID.
 */
export const handOverStep = ( id: string ): string => {
    if ( id === 'google' ) {
        return __( 'Browser tag fired', 'woocommerce-conversion-tracking' );
    }

    if ( id === 'custom' ) {
        return __( 'Your script ran', 'woocommerce-conversion-tracking' );
    }

    return __( 'Browser pixel fired', 'woocommerce-conversion-tracking' );
};
