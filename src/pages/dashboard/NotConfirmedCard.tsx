/**
 * External dependencies
 */
import { Button, Notice } from '@wedevs/plugin-ui';
import { StatusPill } from '@wcct/components';

/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { Metrics } from '../../data/metrics';

// Order numbers named in the card; the rest are counted.
const MAX_NAMED = 5;

/**
 * "A", "A and B", "A, B and C".
 *
 * @param {string[]} items Items.
 */
const listOf = ( items: string[] ): string => {
    if ( items.length < 2 ) {
        return items[ 0 ] ?? '';
    }

    const head = items.slice( 1, -1 ).reduce(
        ( list, item ) =>
            sprintf(
                /* translators: 1: items so far, 2: the next item */
                __( '%1$s, %2$s', 'woocommerce-conversion-tracking' ),
                list,
                item
            ),
        items[ 0 ]
    );

    return sprintf(
        /* translators: 1: comma-separated items, 2: the last item */
        __( '%1$s and %2$s', 'woocommerce-conversion-tracking' ),
        head,
        items[ items.length - 1 ]
    );
};

/**
 * The order numbers, the first few by name.
 *
 * @param {string[]} numbers Order numbers.
 * @param {number}   total   Not confirmed orders in all.
 */
const orderList = ( numbers: string[], total: number ): string => {
    const named = numbers
        .slice( 0, MAX_NAMED )
        .map( ( number ) => `#${ number }` );
    const more = total - named.length;

    if ( more > 0 ) {
        named.push(
            sprintf(
                /* translators: %d: number of other orders */
                _n(
                    '%d more',
                    '%d more',
                    more,
                    'woocommerce-conversion-tracking'
                ),
                more
            )
        );
    }

    return listOf( named );
};

/**
 * The "Not confirmed" card: names the orders and platforms that didn't confirm in the last 24 hours, never a cause.
 *
 * @param {Object}  props         Component props.
 * @param {Metrics} props.metrics Metrics.
 */
export const NotConfirmedCard = ( { metrics }: { metrics: Metrics } ) => {
    const orders = metrics.not_confirmed_orders;

    if ( ! metrics.alerts.not_confirmed || ! orders.length ) {
        return null;
    }

    const total = Math.max( metrics.not_confirmed, orders.length );
    const ids = [
        ...new Set( orders.flatMap( ( order ) => order.platforms ) ),
    ];
    const names = ids.map(
        ( id ) =>
            metrics.platforms.find( ( platform ) => platform.id === id )
                ?.name ?? id
    );
    const numbers = orderList(
        orders.map( ( order ) => order.number ),
        total
    );

    const headline = sprintf(
        /* translators: 1: number of purchases, 2: platform names, for example Meta and X */
        _n(
            "We couldn't confirm that %1$d purchase was sent to %2$s in the last 24 hours.",
            "We couldn't confirm that %1$d purchases were sent to %2$s in the last 24 hours.",
            total,
            'woocommerce-conversion-tracking'
        ),
        total,
        listOf( names )
    );

    let detail;

    if ( names.length === 1 ) {
        detail = sprintf(
            /* translators: 1: "Order #1311" or "Orders #1311 and #1309", 2: platform name */
            __(
                "%1$s: the thank-you page checked in, but %2$s's script didn't confirm it ran.",
                'woocommerce-conversion-tracking'
            ),
            sprintf(
                /* translators: %s: order numbers, for example #1311 and #1309 */
                _n(
                    'Order %s',
                    'Orders %s',
                    total,
                    'woocommerce-conversion-tracking'
                ),
                numbers
            ),
            names[ 0 ]
        );
    } else {
        detail = sprintf(
            /* translators: %s: "Order #1311" or "Orders #1311 and #1309" */
            __(
                "%s: the thank-you page checked in, but a platform's script didn't confirm it ran.",
                'woocommerce-conversion-tracking'
            ),
            sprintf(
                /* translators: %s: order numbers, for example #1311 and #1309 */
                _n(
                    'Order %s',
                    'Orders %s',
                    total,
                    'woocommerce-conversion-tracking'
                ),
                numbers
            )
        );
    }

    return (
        <Notice
            variant="warning"
            role="region"
            aria-label={ __(
                'Not confirmed purchases',
                'woocommerce-conversion-tracking'
            ) }
            data-testid="wcct-not-confirmed-card"
            className="flex flex-col items-start gap-3"
        >
            <div className="flex flex-wrap items-start gap-3">
                <StatusPill tone="warn" className="shrink-0">
                    { __( 'Worth a look', 'woocommerce-conversion-tracking' ) }
                </StatusPill>
                <p className="m-0 min-w-0 flex-1 text-sm text-foreground">
                    <b className="font-semibold">{ headline }</b> { detail }{ ' ' }
                    { __(
                        "An ad blocker or a browser privacy setting often causes this, but we can't tell for sure. Shoppers who decline cookies aren't counted here, because respecting that choice is by design.",
                        'woocommerce-conversion-tracking'
                    ) }
                </p>
            </div>
            <div className="flex flex-wrap gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    nativeButton={ false }
                    // eslint-disable-next-line jsx-a11y/anchor-has-content -- the button renders the label inside.
                    render={ <a href="#/log?tab=not-confirmed" /> }
                >
                    { __(
                        'Open the orders in the Activity log',
                        'woocommerce-conversion-tracking'
                    ) }
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    nativeButton={ false }
                    // eslint-disable-next-line jsx-a11y/anchor-has-content -- the button renders the label inside.
                    render={ <a href="#/platforms?section=cookie-choices" /> }
                >
                    { __(
                        'Check cookie choices',
                        'woocommerce-conversion-tracking'
                    ) }
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    nativeButton={ false }
                    // eslint-disable-next-line jsx-a11y/anchor-has-content -- the button renders the label inside.
                    render={ <a href="#/doctor" /> }
                >
                    { __(
                        'Run the Doctor',
                        'woocommerce-conversion-tracking'
                    ) }
                </Button>
            </div>
        </Notice>
    );
};
