/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';

// The Custom script's placements, as Platforms\CustomScript::PLACEMENTS names them.
export type Placement = 'thankyou' | 'header' | 'signup';

export const PLACEMENTS: Placement[] = [ 'thankyou', 'header', 'signup' ];

// Order placeholders; they fill in on the thank-you page only.
export const ORDER_PLACEHOLDERS = [
    '{order_number}',
    '{order_total}',
    '{order_subtotal}',
    '{order_discount}',
    '{order_shipping}',
    '{currency}',
    '{payment_method}',
];

// Kept from 2.x: {order_id} fills like {order_number}, {used_coupons} with the coupon codes.
export const ORDER_ALIASES = [ '{order_id}', '{used_coupons}' ];

// Customer placeholders (Privacy\CustomerDetails::PLACEHOLDERS).
export const CUSTOMER_PLACEHOLDERS = [
    '{customer_id}',
    '{customer_email}',
    '{customer_first_name}',
    '{customer_last_name}',
];

const ORDER = [ ...ORDER_PLACEHOLDERS, ...ORDER_ALIASES ];

// Anything that looks like an order or customer placeholder, known or not.
const CANDIDATE =
    /\{(?:order_[a-z0-9_]*|customer_[a-z0-9_]*|currency|payment_method|used_coupons)\}/g;

const unique = ( items: string[] ) => [ ...new Set( items ) ];

/**
 * The placeholders a script holds, known or not, in order of first use.
 *
 * @param {string} script Script.
 */
export const placeholdersIn = ( script: string ): string[] =>
    unique( script.match( CANDIDATE ) ?? [] );

/**
 * Why some placeholders of a script won't be filled where it runs; empty when they all will.
 *
 * @param {string}    script          Script.
 * @param {Placement} placement       Where it runs.
 * @param {boolean}   customerDetails Whether "Include customer details in scripts" is on.
 */
export const placeholderHints = (
    script: string,
    placement: Placement,
    customerDetails: boolean
): string[] => {
    const used = placeholdersIn( script );
    const unknown = used.filter(
        ( item ) =>
            ! ORDER.includes( item ) && ! CUSTOMER_PLACEHOLDERS.includes( item )
    );
    const order = used.filter( ( item ) => ORDER.includes( item ) );
    const customer = used.filter( ( item ) =>
        CUSTOMER_PLACEHOLDERS.includes( item )
    );
    const hints: string[] = [];

    if ( unknown.length ) {
        hints.push(
            sprintf(
                /* translators: %s: placeholders, like {order_totl}. */
                _n(
                    "%s isn't a placeholder, so it stays in the script as typed.",
                    "%s aren't placeholders, so they stay in the script as typed.",
                    unknown.length,
                    'woocommerce-conversion-tracking'
                ),
                unknown.join( ', ' )
            )
        );
    }

    if ( placement !== 'thankyou' && order.length ) {
        hints.push(
            sprintf(
                /* translators: %s: placeholders, like {order_total}. */
                __(
                    '%s stays empty here. Order placeholders fill in only on the thank-you page.',
                    'woocommerce-conversion-tracking'
                ),
                order.join( ', ' )
            )
        );
    }

    if ( placement !== 'thankyou' && customer.length ) {
        hints.push(
            sprintf(
                /* translators: %s: placeholders, like {customer_email}. */
                __(
                    '%s stays empty here. Customer placeholders fill in only on the thank-you page.',
                    'woocommerce-conversion-tracking'
                ),
                customer.join( ', ' )
            )
        );
    }

    if ( placement === 'thankyou' && customer.length && ! customerDetails ) {
        hints.push(
            sprintf(
                /* translators: %s: placeholders, like {customer_email}. */
                __(
                    '%s stays empty while "Include customer details in scripts" is off.',
                    'woocommerce-conversion-tracking'
                ),
                customer.join( ', ' )
            )
        );
    }

    return hints;
};

/**
 * A value with text put in place of its selection.
 *
 * @param {string} value Current value.
 * @param {number} start Selection start.
 * @param {number} end   Selection end.
 * @param {string} text  Text to insert.
 * @return {Object} The new value and the caret after the inserted text.
 */
export const insertAt = (
    value: string,
    start: number,
    end: number,
    text: string
): { value: string; caret: number } => {
    const from = Math.max( 0, Math.min( start, value.length ) );
    const to = Math.max( from, Math.min( end, value.length ) );

    return {
        value: value.slice( 0, from ) + text + value.slice( to ),
        caret: from + text.length,
    };
};
