/**
 * External dependencies
 */
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
    recharts,
} from '@wedevs/plugin-ui';

/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { Metrics } from '../../data/metrics';
import { CardSkeleton } from '../../shell/PageSkeleton';

const { Bar, BarChart, CartesianGrid, XAxis, YAxis } = recharts;

const chartConfig = () => ( {
    sent: {
        label: __(
            'Sent to every connected platform',
            'woocommerce-conversion-tracking'
        ),
        color: 'var(--primary)',
    },
    not_confirmed: {
        label: __(
            'Not confirmed on a platform',
            'woocommerce-conversion-tracking'
        ),
        color: 'var(--tone-warn-fg)',
    },
} );

/**
 * What the chart shows, for screen readers.
 *
 * @param {Metrics} metrics Metrics.
 */
const summary = ( metrics: Metrics ): string =>
    sprintf(
        /* translators: 1: purchases, 2: purchases sent to every platform, 3: purchases not confirmed */
        _n(
            'Bar chart: %1$d purchase in the last 24 hours; %2$d sent to every connected platform, %3$d not confirmed on a platform.',
            'Bar chart: %1$d purchases in the last 24 hours; %2$d sent to every connected platform, %3$d not confirmed on a platform.',
            metrics.purchases,
            'woocommerce-conversion-tracking'
        ),
        metrics.purchases,
        metrics.sent,
        metrics.not_confirmed
    );

/**
 * One legend entry.
 *
 * @param {Object}  props       Component props.
 * @param {string}  props.label Label.
 * @param {boolean} props.warn  The not confirmed colour.
 */
const Legend = ( { label, warn }: { label: string; warn?: boolean } ) => (
    <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
        <span
            aria-hidden="true"
            className={
                warn
                    ? 'size-2.5 rounded-[2px] bg-tone-warn-fg'
                    : 'size-2.5 rounded-[2px] bg-primary'
            }
        />
        { label }
    </span>
);

/**
 * "Purchases in the last 24 hours": sent and not confirmed per hour; No check-in orders aren't drawn.
 *
 * @param {Object}       props            Component props.
 * @param {Metrics|null} props.metrics    Metrics, null while they load or when they failed.
 * @param {boolean}      props.refreshing Show a skeleton.
 */
export const HourlyChart = ( {
    metrics,
    refreshing,
}: {
    metrics: Metrics | null;
    refreshing: boolean;
} ) => {
    const config = chartConfig();
    const last = ( metrics?.hourly.length ?? 0 ) - 1;
    let body;

    if ( refreshing || ! metrics ) {
        body = <CardSkeleton lines={ 3 } />;
    } else if ( ! metrics.purchases ) {
        body = (
            <div className="flex h-50 items-center justify-center rounded-lg border border-dashed border-border px-6 text-center text-sm text-muted-foreground">
                { __(
                    'Purchases show here, hour by hour, once your store tracks them.',
                    'woocommerce-conversion-tracking'
                ) }
            </div>
        );
    } else {
        body = (
            <>
                <div data-testid="wcct-hourly-chart">
                    <ChartContainer
                        config={ config }
                        className="aspect-auto h-50 w-full"
                        aria-hidden="true"
                    >
                        <BarChart
                            data={ metrics.hourly }
                            margin={ { top: 8, right: 0, bottom: 0, left: 0 } }
                        >
                            <CartesianGrid
                                vertical={ false }
                                strokeDasharray="3 3"
                            />
                            <XAxis
                                dataKey="label"
                                tickLine={ false }
                                axisLine={ false }
                                interval={ 0 }
                                tickFormatter={ (
                                    value: string,
                                    index: number
                                ) => {
                                    if ( index === last ) {
                                        return __(
                                            'Now',
                                            'woocommerce-conversion-tracking'
                                        );
                                    }

                                    return index % 4 === 0 ? value : '';
                                } }
                            />
                            <YAxis
                                hide
                                allowDecimals={ false }
                                domain={ [
                                    0,
                                    ( max: number ) => Math.max( 3, max ),
                                ] }
                            />
                            <ChartTooltip
                                cursor={ false }
                                content={ <ChartTooltipContent /> }
                            />
                            <Bar
                                dataKey="sent"
                                stackId="purchases"
                                fill="var(--color-sent)"
                                isAnimationActive={ false }
                            />
                            <Bar
                                dataKey="not_confirmed"
                                stackId="purchases"
                                fill="var(--color-not_confirmed)"
                                isAnimationActive={ false }
                            />
                        </BarChart>
                    </ChartContainer>
                    <table className="sr-only">
                        <caption>{ summary( metrics ) }</caption>
                        <thead>
                            <tr>
                                <th scope="col">
                                    { __(
                                        'Hour',
                                        'woocommerce-conversion-tracking'
                                    ) }
                                </th>
                                <th scope="col">
                                    { String( config.sent.label ) }
                                </th>
                                <th scope="col">
                                    { String( config.not_confirmed.label ) }
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            { metrics.hourly.map( ( hour ) => (
                                <tr key={ hour.start }>
                                    <th scope="row">{ hour.label }</th>
                                    <td>{ hour.sent }</td>
                                    <td>{ hour.not_confirmed }</td>
                                </tr>
                            ) ) }
                        </tbody>
                    </table>
                </div>
                <div className="flex flex-wrap gap-4">
                    <Legend label={ String( config.sent.label ) } />
                    <Legend
                        label={ String( config.not_confirmed.label ) }
                        warn
                    />
                </div>
            </>
        );
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle>
                    <h2 className="m-0 text-base font-medium">
                        { __(
                            'Purchases in the last 24 hours',
                            'woocommerce-conversion-tracking'
                        ) }
                    </h2>
                </CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-3">{ body }</CardContent>
        </Card>
    );
};
