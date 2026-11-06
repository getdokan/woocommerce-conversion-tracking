/**
 * WordPress dependencies
 */
import { useEffect, useSyncExternalStore } from '@wordpress/element';

type RoutePath =
    | { name: 'dashboard' }
    | { name: 'platforms'; id: string }
    | { name: 'catalog' }
    | { name: 'log' }
    | { name: 'doctor' }
    | { name: 'settings'; section: string }
    | { name: 'whats-new' }
    | { name: 'onboarding' };

// `query` is the part after ?, kept for pages that read it (#/log?tab=not-confirmed).
export type Route = RoutePath & { query: string };

export const DEFAULT_PLATFORM = 'facebook';

const SIMPLE = [
    'dashboard',
    'catalog',
    'log',
    'doctor',
    'whats-new',
    'onboarding',
] as const;

/**
 * Split a location hash into its path and query.
 *
 * @param {string} hash Location hash, with or without the leading #.
 */
const splitHash = ( hash: string ): [ string, string ] => {
    const [ path, ...rest ] = hash.replace( /^#?\/?/, '' ).split( '?' );

    return [ path, rest.join( '?' ) ];
};

/**
 * The route path for a hash path, or null when nothing matches.
 *
 * @param {string} path Hash path without the leading #/.
 */
const parsePath = ( path: string ): RoutePath | null => {
    const parts = path.split( '/' ).filter( Boolean );
    const [ name = '', param = '' ] = parts;

    if ( parts.length > 2 ) {
        return null;
    }

    if ( ( SIMPLE as readonly string[] ).includes( name ) && ! param ) {
        return { name } as RoutePath;
    }

    if ( name === 'platforms' ) {
        return /^[a-z0-9_-]*$/.test( param )
            ? { name, id: param || DEFAULT_PLATFORM }
            : null;
    }

    if ( name === 'settings' ) {
        return /^[a-z0-9_-]*$/.test( param ) ? { name, section: param } : null;
    }

    return null;
};

/**
 * The route for a location hash, or null when nothing matches.
 *
 * @param {string} hash Location hash, with or without the leading #.
 */
export const parseHash = ( hash: string ): Route | null => {
    const [ path, query ] = splitHash( hash );
    const route = parsePath( path );

    return route ? { ...route, query } : null;
};

/**
 * The hash path for a route, without its query.
 *
 * @param {RoutePath} route Route.
 */
export const routePath = ( route: RoutePath ): string => {
    switch ( route.name ) {
        case 'platforms':
            return `#/platforms/${ route.id }`;
        case 'settings':
            return route.section
                ? `#/settings/${ route.section }`
                : '#/settings';
        default:
            return `#/${ route.name }`;
    }
};

/**
 * Swap the current hash without a new history entry.
 *
 * @param {string} hash New hash.
 */
export const replaceHash = ( hash: string ) => {
    if ( window.location.hash === hash ) {
        return;
    }

    window.history.replaceState( window.history.state, '', hash );
    window.dispatchEvent( new HashChangeEvent( 'hashchange' ) );
};

// Returns true to stay on the current route (see useUnsavedChanges).
type NavigationBlocker = () => boolean;

let blocker: NavigationBlocker | null = null;

/**
 * Let a page veto hash route changes, for example while it has unsaved changes.
 *
 * @param {NavigationBlocker} check Returns true to stay.
 * @return {Function} Removes the blocker.
 */
export const setNavigationBlocker = ( check: NavigationBlocker ) => {
    blocker = check;

    return () => {
        if ( blocker === check ) {
            blocker = null;
        }
    };
};

const listeners = new Set< () => void >();
let lastHash = '';

// One hashchange listener asks the blocker once, before any route subscriber re-renders.
const onHashChange = () => {
    const hash = window.location.hash;

    if ( hash !== lastHash && blocker?.() ) {
        window.history.replaceState(
            window.history.state,
            '',
            lastHash || window.location.pathname + window.location.search
        );

        return;
    }

    lastHash = hash;
    listeners.forEach( ( listener ) => listener() );
};

const subscribe = ( onChange: () => void ) => {
    if ( ! listeners.size ) {
        lastHash = window.location.hash;
        window.addEventListener( 'hashchange', onHashChange );
    }

    listeners.add( onChange );

    return () => {
        listeners.delete( onChange );

        if ( ! listeners.size ) {
            window.removeEventListener( 'hashchange', onHashChange );
        }
    };
};

const getHash = () => window.location.hash;

/**
 * The current route; unknown or empty hashes go to the dashboard.
 */
export const useRoute = (): Route => {
    const hash = useSyncExternalStore( subscribe, getHash );
    const parsed = parseHash( hash );
    const route = parsed ?? { name: 'dashboard', query: '' };
    const canonical = routePath( route );
    const [ path ] = splitHash( hash );

    // Only the path is canonicalised; a known route keeps its query.
    useEffect( () => {
        if ( `#/${ path }` !== canonical ) {
            replaceHash(
                route.query ? `${ canonical }?${ route.query }` : canonical
            );
        }
    }, [ path, canonical, route.query ] );

    return route;
};
