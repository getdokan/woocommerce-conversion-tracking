/**
 * External dependencies
 */
import { Switch, cn } from '@wedevs/plugin-ui';
import { ProBadge } from '@wcct/components';
import { Lock } from 'lucide-react';

/**
 * WordPress dependencies
 */
import { useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import {
    applyPreset,
    isEventOn,
    missingId,
    presetOf,
    switchEvent,
    timeAgo,
    type EventOption,
    type LastPurchase,
    type Preset,
} from '../../../data/platformForm';
import type { PlatformValues } from '../../../data/types';

type EventsTableProps = {
    platform: string;
    events: EventOption[];
    values: PlatformValues;
    lastPurchase: LastPurchase;
    // Saved and connected: Purchase can have a last seen time.
    connected: boolean;
    readOnly: boolean;
    // The Last seen column; extra Meta pixels leave it out, as check-ins are per platform.
    showSeen?: boolean;
    onChange: ( events: string[] ) => void;
};

const presetLabels = (): [ Preset, string ][] => [
    [ 'recommended', __( 'Recommended', 'woocommerce-conversion-tracking' ) ],
    [ 'all', __( 'All', 'woocommerce-conversion-tracking' ) ],
    [ 'custom', __( 'Custom', 'woocommerce-conversion-tracking' ) ],
];

/**
 * The platform's events: Recommended, All or Custom (derived from the switches), an On switch and Last seen per row.
 *
 * @param {EventsTableProps} props Component props.
 */
export const EventsTable = ( {
    platform,
    events,
    values,
    lastPurchase,
    connected,
    readOnly,
    showSeen = true,
    onChange,
}: EventsTableProps ) => {
    const group = useRef< HTMLDivElement >( null );
    const preset = presetOf( events, values );
    const count = events.filter( ( event ) =>
        isEventOn( event, values )
    ).length;
    const titleId = `wcct-${ platform }-events-title`;

    const seen = ( event: EventOption ): string => {
        if ( ! event.locked && missingId( event, values ) ) {
            return __(
                'Needs its event ID',
                'woocommerce-conversion-tracking'
            );
        }

        if ( ! isEventOn( event, values ) ) {
            return __( 'Off', 'woocommerce-conversion-tracking' );
        }

        // 3.0 hears back about purchases only, so only Purchase shows a time.
        if ( event.value !== 'purchase' ) {
            return __( 'On', 'woocommerce-conversion-tracking' );
        }

        return connected && lastPurchase
            ? timeAgo( lastPurchase.at, Math.floor( Date.now() / 1000 ) )
            : __( 'Not yet', 'woocommerce-conversion-tracking' );
    };

    return (
        <section
            className="flex flex-col gap-3"
            aria-labelledby={ titleId }
            data-testid={ `wcct-${ platform }-events` }
        >
            <div className="flex flex-wrap items-center gap-3">
                <h3
                    id={ titleId }
                    className="m-0 text-sm font-semibold text-foreground"
                >
                    { __( 'Events', 'woocommerce-conversion-tracking' ) }
                </h3>
                <span
                    className="text-[13px] text-muted-foreground"
                    aria-live="polite"
                    data-testid={ `wcct-${ platform }-events-count` }
                >
                    { sprintf(
                        /* translators: 1: events on, 2: events listed */
                        __(
                            '%1$d of %2$d on',
                            'woocommerce-conversion-tracking'
                        ),
                        count,
                        events.length
                    ) }
                </span>
                <div
                    ref={ group }
                    role="group"
                    aria-label={ __(
                        'Event preset',
                        'woocommerce-conversion-tracking'
                    ) }
                    className="ml-auto inline-flex gap-0.5 rounded-lg bg-muted p-0.5"
                    id={ `wcct-${ platform }-events` }
                    tabIndex={ -1 }
                >
                    { presetLabels().map( ( [ name, label ] ) => (
                        <button
                            key={ name }
                            type="button"
                            aria-pressed={ preset === name }
                            disabled={ readOnly }
                            onClick={ () => {
                                if ( name !== 'custom' ) {
                                    onChange(
                                        applyPreset(
                                            events,
                                            values.events,
                                            name
                                        )
                                    );
                                }
                            } }
                            className={ cn(
                                'cursor-pointer rounded-md border-0 bg-transparent px-3 py-1 text-[13px] font-medium text-muted-foreground hover:text-foreground focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none disabled:cursor-default',
                                preset === name &&
                                    'bg-background text-foreground shadow-sm'
                            ) }
                        >
                            { label }
                        </button>
                    ) ) }
                </div>
            </div>
            <div className="overflow-hidden rounded-lg border border-border">
                <table
                    className="w-full border-collapse text-sm"
                    aria-labelledby={ titleId }
                >
                    <thead>
                        <tr>
                            <th
                                scope="col"
                                className="px-4 py-2.5 text-left text-[11px] font-medium tracking-[0.04em] text-foreground uppercase"
                            >
                                { __(
                                    'Event',
                                    'woocommerce-conversion-tracking'
                                ) }
                            </th>
                            <th
                                scope="col"
                                className="w-24 px-4 py-2.5 text-left text-[11px] font-medium tracking-[0.04em] text-foreground uppercase"
                            >
                                { __(
                                    'On',
                                    'woocommerce-conversion-tracking'
                                ) }
                            </th>
                            { showSeen && (
                                <th
                                    scope="col"
                                    className="w-40 px-4 py-2.5 text-left text-[11px] font-medium tracking-[0.04em] whitespace-nowrap text-foreground uppercase"
                                >
                                    { __(
                                        'Last seen',
                                        'woocommerce-conversion-tracking'
                                    ) }
                                </th>
                            ) }
                        </tr>
                    </thead>
                    <tbody>
                        { events.map( ( event ) => {
                            const id = `wcct-${ platform }-event-${ event.value }`;
                            const noId = missingId( event, values );
                            const on = isEventOn( event, values );

                            let cell = (
                                <Switch
                                    id={ id }
                                    checked={ on }
                                    disabled={ readOnly || noId }
                                    onCheckedChange={ ( next ) =>
                                        onChange(
                                            switchEvent(
                                                events,
                                                values.events,
                                                event.value,
                                                next
                                            )
                                        )
                                    }
                                    aria-label={
                                        noId
                                            ? sprintf(
                                                  /* translators: %s: event name */
                                                  __(
                                                      'Send %s (add its event ID first)',
                                                      'woocommerce-conversion-tracking'
                                                  ),
                                                  event.label
                                              )
                                            : sprintf(
                                                  /* translators: %s: event name */
                                                  __(
                                                      'Send %s',
                                                      'woocommerce-conversion-tracking'
                                                  ),
                                                  event.label
                                              )
                                    }
                                />
                            );

                            if ( event.locked ) {
                                cell = (
                                    <span className="inline-flex text-muted-foreground">
                                        <Lock
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                        <span className="sr-only">
                                            { sprintf(
                                                /* translators: %s: event name */
                                                __(
                                                    '%s is a Pro event',
                                                    'woocommerce-conversion-tracking'
                                                ),
                                                event.label
                                            ) }
                                        </span>
                                    </span>
                                );
                            } else if ( event.always ) {
                                cell = (
                                    <span className="text-[13px] font-medium whitespace-nowrap text-tone-off-fg">
                                        <span aria-hidden="true">
                                            { __(
                                                'Always on',
                                                'woocommerce-conversion-tracking'
                                            ) }
                                        </span>
                                        <span className="sr-only">
                                            { sprintf(
                                                /* translators: %s: event name */
                                                __(
                                                    'Always on: %s is sent by the base tag.',
                                                    'woocommerce-conversion-tracking'
                                                ),
                                                event.label
                                            ) }
                                        </span>
                                    </span>
                                );
                            }

                            return (
                                <tr
                                    key={ event.value }
                                    className="border-t border-border"
                                    data-testid={ id }
                                >
                                    <th
                                        scope="row"
                                        className="px-4 py-3 text-left font-normal"
                                    >
                                        <span className="flex items-center gap-2 font-medium text-foreground">
                                            { event.label }
                                            { event.locked && <ProBadge /> }
                                        </span>
                                        { event.description && (
                                            <span className="block text-[13px] text-muted-foreground">
                                                { event.description }
                                            </span>
                                        ) }
                                    </th>
                                    <td className="px-4 py-3">{ cell }</td>
                                    { showSeen && (
                                        <td
                                            className={ cn(
                                                'px-4 py-3 text-[13px] whitespace-nowrap',
                                                event.value === 'purchase' &&
                                                    on &&
                                                    connected &&
                                                    lastPurchase
                                                    ? 'text-foreground'
                                                    : 'text-muted-foreground'
                                            ) }
                                            data-testid={ `${ id }-seen` }
                                        >
                                            { seen( event ) }
                                        </td>
                                    ) }
                                </tr>
                            );
                        } ) }
                    </tbody>
                </table>
            </div>
        </section>
    );
};
