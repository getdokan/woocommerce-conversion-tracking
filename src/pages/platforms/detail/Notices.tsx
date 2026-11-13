/**
 * External dependencies
 */
import { Button, Notice, NoticeTitle } from '@wedevs/plugin-ui';
import { StatusPill } from '@wcct/components';

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { controlId, type EventOption } from '../../../data/platformForm';

/**
 * A list of event names, Purchase first, like "Purchase, Add to cart".
 *
 * @param {EventOption[]} events Events.
 */
const names = ( events: EventOption[] ): string =>
    [ ...events ]
        .sort(
            ( a, b ) =>
                Number( b.value === 'purchase' ) -
                Number( a.value === 'purchase' )
        )
        .map( ( event ) => event.label )
        .reduce( ( list, label ) =>
            sprintf(
                /* translators: 1: the list so far, 2: the next event name */
                __( '%1$s, %2$s', 'woocommerce-conversion-tracking' ),
                list,
                label
            )
        );

/**
 * Focus a field of the page and bring it into view.
 *
 * @param {string} id Control ID.
 */
const focusControl = ( id: string ) => {
    const element = document.getElementById( id );

    element?.scrollIntoView( { block: 'center' } );
    element?.focus();
};

/**
 * X upgraders: the events 2.x sent that need a new event ID.
 *
 * @param {Object}        props          Component props.
 * @param {string}        props.platform Platform ID.
 * @param {EventOption[]} props.events   Events still without an event ID.
 */
export const XResetupNotice = ( {
    platform,
    events,
}: {
    platform: string;
    events: EventOption[];
} ) => {
    const purchase = events.some( ( event ) => event.value === 'purchase' );

    return (
        <Notice
            variant="warning"
            role="status"
            aria-labelledby="wcct-x-resetup-title"
            data-testid="wcct-x-resetup"
        >
            <div>
                <StatusPill tone="warn">
                    { __(
                        'Needs a re-setup',
                        'woocommerce-conversion-tracking'
                    ) }
                </StatusPill>
            </div>
            <NoticeTitle id="wcct-x-resetup-title">
                { __(
                    'X retired the old single-event codes',
                    'woocommerce-conversion-tracking'
                ) }
            </NoticeTitle>
            <p className="m-0">
                { __(
                    "Your base tag carried over and still sends page views. These events now need their own event ID from X Ads Manager; until you add them, X doesn't see them. It takes about 2 minutes.",
                    'woocommerce-conversion-tracking'
                ) }
            </p>
            <p className="m-0" data-testid="wcct-x-resetup-events">
                { sprintf(
                    /* translators: %s: event names, like "Purchase, Add to cart" */
                    __(
                        'Set up again: %s.',
                        'woocommerce-conversion-tracking'
                    ),
                    names( events )
                ) }
            </p>
            <div>
                <Button
                    size="sm"
                    onClick={ () =>
                        focusControl(
                            controlId(
                                platform,
                                (
                                    events.find(
                                        ( event ) => event.value === 'purchase'
                                    ) ?? events[ 0 ]
                                ).needs
                            )
                        )
                    }
                >
                    { purchase
                        ? __(
                              'Set up the purchase event',
                              'woocommerce-conversion-tracking'
                          )
                        : __(
                              'Add the event IDs',
                              'woocommerce-conversion-tracking'
                          ) }
                </Button>
            </div>
        </Notice>
    );
};

/**
 * Google upgraders: the 2.x Ads ID held the conversion label, and the update split it.
 *
 * @param {Object} props       Component props.
 * @param {string} props.saved The 2.x value, like AW-123/AbC.
 */
export const GoogleSplitNotice = ( { saved }: { saved: string } ) => (
    <Notice
        variant="info"
        role="note"
        aria-labelledby="wcct-google-split-title"
        data-testid="wcct-google-split"
    >
        <NoticeTitle id="wcct-google-split-title">
            { __(
                'We split your Google Ads ID into a conversion ID and a label',
                'woocommerce-conversion-tracking'
            ) }
        </NoticeTitle>
        <p className="m-0">
            { saved
                ? sprintf(
                      /* translators: %s: the old Ads setting, like AW-10938215/AbC */
                      __(
                          'Your old setting held both in one field: %s. Check the two fields below look right.',
                          'woocommerce-conversion-tracking'
                      ),
                      saved
                  )
                : __(
                      'Your old setting held both in one field. Check the two fields below look right.',
                      'woocommerce-conversion-tracking'
                  ) }
        </p>
    </Notice>
);
