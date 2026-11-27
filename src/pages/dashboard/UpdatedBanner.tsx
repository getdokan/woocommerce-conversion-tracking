/**
 * External dependencies
 */
import { Button, Notice } from '@wedevs/plugin-ui';

/**
 * WordPress dependencies
 */
import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import {
    fetchWhatsNew,
    hasWhatsNew,
    type WhatsNewData,
} from '../../data/whatsNew';

/**
 * "Updated to 3.0" with "See what's new", until What's new is closed with "Done, show me the dashboard".
 */
export const UpdatedBanner = () => {
    const available = hasWhatsNew();
    const [ data, setData ] = useState< WhatsNewData | null >( null );

    useEffect( () => {
        let live = true;

        if ( available ) {
            // A failed read shows no banner; What's new stays one click away in the Help menu.
            fetchWhatsNew()
                .then( ( next ) => live && setData( next ) )
                .catch( () => {} );
        }

        return () => {
            live = false;
        };
    }, [ available ] );

    if ( ! data || data.dismissed ) {
        return null;
    }

    const count = data.attention.length;

    return (
        <Notice
            variant="info"
            role="region"
            aria-label={ __(
                'Updated to 3.0',
                'woocommerce-conversion-tracking'
            ) }
            className="flex flex-row flex-wrap items-center gap-4"
            data-testid="wcct-updated-banner"
        >
            <p className="m-0 min-w-0 flex-1 text-sm text-foreground">
                <b>
                    { __(
                        'Updated to 3.0.',
                        'woocommerce-conversion-tracking'
                    ) }
                </b>{ ' ' }
                { __(
                    'Your settings carried over. Three fixes now apply to every store: purchases counted once, failed orders skipped, checkout tracked on the Checkout block.',
                    'woocommerce-conversion-tracking'
                ) }
                { count > 0 &&
                    ' ' +
                        sprintf(
                            /* translators: %d: number of settings */
                            _n(
                                '%d setting needs a quick check.',
                                '%d settings need a quick check.',
                                count,
                                'woocommerce-conversion-tracking'
                            ),
                            count
                        ) }
            </p>
            <Button
                variant="outline"
                nativeButton={ false }
                className="shrink-0"
                // eslint-disable-next-line jsx-a11y/anchor-has-content, jsx-a11y/no-redundant-roles -- the button renders the label; Base UI would make it role=button.
                render={ <a href="#/whats-new" role="link" /> }
            >
                { __( 'See what’s new', 'woocommerce-conversion-tracking' ) }
            </Button>
        </Notice>
    );
};
