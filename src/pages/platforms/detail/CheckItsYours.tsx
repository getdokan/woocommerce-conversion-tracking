/**
 * WordPress dependencies
 */
import { createInterpolateElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { NewTabNote } from '../../../shell/NewTabNote';

type CheckLink = { field: string; url: string; text: string };

/**
 * The platform's own test tool for its main ID, or null: we check the format only, the platform knows whose ID it is.
 *
 * @param {string} platform Platform ID.
 */
const checkLink = ( platform: string ): CheckLink | null => {
    switch ( platform ) {
        case 'facebook':
            return {
                field: 'pixel_id',
                url: 'https://business.facebook.com/events_manager2/',
                text: __(
                    "<a>Check it's yours<n/></a> in Meta Events Manager, on the Test events tab.",
                    'woocommerce-conversion-tracking'
                ),
            };
        case 'google':
            return {
                field: 'measurement_id',
                url: 'https://analytics.google.com/',
                text: __(
                    "<a>Check it's yours<n/></a> in Google Analytics, under Realtime or DebugView.",
                    'woocommerce-conversion-tracking'
                ),
            };
        case 'bing_ads':
            return {
                field: 'tag_id',
                url: 'https://learn.microsoft.com/en-us/advertising/msa-help/hlp_ba_conc_uet_taghelper',
                text: __(
                    "<a>Check it's yours<n/></a> with Microsoft's UET Tag Helper.",
                    'woocommerce-conversion-tracking'
                ),
            };
        case 'twitter':
            return {
                field: 'pixel_id',
                url: 'https://ads.x.com/',
                text: __(
                    "<a>Check it's yours<n/></a> in X Events Manager.",
                    'woocommerce-conversion-tracking'
                ),
            };
        default:
            return null;
    }
};

type CheckItsYoursProps = {
    platform: string;
    // Field ID; the link shows under the platform's main ID only.
    field: string;
    // Control ID, for the test ID.
    id: string;
};

/**
 * "Check it's yours": a link to the platform's own test tool under an ID in the right format.
 *
 * @param {CheckItsYoursProps} props Component props.
 */
export const CheckItsYours = ( {
    platform,
    field,
    id,
}: CheckItsYoursProps ) => {
    const link = checkLink( platform );

    if ( ! link || link.field !== field ) {
        return null;
    }

    return (
        <p
            className="m-0 text-[13px] text-muted-foreground"
            data-testid={ `${ id }-verify` }
        >
            { createInterpolateElement( link.text, {
                a: (
                    // eslint-disable-next-line jsx-a11y/anchor-has-content -- createInterpolateElement fills it.
                    <a
                        href={ link.url }
                        target="_blank"
                        rel="noopener noreferrer"
                        className="font-medium text-primary underline underline-offset-3"
                    />
                ),
                n: <NewTabNote />,
            } ) }
        </p>
    );
};
