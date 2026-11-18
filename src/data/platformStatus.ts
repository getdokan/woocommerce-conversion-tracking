/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { NAMESPACE } from './api';
import type { PlatformStatus } from './platformForm';

// Platform ID => its status as the Dashboard card shows it.
export type PlatformStatuses = Record< string, PlatformStatus >;

/**
 * Every platform's status (wcct/v1/settings/status).
 */
export const fetchPlatformStatuses = (): Promise< PlatformStatuses > =>
    apiFetch< { platforms: PlatformStatuses } >( {
        path: `${ NAMESPACE }/settings/status`,
    } ).then( ( response ) => response?.platforms ?? {} );

/**
 * The platforms' statuses for these settings: fetched again only when their content changes (a save, a pause, new values); null while that fetch runs or after it fails, so the page shows its own checks rather than an old status.
 *
 * @param {unknown} settings The shared settings.
 */
export const usePlatformStatuses = (
    settings: unknown
): PlatformStatuses | null => {
    const key = settings ? JSON.stringify( settings ) : '';
    const [ loaded, setLoaded ] = useState< {
        key: string;
        statuses: PlatformStatuses;
    } | null >( null );

    useEffect( () => {
        let live = true;

        if ( key ) {
            fetchPlatformStatuses()
                .then( ( statuses ) => {
                    if ( live ) {
                        setLoaded( { key, statuses } );
                    }
                } )
                .catch( () => {
                    if ( live ) {
                        setLoaded( null );
                    }
                } );
        }

        return () => {
            live = false;
        };
    }, [ key ] );

    // A status fetched for other settings is never shown.
    return loaded && loaded.key === key ? loaded.statuses : null;
};
