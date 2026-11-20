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
 * @param {boolean}   dirty Whether the page has unsaved changes.
 * @param {Function=} keeps Receives the new hash; true when the page stays mounted and keeps the changes (the wizard's steps).
 */
export const useUnsavedChanges = (
    dirty: boolean,
    keeps?: ( hash: string ) => boolean
) => {
    const dirtyRef = useRef( dirty );
    const keepsRef = useRef( keeps );

    dirtyRef.current = dirty;
    keepsRef.current = keeps;

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
                    ! keepsRef.current?.( window.location.hash ) &&
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
