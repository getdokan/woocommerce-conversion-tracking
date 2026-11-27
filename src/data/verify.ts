/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { NAMESPACE } from './api';

// The wcct/v1/onboarding/verify state (VerifyController); test names the link without its token, platforms maps integration IDs to "library loaded".
export type VerifyState = {
    active: boolean;
    test: string;
    started_at: number;
    seen_at: number;
    platforms: Record< string, boolean >;
    // The open test's link and its age in seconds, to pick it up again after a reload.
    url: string;
    age: number;
};

export type VerifyStart = {
    url: string;
    test: string;
    started_at: number;
};

// sent: its library loaded; blocked: the page checked in without it; missing: not on the page at all.
export type PlatformCheck = 'sent' | 'blocked' | 'missing';

// The hint shows after a minute without a ping; polling stops after five.
export const HINT_AFTER = 60_000;
export const POLL_CAP = 300_000;

const POLL_FIRST = 1_500;
const POLL_MAX = 8_000;

/**
 * Wait before the next poll: 1.5 s, then half as long again each time, at most 8 s.
 *
 * @param {number} attempt Polls so far.
 */
export const pollDelay = ( attempt: number ): number =>
    Math.min( POLL_MAX, Math.round( POLL_FIRST * 1.5 ** attempt ) );

/**
 * Open a new test link; any earlier one stops working.
 */
export const startVerify = (): Promise< VerifyStart > =>
    apiFetch< VerifyStart >( {
        path: `${ NAMESPACE }/onboarding/verify`,
        method: 'POST',
    } );

/**
 * The open test; an empty PHP array comes back as [] and is read as no platforms.
 */
export const fetchVerify = async (): Promise< VerifyState > => {
    const state = await apiFetch< VerifyState >( {
        path: `${ NAMESPACE }/onboarding/verify`,
    } );

    return {
        ...state,
        platforms:
            state.platforms && ! Array.isArray( state.platforms )
                ? state.platforms
                : {},
    };
};

/**
 * What the ping said about one platform, from its integrations (Google is ga4 and adwords).
 *
 * @param {string[]} integrations Its integration IDs.
 * @param {Object}   seen         Integration ID => library loaded.
 */
export const platformCheck = (
    integrations: string[],
    seen: Record< string, boolean >
): PlatformCheck => {
    const reported = integrations.filter( ( id ) =>
        Object.prototype.hasOwnProperty.call( seen, id )
    );

    if ( ! reported.length ) {
        return 'missing';
    }

    return reported.some( ( id ) => seen[ id ] === true ) ? 'sent' : 'blocked';
};
