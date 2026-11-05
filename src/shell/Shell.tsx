/**
 * External dependencies
 */
import { Button, Notice, NoticeTitle } from '@wedevs/plugin-ui';
import { ThemeProvider } from '@wcct/components';

/**
 * WordPress dependencies
 */
import { createPortal, useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { useAppData } from '../data/AppData';
import { isKnownPlatform } from '../data/platforms';
import { replaceHash, routePath, useRoute, type Route } from '../router';
import Dashboard from '../pages/dashboard/Dashboard';
import Placeholder from '../pages/Placeholder';
import { pageInfo } from '../pages/titles';
import { AppTopBar } from './AppTopBar';
import { PageSkeleton } from './PageSkeleton';

// WordPress's own title, "Conversion Tracking ‹ Site — WordPress"; each page prefixes its name.
const BASE_TITLE = document.title;

/**
 * The page for a route; pages owned by later tasks are stubs.
 *
 * @param {Object} props       Component props.
 * @param {Route}  props.route Current route.
 */
const RoutePage = ( { route }: { route: Route } ) => {
    if ( route.name === 'dashboard' ) {
        return <Dashboard />;
    }

    return <Placeholder { ...pageInfo( route ) } />;
};

/**
 * Load error with a retry; blocking when there is nothing to show yet.
 *
 * @param {Object}   props         Component props.
 * @param {string}   props.message Error message.
 * @param {Function} props.onRetry Retry handler.
 * @param {string}   props.title   Notice title.
 * @param {string=}  props.role    ARIA role.
 */
const LoadError = ( {
    message,
    onRetry,
    title,
    role,
}: {
    message: string;
    onRetry: () => void;
    title: string;
    role: 'alert' | 'status';
} ) => (
    <Notice variant="destructive" role={ role } data-testid="wcct-load-error">
        <NoticeTitle>{ title }</NoticeTitle>
        <p className="m-0">{ message }</p>
        <div>
            <Button variant="outline" size="sm" onClick={ onRetry }>
                { __( 'Try again', 'woocommerce-conversion-tracking' ) }
            </Button>
        </div>
    </Notice>
);

/**
 * The app shell: the top bar above the admin notices, then the current page.
 */
export const Shell = () => {
    const route = useRoute();
    const { data, loading, error, refresh } = useAppData();
    const header = document.getElementById( 'wcct-topbar-root' );
    const path = routePath( route );
    const previousPath = useRef( path );
    const unknownPlatform =
        route.name === 'platforms' &&
        !! data &&
        ! isKnownPlatform( data, route.id );
    const { title } = pageInfo( route );

    useEffect( () => {
        if ( unknownPlatform ) {
            replaceHash( '#/dashboard' );
        }
    }, [ unknownPlatform ] );

    useEffect( () => {
        document.title = `${ title } ‹ ${ BASE_TITLE }`;
    }, [ title ] );

    // Entering a page from another one re-reads the settings and moves focus to its heading.
    useEffect( () => {
        if ( previousPath.current === path ) {
            return;
        }

        previousPath.current = path;
        refresh();
        document.querySelector< HTMLElement >( '#wcct-app-root h1' )?.focus();
    }, [ path, refresh ] );

    let page = <RoutePage route={ route } />;

    if ( loading || unknownPlatform ) {
        page = <PageSkeleton />;
    } else if ( ! data ) {
        page = (
            <div className="px-8 py-8">
                <LoadError
                    role="alert"
                    title={ __(
                        'Conversion Tracking could not load its settings.',
                        'woocommerce-conversion-tracking'
                    ) }
                    message={ error }
                    onRetry={ refresh }
                />
            </div>
        );
    } else if ( error ) {
        page = (
            <>
                <div className="px-8 pt-8">
                    <LoadError
                        role="status"
                        title={ __(
                            'Could not refresh your settings. What you see may be out of date.',
                            'woocommerce-conversion-tracking'
                        ) }
                        message={ error }
                        onRetry={ refresh }
                    />
                </div>
                { page }
            </>
        );
    }

    return (
        <>
            { header &&
                createPortal(
                    <ThemeProvider>
                        <AppTopBar route={ route } />
                    </ThemeProvider>,
                    header
                ) }
            { page }
        </>
    );
};
