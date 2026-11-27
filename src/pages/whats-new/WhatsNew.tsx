/**
 * External dependencies
 */
import {
    Button,
    Card,
    Notice,
    NoticeTitle,
    Spinner,
    cn,
    toast,
} from '@wedevs/plugin-ui';
import { StatusPill } from '@wcct/components';
import {
    Activity,
    Check,
    CircleCheck,
    CirclePause,
    Cookie,
    Download,
    Stethoscope,
    TriangleAlert,
    UserX,
} from 'lucide-react';
import type { ReactNode } from 'react';

/**
 * WordPress dependencies
 */
import {
    createInterpolateElement,
    useCallback,
    useEffect,
    useRef,
    useState,
} from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { errorMessage } from '../../data/api';
import { isProOutdated, proUpdateUrl } from '../../data/plan';
import {
    confirmWhatsNewItem,
    dismissWhatsNew,
    downloadBackup,
    fetchWhatsNew,
    hasWhatsNew,
    markWhatsNewSeen,
    type WhatsNewData,
    type WhatsNewNote,
} from '../../data/whatsNew';
import { replaceHash } from '../../router';
import { CardSkeleton } from '../../shell/PageSkeleton';

const LINK_CLASS =
    'text-primary underline underline-offset-2 hover:text-primary/80';

type Tone = 'ok' | 'warn' | 'off';

const ICON_TONES: Record< Tone, string > = {
    ok: 'bg-tone-ok-bg text-tone-ok-fg',
    warn: 'bg-tone-warn-bg text-tone-warn-fg',
    off: 'bg-primary/8 text-primary',
};

/**
 * A row in a card: round icon, text, and an optional action on the right.
 *
 * @param {Object}    props          Component props.
 * @param {ReactNode} props.icon     Icon.
 * @param {Tone}      props.tone     Icon circle colours.
 * @param {ReactNode} props.children Text.
 * @param {ReactNode} props.action   Action.
 * @param {string=}   props.testId   Test ID.
 */
const StepRow = ( {
    icon,
    tone,
    children,
    action,
    testId,
}: {
    icon: ReactNode;
    tone: Tone;
    children: ReactNode;
    action?: ReactNode;
    testId?: string;
} ) => (
    <div
        className="flex items-center gap-4 border-t border-border px-6 py-4 first:border-t-0"
        data-testid={ testId }
    >
        <span
            className={ cn(
                'inline-flex size-9 shrink-0 items-center justify-center rounded-full [&>svg]:size-4.5',
                ICON_TONES[ tone ]
            ) }
            aria-hidden="true"
        >
            { icon }
        </span>
        <div className="min-w-0 flex-1 text-sm text-foreground">
            { children }
        </div>
        { action }
    </div>
);

/**
 * An outline button that opens an app route.
 *
 * @param {Object} props       Component props.
 * @param {string} props.href  Route.
 * @param {string} props.label Label.
 */
const LinkButton = ( { href, label }: { href: string; label: string } ) => (
    <Button
        variant="outline"
        nativeButton={ false }
        className="shrink-0"
        // eslint-disable-next-line jsx-a11y/anchor-has-content, jsx-a11y/no-redundant-roles -- the button renders the label; Base UI would make it role=button.
        render={ <a href={ href } role="link" /> }
    >
        { label }
    </Button>
);

/**
 * A card's heading row.
 *
 * @param {Object}    props          Component props.
 * @param {string}    props.id       Heading ID.
 * @param {ReactNode} props.children Heading.
 */
const CardHead = ( { id, children }: { id: string; children: ReactNode } ) => (
    <h2
        id={ id }
        className="m-0 border-b border-border px-6 py-5 text-base font-medium text-foreground"
    >
        { children }
    </h2>
);

/**
 * A note's text, with its <link></link> as a link to its URL.
 *
 * @param {WhatsNewNote} note Note.
 */
const noteText = ( note: WhatsNewNote ): ReactNode => {
    try {
        return createInterpolateElement( note.text, {
            // eslint-disable-next-line jsx-a11y/anchor-has-content -- filled with the wrapped text.
            link: <a className={ LINK_CLASS } href={ note.url } />,
        } );
    } catch {
        return note.text.replace( /<\/?link>/g, '' );
    }
};

/**
 * "Pro 1.1.4 is installed but paused": old Pro is off until it's updated.
 *
 * @param {Object} props             Component props.
 * @param {number} props.extraPixels Extra Meta pixels 2.x Pro kept.
 */
const OldProBanner = ( { extraPixels }: { extraPixels: number } ) => {
    let back: string = __(
        'Update Pro to 3.0 to get your Pro features back.',
        'woocommerce-conversion-tracking'
    );

    if ( extraPixels === 1 ) {
        back = __(
            'Update Pro to 3.0 to get your second pixel and other Pro features back.',
            'woocommerce-conversion-tracking'
        );
    } else if ( extraPixels > 1 ) {
        back = __(
            'Update Pro to 3.0 to get your extra pixels and other Pro features back.',
            'woocommerce-conversion-tracking'
        );
    }

    return (
        <Notice
            variant="warning"
            role="region"
            aria-label={ __(
                'Action needed',
                'woocommerce-conversion-tracking'
            ) }
            className="flex flex-row flex-wrap items-center gap-4"
            data-testid="wcct-whats-new-old-pro"
        >
            <div className="flex min-w-0 flex-1 items-start gap-3">
                <StatusPill tone="warn" className="shrink-0">
                    { __( 'Action needed', 'woocommerce-conversion-tracking' ) }
                </StatusPill>
                <p className="m-0 text-sm text-foreground">
                    <b>
                        { sprintf(
                            /* translators: %s: installed Pro version, for example 1.1.4 */
                            __(
                                'Conversion Tracking Pro %s is installed but paused.',
                                'woocommerce-conversion-tracking'
                            ),
                            window.wcctAdmin?.proVersion ?? ''
                        ) }
                    </b>{ ' ' }
                    { createInterpolateElement(
                        __(
                            'It was built for the old version. Your standard tracking keeps running from the carried-over settings, and your <link>Meta catalog feed</link> keeps serving.',
                            'woocommerce-conversion-tracking'
                        ),
                        {
                            link: (
                                // eslint-disable-next-line jsx-a11y/anchor-has-content -- filled with the wrapped text.
                                <a className={ LINK_CLASS } href="#/catalog" />
                            ),
                        }
                    ) }{ ' ' }
                    { back }
                </p>
            </div>
            { proUpdateUrl() && (
                <Button
                    nativeButton={ false }
                    className="shrink-0"
                    render={
                        // eslint-disable-next-line jsx-a11y/anchor-has-content, jsx-a11y/no-redundant-roles -- the button renders the label; Base UI would make it role=button.
                        <a href={ proUpdateUrl() } role="link" />
                    }
                >
                    { __( 'Update Pro', 'woocommerce-conversion-tracking' ) }
                </Button>
            ) }
        </Notice>
    );
};

/**
 * The migration result: everything carried over, or one row per setting to check.
 *
 * @param {Object}   props            Component props.
 * @param {Object}   props.data       Page data.
 * @param {Function} props.onConfirm  "Looks right" on an item.
 * @param {string}   props.confirming Flag ID being confirmed.
 */
const MigrationCard = ( {
    data,
    onConfirm,
    confirming,
}: {
    data: WhatsNewData;
    onConfirm: ( id: string ) => void;
    confirming: string;
} ) => {
    if ( data.state === 'done' ) {
        return (
            <Card
                className="flex-row items-center gap-4 px-6 py-5"
                aria-labelledby="wcct-whats-new-done-title"
                data-testid="wcct-whats-new-done"
            >
                <span
                    className="inline-flex size-13 shrink-0 items-center justify-center rounded-full bg-tone-ok-bg text-tone-ok-fg [&>svg]:size-6"
                    aria-hidden="true"
                >
                    <CircleCheck />
                </span>
                <div className="flex flex-col gap-1">
                    <h2
                        id="wcct-whats-new-done-title"
                        tabIndex={ -1 }
                        className="m-0 p-0 text-lg font-semibold text-foreground"
                    >
                        { __(
                            'Everything carried over',
                            'woocommerce-conversion-tracking'
                        ) }
                    </h2>
                    <p className="m-0 text-sm text-page-muted-foreground">
                        { __(
                            'Your settings and your order history are on 3.0. Nothing needs your attention.',
                            'woocommerce-conversion-tracking'
                        ) }
                    </p>
                </div>
            </Card>
        );
    }

    const count = data.attention.length;

    return (
        <Card
            className="gap-0 py-0"
            aria-labelledby="wcct-whats-new-attention-title"
            data-testid="wcct-whats-new-attention"
        >
            <div className="flex flex-col items-start gap-2 border-b border-border px-6 py-5">
                <StatusPill tone="warn">
                    { __(
                        'Needs attention',
                        'woocommerce-conversion-tracking'
                    ) }
                </StatusPill>
                <h2
                    id="wcct-whats-new-attention-title"
                    tabIndex={ -1 }
                    className="m-0 p-0 text-base font-medium text-foreground"
                >
                    { sprintf(
                        /* translators: %d: number of settings */
                        _n(
                            '%d setting couldn’t carry over as it was',
                            '%d settings couldn’t carry over as they were',
                            count,
                            'woocommerce-conversion-tracking'
                        ),
                        count
                    ) }
                </h2>
                <p className="m-0 text-[13px] text-muted-foreground">
                    { __(
                        'Everything else carried over. Tracking keeps running while you check these.',
                        'woocommerce-conversion-tracking'
                    ) }
                </p>
            </div>
            { data.attention.map( ( item ) => (
                <StepRow
                    key={ item.id }
                    tone="warn"
                    icon={ <TriangleAlert /> }
                    testId={ `wcct-whats-new-item-${ item.id }` }
                    action={
                        <div className="flex shrink-0 flex-wrap items-center gap-2">
                            { item.confirmable && (
                                <Button
                                    variant="ghost"
                                    onClick={ () => onConfirm( item.id ) }
                                    disabled={ !! confirming }
                                    aria-busy={ confirming === item.id }
                                >
                                    { __(
                                        'Looks right',
                                        'woocommerce-conversion-tracking'
                                    ) }
                                </Button>
                            ) }
                            { item.url && item.action && (
                                <LinkButton
                                    href={ item.url }
                                    label={ item.action }
                                />
                            ) }
                        </div>
                    }
                >
                    <p className="m-0">
                        <b>{ item.title }</b> { item.text }
                    </p>
                </StepRow>
            ) ) }
        </Card>
    );
};

/**
 * Platforms 2.x had switched off: still paused, and why.
 *
 * @param {Object}       props      Component props.
 * @param {WhatsNewData} props.data Page data.
 */
const PausedCard = ( { data }: { data: WhatsNewData } ) => (
    <Card
        className="gap-0 py-0"
        aria-labelledby="wcct-whats-new-paused-title"
        data-testid="wcct-whats-new-paused"
    >
        <CardHead id="wcct-whats-new-paused-title">
            { __( 'Paused, as before', 'woocommerce-conversion-tracking' ) }
        </CardHead>
        { data.paused.map( ( item ) => (
            <StepRow
                key={ item.id }
                tone="off"
                icon={ <CirclePause /> }
                testId={ `wcct-whats-new-paused-${ item.id }` }
                action={
                    <LinkButton
                        href={ item.url }
                        label={ sprintf(
                            /* translators: %s: platform name */
                            __( 'Open %s', 'woocommerce-conversion-tracking' ),
                            item.label
                        ) }
                    />
                }
            >
                <p className="m-0">
                    <b>
                        { sprintf(
                            /* translators: %s: platform name */
                            __(
                                '%s is paused.',
                                'woocommerce-conversion-tracking'
                            ),
                            item.label
                        ) }
                    </b>{ ' ' }
                    { __(
                        'It was switched off before the update, so it still sends nothing. Its settings are kept; resume it when you want it back.',
                        'woocommerce-conversion-tracking'
                    ) }
                </p>
            </StepRow>
        ) ) }
    </Card>
);

/**
 * "Your previous settings": the JSON download and how to go back to 2.1.
 */
const BackupCard = () => {
    const [ busy, setBusy ] = useState( false );

    const download = () => {
        setBusy( true );

        downloadBackup()
            .then( ( name ) =>
                toast.success(
                    sprintf(
                        /* translators: %s: file name */
                        __(
                            'Downloaded %s.',
                            'woocommerce-conversion-tracking'
                        ),
                        name
                    )
                )
            )
            .catch( ( reason ) => toast.error( errorMessage( reason ) ) )
            .finally( () => setBusy( false ) );
    };

    return (
        <Card
            className="gap-0 py-0"
            aria-labelledby="wcct-whats-new-backup-title"
            data-testid="wcct-whats-new-backup"
        >
            <StepRow
                tone="off"
                icon={ <Download /> }
                action={
                    <Button
                        variant="outline"
                        className="shrink-0"
                        onClick={ download }
                        aria-busy={ busy }
                    >
                        { busy ? (
                            <Spinner aria-hidden="true" />
                        ) : (
                            <Download aria-hidden="true" />
                        ) }
                        { __(
                            'Download your previous settings (JSON)',
                            'woocommerce-conversion-tracking'
                        ) }
                    </Button>
                }
            >
                <h2
                    id="wcct-whats-new-backup-title"
                    className="m-0 p-0 text-lg font-semibold text-foreground"
                >
                    { __(
                        'Your previous settings',
                        'woocommerce-conversion-tracking'
                    ) }
                </h2>
                <p className="m-0 text-[13px] text-muted-foreground">
                    { __(
                        'Saved before the update. Your old settings are also kept, so you can go back to the previous version if you need to. If you do, version 2.1 uses your settings from before the update: anything you change in 3.0 is lost.',
                        'woocommerce-conversion-tracking'
                    ) }
                </p>
            </StepRow>
        </Card>
    );
};

/**
 * The three fixes every store gets.
 */
const FixedCard = () => {
    const fixes = [
        {
            id: 'once',
            title: __(
                'Purchases are counted once.',
                'woocommerce-conversion-tracking'
            ),
            text: __(
                'Reloading the thank-you page no longer sends a second purchase to Meta, Google Ads, X or Microsoft.',
                'woocommerce-conversion-tracking'
            ),
        },
        {
            id: 'failed',
            title: __(
                'Failed and cancelled orders are skipped.',
                'woocommerce-conversion-tracking'
            ),
            text: __(
                'They used to count as sales on every platform except Google Analytics.',
                'woocommerce-conversion-tracking'
            ),
        },
        {
            id: 'block-checkout',
            title: __(
                'Checkout is tracked on the Checkout block.',
                'woocommerce-conversion-tracking'
            ),
            text: __(
                'Meta and Microsoft now see "started checkout" on block-based checkouts.',
                'woocommerce-conversion-tracking'
            ),
        },
    ];

    return (
        <Card
            className="gap-0 py-0"
            aria-labelledby="wcct-whats-new-fixed-title"
            data-testid="wcct-whats-new-fixed"
        >
            <CardHead id="wcct-whats-new-fixed-title">
                { __(
                    'Fixed for every store',
                    'woocommerce-conversion-tracking'
                ) }
            </CardHead>
            { fixes.map( ( fix ) => (
                <StepRow key={ fix.id } tone="ok" icon={ <Check /> }>
                    <p className="m-0">
                        <b>{ fix.title }</b> { fix.text }
                    </p>
                </StepRow>
            ) ) }
        </Card>
    );
};

type TryRow = {
    id: string;
    icon: ReactNode;
    title: string;
    text: string;
    href: string;
    label: string;
};

/**
 * Optional things to try; cookie choices only while they're off.
 *
 * @param {Object}  props         Component props.
 * @param {boolean} props.consent "Respect cookie choices" is on.
 */
const TryCard = ( { consent }: { consent: boolean } ) => {
    const rows: TryRow[] = [
        {
            id: 'doctor',
            icon: <Stethoscope />,
            title: __( 'Tracking Doctor.', 'woocommerce-conversion-tracking' ),
            text: __(
                'Finds duplicate or broken tracking and says what to do. Optional.',
                'woocommerce-conversion-tracking'
            ),
            href: '#/doctor',
            label: __( 'Start', 'woocommerce-conversion-tracking' ),
        },
        {
            id: 'log',
            icon: <Activity />,
            title: __( 'Activity log.', 'woocommerce-conversion-tracking' ),
            text: __(
                'Every event your store sent, by order number. Already recording.',
                'woocommerce-conversion-tracking'
            ),
            href: '#/log',
            label: __( 'Open', 'woocommerce-conversion-tracking' ),
        },
        {
            id: 'skip-roles',
            icon: <UserX />,
            title: __(
                'Skip your own visits.',
                'woocommerce-conversion-tracking'
            ),
            text: __(
                "Your store tracked every visitor before, so nothing changed. New stores skip administrators and shop managers; turn it on in each platform's Advanced settings.",
                'woocommerce-conversion-tracking'
            ),
            href: '#/platforms/facebook?field=skip_roles',
            label: __( 'Review', 'woocommerce-conversion-tracking' ),
        },
    ];

    if ( ! consent ) {
        rows.push( {
            id: 'consent',
            icon: <Cookie />,
            title: __(
                'Respect cookie choices.',
                'woocommerce-conversion-tracking'
            ),
            text: __(
                "Off for existing stores so your numbers don't shift overnight. Turn it on if you sell to Europe.",
                'woocommerce-conversion-tracking'
            ),
            href: '#/platforms',
            label: __( 'Review', 'woocommerce-conversion-tracking' ),
        } );
    }

    return (
        <Card
            className="gap-0 py-0"
            aria-labelledby="wcct-whats-new-try-title"
            data-testid="wcct-whats-new-try"
        >
            <CardHead id="wcct-whats-new-try-title">
                { __(
                    'Try when you have a minute',
                    'woocommerce-conversion-tracking'
                ) }
            </CardHead>
            { rows.map( ( row ) => (
                <StepRow
                    key={ row.id }
                    tone="off"
                    icon={ row.icon }
                    testId={ `wcct-whats-new-try-${ row.id }` }
                    action={
                        <LinkButton href={ row.href } label={ row.label } />
                    }
                >
                    <p className="m-0">
                        <b>{ row.title }</b> { row.text }
                    </p>
                </StepRow>
            ) ) }
        </Card>
    );
};

/**
 * What's new in 3.0, for stores upgraded from 2.x.
 */
const WhatsNew = () => {
    const available = hasWhatsNew();
    const [ data, setData ] = useState< WhatsNewData | null >( null );
    const [ error, setError ] = useState( '' );
    const [ leaving, setLeaving ] = useState( false );
    const [ confirming, setConfirming ] = useState( '' );
    const mounted = useRef( true );

    const load = useCallback( () => {
        setError( '' );

        fetchWhatsNew()
            .then( ( next ) => {
                if ( ! mounted.current ) {
                    return;
                }

                setData( next );

                // Opened on its own: the one-time redirect isn't needed any more.
                if ( ! next.seen ) {
                    markWhatsNewSeen().catch( () => {} );
                }
            } )
            .catch( ( reason: { data?: { status?: number } } ) => {
                if ( ! mounted.current ) {
                    return;
                }

                if ( reason?.data?.status === 404 ) {
                    replaceHash( '#/dashboard' );
                    return;
                }

                setError( errorMessage( reason ) );
            } );
    }, [] );

    useEffect( () => {
        mounted.current = true;

        // A store that started on 3.0 has nothing here.
        if ( ! available ) {
            replaceHash( '#/dashboard' );
        } else {
            load();
        }

        return () => {
            mounted.current = false;
        };
    }, [ available, load ] );

    // Leaves for the dashboard only once the banner is really dismissed.
    const done = () => {
        setLeaving( true );

        dismissWhatsNew()
            .then( () => {
                window.location.hash = '#/dashboard';
            } )
            .catch( ( reason ) => {
                toast.error( errorMessage( reason ) );

                if ( mounted.current ) {
                    setLeaving( false );
                }
            } );
    };

    const confirm = ( id: string ) => {
        setConfirming( id );

        confirmWhatsNewItem( id )
            .then( ( next ) => {
                if ( ! mounted.current ) {
                    return;
                }

                setData( next );
                toast.success(
                    __(
                        'Marked as checked.',
                        'woocommerce-conversion-tracking'
                    )
                );
                // The row is gone: focus moves to the card's heading.
                window.requestAnimationFrame(
                    () =>
                        document
                            .querySelector< HTMLElement >(
                                '#wcct-whats-new-attention-title, #wcct-whats-new-done-title'
                            )
                            ?.focus()
                );
            } )
            .catch( ( reason ) => toast.error( errorMessage( reason ) ) )
            .finally( () => mounted.current && setConfirming( '' ) );
    };

    if ( ! available ) {
        return null;
    }

    const outdated = isProOutdated();
    let body: ReactNode;

    if ( ! data ) {
        body = error ? (
            <Notice variant="destructive" role="alert">
                <NoticeTitle>
                    { __(
                        'Could not load what’s new.',
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
                <CardSkeleton lines={ 3 } />
            </Card>
        );
    } else {
        body = (
            <>
                { outdated && (
                    <OldProBanner extraPixels={ data.extra_pixels } />
                ) }
                { ! outdated &&
                    data.notes.map( ( note ) => (
                        <Notice
                            key={ note.id }
                            variant="info"
                            role="note"
                            data-testid={ `wcct-whats-new-note-${ note.id }` }
                        >
                            <p className="m-0 text-sm text-foreground">
                                { note.title && <b>{ note.title }</b> }
                                { note.title && note.text ? ' ' : '' }
                                { noteText( note ) }
                            </p>
                        </Notice>
                    ) ) }
                <MigrationCard
                    data={ data }
                    onConfirm={ confirm }
                    confirming={ confirming }
                />
                { data.paused.length > 0 && <PausedCard data={ data } /> }
                { data.backup && <BackupCard /> }
                <FixedCard />
                <TryCard consent={ data.consent } />
                <div className="flex justify-end">
                    <Button
                        size="lg"
                        onClick={ done }
                        disabled={ leaving }
                        aria-busy={ leaving }
                        data-testid="wcct-whats-new-done-button"
                    >
                        { __(
                            'Done, show me the dashboard',
                            'woocommerce-conversion-tracking'
                        ) }
                    </Button>
                </div>
            </>
        );
    }

    return (
        <div
            className="flex flex-col gap-6 px-8 py-8"
            data-testid="wcct-whats-new"
        >
            <div className="flex flex-col items-start gap-2">
                <StatusPill tone="ok">
                    { __(
                        'Updated to 3.0',
                        'woocommerce-conversion-tracking'
                    ) }
                </StatusPill>
                { /* Focused after a route change, so screen readers hear the new page. */ }
                <h1
                    tabIndex={ -1 }
                    className="m-0 p-0 text-[28px] leading-tight font-bold text-foreground"
                >
                    { __(
                        'Your tracking is still running. Here’s what changed.',
                        'woocommerce-conversion-tracking'
                    ) }
                </h1>
                <p className="m-0 text-base text-page-muted-foreground">
                    { __(
                        'Every pixel ID, event choice and custom script carried over as it was. Nothing new is sent until you turn it on. Three fixes apply to everyone because they were bugs.',
                        'woocommerce-conversion-tracking'
                    ) }
                </p>
            </div>
            { body }
        </div>
    );
};

export default WhatsNew;
