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
 * The platforms' statuses, fetched again whenever the settings change (page change, save, pause); null until the first answer.
 *
 * @param {unknown} settings The shared settings; a new object means they changed.
 */
export const usePlatformStatuses = (
    settings: unknown
): PlatformStatuses | null => {
    const [ statuses, setStatuses ] = useState< PlatformStatuses | null >(
        null
    );

    useEffect( () => {
        let live = true;

        if ( settings ) {
            fetchPlatformStatuses()
                .then( ( next ) => {
                    if ( live ) {
                        setStatuses( next );
                    }
                } )
                // The page keeps its own checks until a status arrives.
                .catch( () => {} );
        }

        return () => {
            live = false;
        };
    }, [ settings ] );

    return statuses;
};
