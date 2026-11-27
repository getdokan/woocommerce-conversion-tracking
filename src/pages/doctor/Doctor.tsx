/**
 * External dependencies
 */
import {
    Button,
    Card,
    Notice,
    NoticeTitle,
    Spinner,
    toast,
} from '@wedevs/plugin-ui';
import { Page, StatusPill } from '@wcct/components';
import {
    ChevronRight,
    CircleCheck,
    LoaderCircle,
    RefreshCw,
    Stethoscope,
} from 'lucide-react';
import type { ReactNode } from 'react';

/**
 * WordPress dependencies
 */
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { errorMessage } from '../../data/api';
import {
    dismissFinding,
    fetchDoctor,
    markDoctorSeen,
    openProblems,
    runDoctor,
    type DoctorReport,
    type Finding,
} from '../../data/doctor';
import { CardSkeleton } from '../../shell/PageSkeleton';
import { pageInfo } from '../titles';
import { FindingCard } from './FindingCard';

const SECTION_LABEL = 'm-0 p-0 text-sm font-semibold text-foreground';

/**
 * "We checked N things."
 *
 * @param {number} count Checks that ran.
 */
const checkedText = ( count: number ) =>
    sprintf(
        /* translators: %d: number of checks */
        _n(
            'We checked %d thing.',
            'We checked %d things.',
            count,
            'woocommerce-conversion-tracking'
        ),
        count
    );

/**
 * The line under the summary title.
 *
 * @param {Finding[]} problems  Open findings worth a look.
 * @param {boolean}   dismissed Some were dismissed.
 */
const summaryText = ( problems: Finding[], dismissed: boolean ): string => {
    const sure = problems.filter(
        ( finding ) => finding.confidence === 'sure'
    ).length;
    const unsure = problems.length - sure;
    if ( sure && ! unsure ) {
        return sprintf(
            /* translators: %d: number of findings */
            _n(
                '%d is worth a look.',
                '%d are worth a look.',
                sure,
                'woocommerce-conversion-tracking'
            ),
            sure
        );
    }

    if ( sure ) {
        const unsureText = sprintf(
            /* translators: %d: number of findings we can't be sure about */
            _n(
                '%d thing we can’t be sure about',
                '%d things we can’t be sure about',
                unsure,
                'woocommerce-conversion-tracking'
            ),
            unsure
        );
        const sureText = sprintf(
            /* translators: %d: number of findings */
            _n(
                '%d is worth a look',
                '%d are worth a look',
                sure,
                'woocommerce-conversion-tracking'
            ),
            sure
        );

        return sprintf(
            /* translators: 1: "2 are worth a look", 2: "1 thing we can't be sure about" */
            __( '%1$s and %2$s.', 'woocommerce-conversion-tracking' ),
            sureText,
            unsureText
        );
    }

    if ( unsure ) {
        return sprintf(
            /* translators: %d: number of findings we can't be sure about */
            _n(
                '%d thing we can’t be sure about.',
                '%d things we can’t be sure about.',
                unsure,
                'woocommerce-conversion-tracking'
            ),
            unsure
        );
    }

    return dismissed
        ? __(
              'You dismissed everything worth a look.',
              'woocommerce-conversion-tracking'
          )
        : __(
              'Nothing needs your attention.',
              'woocommerce-conversion-tracking'
          );
};

/**
 * A card with a round icon, a title and a line under it.
 *
 * @param {Object}    props          Component props.
 * @param {ReactNode} props.icon     Icon.
 * @param {string}    props.tone     Icon circle colours.
 * @param {ReactNode} props.title    Title.
 * @param {ReactNode} props.children The line under it.
 * @param {string=}   props.testId   Test ID.
 */
const SummaryCard = ( {
    icon,
    tone,
    title,
    children,
    testId,
}: {
    icon: ReactNode;
    tone: 'ok' | 'warn' | 'busy';
    title: ReactNode;
    children: ReactNode;
    testId?: string;
} ) => {
    const tones = {
        ok: 'bg-tone-ok-bg text-tone-ok-fg',
        warn: 'bg-tone-warn-bg text-tone-warn-fg',
        busy: 'bg-primary/10 text-primary',
    };

    return (
        <Card
            className="flex-row items-center gap-4 px-6 py-5"
            data-testid={ testId }
        >
            <span
                className={ `inline-flex size-13 shrink-0 items-center justify-center rounded-full [&>svg]:size-6 ${ tones[ tone ] }` }
                aria-hidden="true"
            >
                { icon }
            </span>
            <div className="flex flex-col gap-1">
                <h2
                    id={ testId ? `${ testId }-title` : undefined }
                    tabIndex={ -1 }
                    className="m-0 p-0 text-lg font-semibold text-foreground"
                >
                    { title }
                </h2>
                <p className="m-0 text-sm text-page-muted-foreground">
                    { children }
                </p>
            </div>
        </Card>
    );
};

/**
 * The collapsed list of checks that passed, and the ones that couldn't run.
 *
 * @param {Object}       props        Component props.
 * @param {DoctorReport} props.report Report.
 */
const WorkingList = ( { report }: { report: DoctorReport } ) => {
    if ( ! report.working.length && ! report.skipped.length ) {
        return null;
    }

    return (
        <Card className="gap-0 py-0" data-testid="wcct-doctor-working">
            <details className="group">
                <summary className="flex cursor-pointer list-none items-center gap-2.5 px-6 py-4 text-sm font-semibold text-foreground focus-visible:rounded-xl focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring [&::-webkit-details-marker]:hidden">
                    <ChevronRight
                        className="size-4 text-muted-foreground transition-transform group-open:rotate-90"
                        aria-hidden="true"
                    />
                    { __( 'Working', 'woocommerce-conversion-tracking' ) }
                    <StatusPill tone="ok" className="before:hidden">
                        { report.working.length }
                    </StatusPill>
                </summary>
                <ul className="m-0 list-none p-0">
                    { report.working.map( ( line ) => (
                        <li
                            key={ line.check }
                            className="m-0 flex items-center gap-3 border-t border-border px-6 py-3 text-sm"
                        >
                            <span
                                className="size-2 shrink-0 rounded-full bg-tone-ok-dot"
                                aria-hidden="true"
                            />
                            <span className="sr-only">
                                { __(
                                    'Passed:',
                                    'woocommerce-conversion-tracking'
                                ) }
                            </span>
                            { line.label }
                        </li>
                    ) ) }
                    { report.skipped.map( ( line ) => (
                        <li
                            key={ line.check }
                            className="m-0 flex items-center gap-3 border-t border-border px-6 py-3 text-sm"
                        >
                            <span
                                className="size-2 shrink-0 rounded-full bg-tone-off-dot"
                                aria-hidden="true"
                            />
                            <span className="sr-only">
                                { __(
                                    'Not checked:',
                                    'woocommerce-conversion-tracking'
                                ) }
                            </span>
                            { line.label }
                            <span className="ml-auto text-xs text-muted-foreground">
                                { line.reason }
                            </span>
                        </li>
                    ) ) }
                </ul>
            </details>
        </Card>
    );
};

/**
 * The Tracking Doctor: findings first, then the collapsed Working list.
 */
const Doctor = () => {
    const { title, description } = pageInfo( { name: 'doctor', query: '' } );
    const [ report, setReport ] = useState< DoctorReport | null >( null );
    const [ error, setError ] = useState( '' );
    const [ running, setRunning ] = useState( false );
    const [ checking, setChecking ] = useState( '' );
    const mounted = useRef( true );
    const busy = useRef( false );
    // What gets focus once the next report renders: 'summary' or a finding's hash (its Dismiss button).
    const focusNext = useRef( '' );

    const apply = useCallback( ( next: DoctorReport ) => {
        if ( mounted.current ) {
            setReport( next );
            setError( '' );
        }
    }, [] );

    const fail = useCallback( ( reason: unknown ) => {
        if ( mounted.current ) {
            setError( errorMessage( reason ) );
        }
    }, [] );

    const runAll = useCallback(
        ( focus = false ) => {
            // The button stays enabled (aria-busy) while a run is going; a second press does nothing.
            if ( busy.current ) {
                return;
            }

            busy.current = true;
            setRunning( true );

            runDoctor()
                .then( ( next ) => {
                    focusNext.current = focus ? 'summary' : '';
                    apply( next );
                    toast.success(
                        sprintf(
                            /* translators: %d: number of checks */
                            _n(
                                'Check finished. We checked %d thing.',
                                'Check finished. We checked %d things.',
                                next.checked,
                                'woocommerce-conversion-tracking'
                            ),
                            next.checked
                        )
                    );
                } )
                .catch( fail )
                .finally( () => {
                    busy.current = false;

                    if ( mounted.current ) {
                        setRunning( false );
                    }
                } );
        },
        [ apply, fail ]
    );

    const load = useCallback( () => {
        fetchDoctor()
            .then( ( next ) => {
                apply( next );

                // The first visit runs the checks; later ones show the last run.
                if ( ! next.ran_at ) {
                    runAll();
                } else if ( next.new ) {
                    // Clear the dashboard badge; this view keeps its "New" tags.
                    markDoctorSeen().catch( () => {} );
                }
            } )
            .catch( fail );
    }, [ apply, fail, runAll ] );

    useEffect( () => {
        mounted.current = true;
        load();

        return () => {
            mounted.current = false;
        };
    }, [ load ] );

    useEffect( () => {
        const target = focusNext.current;

        if ( running || ! target ) {
            return;
        }

        focusNext.current = '';
        (
            document.querySelector< HTMLElement >(
                target === 'summary'
                    ? '#wcct-doctor-summary-title'
                    : `[data-dismiss="${ target }"]`
            ) ??
            document.querySelector< HTMLElement >(
                '#wcct-doctor-summary-title'
            )
        )?.focus();
    }, [ report, running ] );

    const recheck = ( finding: Finding ) => {
        setChecking( finding.check );

        runDoctor( [ finding.check ] )
            .then( ( next ) => {
                apply( next );

                const still = next.findings.some(
                    ( item ) => item.check === finding.check && ! item.dismissed
                );

                toast.success(
                    still
                        ? __(
                              'Checked again. It’s still there.',
                              'woocommerce-conversion-tracking'
                          )
                        : __(
                              'Checked again. It’s fixed.',
                              'woocommerce-conversion-tracking'
                          )
                );
            } )
            .catch( ( reason ) => toast.error( errorMessage( reason ) ) )
            .finally( () => mounted.current && setChecking( '' ) );
    };

    const dismiss = ( finding: Finding ) => {
        // Focus moves to the next card's Dismiss, or to the summary after the last one.
        const shown = report
            ? report.findings.filter( ( item ) => ! item.dismissed )
            : [];
        const ordered = [
            ...shown.filter( ( item ) => item.severity === 'warning' ),
            ...shown.filter( ( item ) => item.severity === 'info' ),
        ];
        const index = ordered.findIndex(
            ( item ) => item.hash === finding.hash
        );

        dismissFinding( finding.hash )
            .then( ( next ) => {
                focusNext.current = ordered[ index + 1 ]?.hash ?? 'summary';
                apply( next );
                toast.success(
                    __(
                        'Dismissed. It won’t show again unless something changes.',
                        'woocommerce-conversion-tracking'
                    ),
                    {
                        duration: 10000,
                        action: {
                            label: __(
                                'Undo',
                                'woocommerce-conversion-tracking'
                            ),
                            onClick: () => {
                                dismissFinding( finding.hash, false )
                                    .then( apply )
                                    .catch( ( reason ) =>
                                        toast.error( errorMessage( reason ) )
                                    );
                            },
                        },
                    }
                );
            } )
            .catch( ( reason ) => toast.error( errorMessage( reason ) ) );
    };

    const actions = (
        <>
            { report?.ran_at_label && (
                <span
                    className="text-sm text-page-muted-foreground"
                    data-testid="wcct-doctor-last-check"
                >
                    { sprintf(
                        /* translators: %s: when, for example "today 10:50" */
                        __(
                            'Last check: %s',
                            'woocommerce-conversion-tracking'
                        ),
                        report.ran_at_label
                    ) }
                </span>
            ) }
            <Button
                onClick={ () => runAll( true ) }
                disabled={ ! report }
                aria-busy={ running }
            >
                { running ? (
                    <Spinner aria-hidden="true" />
                ) : (
                    <RefreshCw aria-hidden="true" />
                ) }
                { running
                    ? __( 'Checking…', 'woocommerce-conversion-tracking' )
                    : __(
                          'Check my store',
                          'woocommerce-conversion-tracking'
                      ) }
            </Button>
        </>
    );

    let body: ReactNode;

    if ( ! report ) {
        body = error ? (
            <Notice variant="destructive" role="alert">
                <NoticeTitle>
                    { __(
                        'Could not load the Doctor.',
                        'woocommerce-conversion-tracking'
                    ) }
                </NoticeTitle>
                <p className="m-0">{ error }</p>
                <div>
                    <Button variant="outline" size="sm" onClick={ load }>
                        { __( 'Try again', 'woocommerce-conversion-tracking' ) }
                    </Button>
                </div>
            </Notice>
        ) : (
            <Card className="px-6" role="status" aria-busy="true">
                <CardSkeleton lines={ 2 } />
            </Card>
        );
    } else if ( running ) {
        body = (
            <div data-testid="wcct-doctor-running">
                <SummaryCard
                    tone="busy"
                    icon={ <LoaderCircle className="animate-spin" /> }
                    title={ sprintf(
                        /* translators: %d: number of checks */
                        _n(
                            'Checking %d thing…',
                            'Checking %d things…',
                            report.total,
                            'woocommerce-conversion-tracking'
                        ),
                        report.total
                    ) }
                >
                    { __(
                        'Your active plugins and your saved settings. Nothing is changed.',
                        'woocommerce-conversion-tracking'
                    ) }
                </SummaryCard>
            </div>
        );
    } else if ( ! report.ran_at ) {
        // The first run hasn't finished (or failed): never claim everything is fine.
        body = (
            <div className="flex flex-col gap-4" data-testid="wcct-doctor">
                { error && (
                    <Notice variant="destructive" role="status">
                        <NoticeTitle>
                            { __(
                                'The check could not finish.',
                                'woocommerce-conversion-tracking'
                            ) }
                        </NoticeTitle>
                        <p className="m-0">{ error }</p>
                    </Notice>
                ) }
                <SummaryCard
                    testId="wcct-doctor-summary"
                    tone="warn"
                    icon={ <Stethoscope /> }
                    title={ __(
                        'Not checked yet',
                        'woocommerce-conversion-tracking'
                    ) }
                >
                    { __(
                        'Press Check my store to look for duplicate or broken tracking. It takes a few seconds and changes nothing.',
                        'woocommerce-conversion-tracking'
                    ) }
                </SummaryCard>
            </div>
        );
    } else {
        const problems = openProblems( report );
        const found = report.findings.some(
            ( finding ) => finding.severity === 'warning'
        );
        const dismissed = found && ! problems.length;
        const notes = report.findings.filter(
            ( finding ) => finding.severity === 'info' && ! finding.dismissed
        );
        const card = ( finding: Finding ) => (
            <FindingCard
                key={ finding.hash }
                finding={ finding }
                checking={ checking === finding.check }
                onRecheck={ recheck }
                onDismiss={ dismiss }
            />
        );

        body = (
            <div className="flex flex-col gap-4" data-testid="wcct-doctor">
                { error && (
                    <Notice variant="destructive" role="status">
                        <NoticeTitle>
                            { __(
                                'The check could not finish.',
                                'woocommerce-conversion-tracking'
                            ) }
                        </NoticeTitle>
                        <p className="m-0">{ error }</p>
                    </Notice>
                ) }
                { found ? (
                    <SummaryCard
                        testId="wcct-doctor-summary"
                        tone={
                            problems.some(
                                ( finding ) => finding.confidence === 'sure'
                            )
                                ? 'warn'
                                : 'ok'
                        }
                        icon={ <Stethoscope /> }
                        title={ checkedText( report.checked ) }
                    >
                        { summaryText( problems, dismissed ) }
                    </SummaryCard>
                ) : (
                    <SummaryCard
                        testId="wcct-doctor-summary"
                        tone="ok"
                        icon={ <CircleCheck /> }
                        title={ __(
                            'Everything looks good',
                            'woocommerce-conversion-tracking'
                        ) }
                    >
                        { sprintf(
                            /* translators: %d: number of checks */
                            _n(
                                'We checked %d thing. Nothing needs your attention.',
                                'We checked %d things. Nothing needs your attention.',
                                report.checked,
                                'woocommerce-conversion-tracking'
                            ),
                            report.checked
                        ) }
                    </SummaryCard>
                ) }

                { problems.length > 0 && (
                    <section
                        className="flex flex-col gap-4"
                        aria-labelledby="wcct-doctor-worth-a-look"
                    >
                        <h2
                            id="wcct-doctor-worth-a-look"
                            className={ SECTION_LABEL }
                        >
                            { __(
                                'Worth a look',
                                'woocommerce-conversion-tracking'
                            ) }
                        </h2>
                        { problems.map( card ) }
                    </section>
                ) }

                { dismissed && (
                    <SummaryCard
                        testId="wcct-doctor-all-dismissed"
                        tone="ok"
                        icon={ <CircleCheck /> }
                        title={ __(
                            'Nothing left to look at',
                            'woocommerce-conversion-tracking'
                        ) }
                    >
                        { __(
                            'Dismissed items come back only if something changes. Check again any time.',
                            'woocommerce-conversion-tracking'
                        ) }
                    </SummaryCard>
                ) }

                { notes.length > 0 && (
                    <section
                        className="flex flex-col gap-4"
                        aria-labelledby="wcct-doctor-good-to-know"
                    >
                        <h2
                            id="wcct-doctor-good-to-know"
                            className={ SECTION_LABEL }
                        >
                            { __(
                                'Good to know',
                                'woocommerce-conversion-tracking'
                            ) }
                        </h2>
                        { notes.map( card ) }
                    </section>
                ) }

                <WorkingList report={ report } />
            </div>
        );
    }

    return (
        <Page title={ title } description={ description } actions={ actions }>
            { /* Mounted all the time, so screen readers hear the run start. */ }
            <p role="status" className="sr-only" data-testid="wcct-doctor-live">
                { running && report
                    ? sprintf(
                          /* translators: %d: number of checks */
                          _n(
                              'Checking %d thing…',
                              'Checking %d things…',
                              report.total,
                              'woocommerce-conversion-tracking'
                          ),
                          report.total
                      )
                    : '' }
            </p>
            { body }
        </Page>
    );
};

export default Doctor;
