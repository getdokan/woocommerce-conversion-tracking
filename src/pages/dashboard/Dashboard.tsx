/**
 * External dependencies
 */
import {
    Button,
    Notice,
    ToggleGroup,
    ToggleGroupItem,
} from '@wedevs/plugin-ui';
import { Page } from '@wcct/components';
import { Stethoscope } from 'lucide-react';

/**
 * WordPress dependencies
 */
import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { useAppData } from '../../data/AppData';
import { errorMessage } from '../../data/api';
import { fetchDoctor, type DoctorReport } from '../../data/doctor';
import { fetchMetrics, type Metrics } from '../../data/metrics';
import { platformCards } from '../../data/platforms';
import { DoctorCard } from './DoctorCard';
import { HourlyChart } from './HourlyChart';
import { NextSteps } from './NextSteps';
import { NotConfirmedCard } from './NotConfirmedCard';
import {
    ConnectedPlatformCard,
    SetupPlatformCard,
    cardState,
} from './PlatformCard';
import { StatCards } from './StatCards';
import { UpdatedBanner } from './UpdatedBanner';
import { WelcomeCard } from './WelcomeCard';

type Filter = 'all' | 'working' | 'attention';

const filters = (): { value: Filter; label: string }[] => [
    { value: 'all', label: __( 'All', 'woocommerce-conversion-tracking' ) },
    {
        value: 'working',
        label: __( 'Working', 'woocommerce-conversion-tracking' ),
    },
    {
        value: 'attention',
        label: __( 'Needs attention', 'woocommerce-conversion-tracking' ),
    },
];

const GRID = 'grid grid-cols-3 gap-4 max-[1100px]:grid-cols-2';

/**
 * The Dashboard: the welcome state until a platform is connected, then the normal layout.
 */
const Dashboard = () => {
    const { data, refreshing } = useAppData();
    const [ filter, setFilter ] = useState< Filter >( 'all' );
    const [ doctor, setDoctor ] = useState< DoctorReport | null >( null );
    const [ doctorLoading, setDoctorLoading ] = useState( true );
    const [ metrics, setMetrics ] = useState< Metrics | null >( null );
    const [ metricsLoading, setMetricsLoading ] = useState( true );
    const [ metricsError, setMetricsError ] = useState( '' );

    // The last Doctor run; a failed read shows the not-run card.
    useEffect( () => {
        let live = true;

        fetchDoctor()
            .then( ( report ) => live && setDoctor( report ) )
            .catch( () => {} )
            .finally( () => live && setDoctorLoading( false ) );

        return () => {
            live = false;
        };
    }, [] );

    // The last 24 hours; cards keep their settings-only state when it fails.
    useEffect( () => {
        let live = true;

        fetchMetrics()
            .then( ( next ) => live && setMetrics( next ) )
            .catch(
                ( reason: unknown ) =>
                    live && setMetricsError( errorMessage( reason ) )
            )
            .finally( () => live && setMetricsLoading( false ) );

        return () => {
            live = false;
        };
    }, [] );

    if ( ! data ) {
        return null;
    }

    const cards = platformCards( data );
    const connected = cards.filter( ( card ) => card.connected );
    // A setup that was started and not finished keeps its checklist, even once a platform is connected.
    const setupOpen = [ 'in_progress', 'skipped' ].includes(
        data.onboarding.status
    );

    if ( ! connected.length ) {
        return (
            <Page
                title={ __( 'Dashboard', 'woocommerce-conversion-tracking' ) }
                description={ __(
                    'Is my tracking working? Last 24 hours.',
                    'woocommerce-conversion-tracking'
                ) }
            >
                <UpdatedBanner />
                <WelcomeCard onboarding={ data.onboarding } />
                <section
                    className="flex flex-col gap-3"
                    aria-labelledby="wcct-new-platforms"
                >
                    <h2
                        id="wcct-new-platforms"
                        className="m-0 text-sm font-semibold text-page-muted-foreground"
                    >
                        { __(
                            'Platforms you can connect',
                            'woocommerce-conversion-tracking'
                        ) }
                    </h2>
                    <div className={ GRID }>
                        { cards
                            .filter( ( card ) => card.core && ! card.locked )
                            .map( ( card ) => (
                                <SetupPlatformCard
                                    key={ card.id }
                                    platform={ card }
                                />
                            ) ) }
                    </div>
                </section>
            </Page>
        );
    }

    const notConnected = cards.filter( ( card ) => ! card.connected );
    const loadingMetrics = refreshing || metricsLoading;
    const metricOf = ( id: string ) =>
        metrics?.platforms.find( ( platform ) => platform.id === id );
    const shown = connected.filter( ( card ) => {
        const state = cardState( card, metricOf( card.id ) );

        return (
            filter === 'all' ||
            ( filter === 'working' && state === 'working' ) ||
            ( filter === 'attention' && state === 'attention' )
        );
    } );
    // "Doctor found something new" follows its switch under Settings > Alerts.
    const doctorNewOn = data.settings.alert_doctor_new !== false;

    return (
        <Page
            title={ __( 'Dashboard', 'woocommerce-conversion-tracking' ) }
            description={ __(
                'Is my tracking working? Last 24 hours.',
                'woocommerce-conversion-tracking'
            ) }
            actions={
                <Button
                    variant="outline"
                    nativeButton={ false }
                    // eslint-disable-next-line jsx-a11y/anchor-has-content -- the button renders the label inside.
                    render={ <a href="#/doctor" /> }
                >
                    <Stethoscope aria-hidden="true" />
                    { __(
                        'Run the Doctor',
                        'woocommerce-conversion-tracking'
                    ) }
                </Button>
            }
        >
            <div className="flex flex-col gap-5" data-testid="wcct-dashboard">
                <UpdatedBanner />
                { setupOpen && <WelcomeCard onboarding={ data.onboarding } /> }
                { metricsError && (
                    <Notice variant="destructive" role="alert">
                        <p className="m-0">
                            { __(
                                "The last 24 hours couldn't be loaded.",
                                'woocommerce-conversion-tracking'
                            ) }{ ' ' }
                            { metricsError }
                        </p>
                    </Notice>
                ) }
                <div className="flex flex-col gap-2">
                    <StatCards
                        refreshing={ loadingMetrics }
                        metrics={ metrics }
                        lastDoctorRun={
                            doctor?.ran_ago ?? metrics?.doctor.ran_ago ?? ''
                        }
                    />
                    { ! loadingMetrics && !! metrics?.no_checkin && (
                        <p
                            className="m-0 text-[13px] text-page-muted-foreground"
                            data-testid="wcct-no-checkin-note"
                        >
                            { sprintf(
                                /* translators: %d: number of orders */
                                _n(
                                    "%d order couldn't be checked: no thank-you page visit, so it isn't counted as not confirmed.",
                                    "%d orders couldn't be checked: no thank-you page visit, so they aren't counted as not confirmed.",
                                    metrics.no_checkin,
                                    'woocommerce-conversion-tracking'
                                ),
                                metrics.no_checkin
                            ) }{ ' ' }
                            <a
                                href="#/log"
                                className="font-medium text-primary underline underline-offset-3 hover:text-primary/80"
                            >
                                { __(
                                    'See it in the Activity log',
                                    'woocommerce-conversion-tracking'
                                ) }
                            </a>
                        </p>
                    ) }
                </div>

                { ! loadingMetrics && metrics && (
                    <NotConfirmedCard metrics={ metrics } />
                ) }

                <div className="grid grid-cols-[2fr_1fr] items-start gap-4 max-[1100px]:grid-cols-1">
                    <HourlyChart
                        metrics={ metrics }
                        refreshing={ loadingMetrics }
                    />

                    <DoctorCard
                        report={ doctor }
                        loading={ refreshing || doctorLoading }
                        showNew={ doctorNewOn }
                    />
                </div>

                <section
                    className="flex flex-col gap-3"
                    aria-labelledby="wcct-connected-title"
                >
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h2
                            id="wcct-connected-title"
                            className="m-0 text-sm font-semibold text-foreground"
                        >
                            { __(
                                'Connected',
                                'woocommerce-conversion-tracking'
                            ) }
                        </h2>
                        <ToggleGroup
                            spacing={ 1 }
                            value={ [ filter ] }
                            onValueChange={ ( value ) => {
                                // Clicking the pressed chip keeps it on.
                                if ( value[ 0 ] ) {
                                    setFilter( value[ 0 ] as Filter );
                                }
                            } }
                            aria-label={ __(
                                'Filter platforms',
                                'woocommerce-conversion-tracking'
                            ) }
                            className="h-9 rounded-lg bg-muted p-[3px]"
                        >
                            { filters().map( ( item ) => (
                                <ToggleGroupItem
                                    key={ item.value }
                                    value={ item.value }
                                    className="h-full rounded-md px-2 text-foreground/60 hover:bg-transparent aria-pressed:bg-background aria-pressed:text-foreground aria-pressed:shadow-sm"
                                >
                                    { item.label }
                                </ToggleGroupItem>
                            ) ) }
                        </ToggleGroup>
                    </div>
                    { shown.length ? (
                        <div className={ GRID }>
                            { shown.map( ( card ) => (
                                <ConnectedPlatformCard
                                    key={ card.id }
                                    platform={ card }
                                    refreshing={ loadingMetrics }
                                    metric={ metricOf( card.id ) }
                                />
                            ) ) }
                        </div>
                    ) : (
                        <p className="m-0 text-sm text-page-muted-foreground">
                            { __(
                                'No platforms in this state.',
                                'woocommerce-conversion-tracking'
                            ) }
                        </p>
                    ) }
                </section>

                { notConnected.length > 0 && (
                    <section
                        className="flex flex-col gap-3"
                        aria-labelledby="wcct-not-connected-title"
                    >
                        <h2
                            id="wcct-not-connected-title"
                            className="m-0 text-sm font-semibold text-page-muted-foreground"
                        >
                            { __(
                                'Not connected',
                                'woocommerce-conversion-tracking'
                            ) }
                        </h2>
                        <div className={ GRID }>
                            { notConnected.map( ( card ) => (
                                <SetupPlatformCard
                                    key={ card.id }
                                    platform={ card }
                                />
                            ) ) }
                        </div>
                    </section>
                ) }

                <NextSteps
                    steps={ metrics?.next_steps ?? [] }
                    refreshing={ loadingMetrics }
                />
            </div>
        </Page>
    );
};

export default Dashboard;
