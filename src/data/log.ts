/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { NAMESPACE } from './api';

// Statuses worked out on read by Log\Statuses; `paused` is per platform only.
export type LogStatus =
    | 'confirmed'
    | 'not_confirmed'
    | 'no_checkin'
    | 'pending'
    | 'waiting'
    | 'skipped'
    | 'paused';

export type LogTab = 'all' | 'tracked' | 'not-confirmed' | 'pending';

export const LOG_TABS: LogTab[] = [
    'all',
    'tracked',
    'not-confirmed',
    'pending',
];

export type LogPlatform = {
    // Settings platform ID; Google covers ga4 and adwords.
    id: string;
    name: string;
    integrations: string[];
    status: LogStatus;
    // timeout or hidden (not confirmed), consent, paused, order_status.
    cause: string;
    // Handed to the platform's script on the thank-you page.
    sent: boolean;
    checked_in_at: string | null;
    sent_at: string | null;
    sent_at_label: string;
    consent: string;
    event_id: string;
    // No check-in from this platform while others checked in: listed, never judged.
    unknown: boolean;
};

export type LogOrder = {
    id: number;
    number: string;
    // Formatted in the order currency, for example $200.00.
    amount: string;
    total: string;
    currency: string;
    order_status: string;
    order_status_label: string;
    status: LogStatus;
    // no_checkin: not_reached or not_reported; skipped: consent, paused or order_status.
    cause: string;
    tab: string;
    created_at: string | null;
    created_label: string;
    created_clock: string;
    paid_at: string | null;
    thankyou_at: string | null;
    thankyou_clock: string;
    // When a waiting result becomes final.
    settles_at: string | null;
    platforms: LogPlatform[];
};

export type LogPage = {
    orders: LogOrder[];
    counts: Record< LogTab, number >;
    total: number;
    pages: number;
    page: number;
    per_page: number;
    since: string;
    settle: number;
};

export const PER_PAGE = 20;

/**
 * One page of the last 24 hours of orders under a tab.
 *
 * @param {LogTab} tab  Tab.
 * @param {number} page Page.
 */
export const fetchLog = ( tab: LogTab, page = 1 ): Promise< LogPage > =>
    apiFetch< LogPage >( {
        path: `${ NAMESPACE }/log?tab=${ tab }&page=${ page }&per_page=${ PER_PAGE }`,
    } );

/**
 * A tab from a #/log?tab= query; All for anything else.
 *
 * @param {string} query Route query.
 */
export const tabFromQuery = ( query: string ): LogTab => {
    const tab = new URLSearchParams( query ).get( 'tab' ) ?? '';

    return ( LOG_TABS as string[] ).includes( tab ) ? ( tab as LogTab ) : 'all';
};
