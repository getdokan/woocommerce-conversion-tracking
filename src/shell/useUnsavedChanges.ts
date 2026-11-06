/**
 * WordPress dependencies
 */
import { useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { setNavigationBlocker } from '../router';

/**
 * Asks before leaving a page with unsaved changes: reloads and closes (beforeunload) and in-app hash routes.
 *
 * @param {boolean} dirty Whether the page has unsaved changes.
 */
export const useUnsavedChanges = ( dirty: boolean ) => {
    const dirtyRef = useRef( dirty );

    dirtyRef.current = dirty;

    useEffect( () => {
        if ( ! dirty ) {
            return;
        }

        const warn = ( event: BeforeUnloadEvent ) => {
            event.preventDefault();
            event.returnValue = '';
        };

        window.addEventListener( 'beforeunload', warn );

        return () => window.removeEventListener( 'beforeunload', warn );
    }, [ dirty ] );

    useEffect(
        () =>
            setNavigationBlocker(
                () =>
                    dirtyRef.current &&
                    // eslint-disable-next-line no-alert -- a native confirm, like the beforeunload prompt.
                    ! window.confirm(
                        __(
                            'You have unsaved changes. Leave this page and lose them?',
                            'woocommerce-conversion-tracking'
                        )
                    )
            ),
        []
    );
};
