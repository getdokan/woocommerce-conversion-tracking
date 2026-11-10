/**
 * External dependencies
 */
import type { ReactNode } from 'react';
import { Button, Notice, NoticeTitle, cn } from '@wedevs/plugin-ui';
import {
    CircleAlert,
    CircleCheck,
    Clock,
    ExternalLink,
    LoaderCircle,
} from 'lucide-react';

/**
 * WordPress dependencies
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { errorMessage } from '../../data/api';
import { listOf } from '../../data/onboarding';
import type { PlatformCardInfo } from '../../data/platforms';
import {
    HINT_AFTER,
    POLL_CAP,
    fetchVerify,
    platformCheck,
    pollDelay,
    startVerify,
} from '../../data/verify';
import { NewTabNote } from '../../shell/NewTabNote';
import { StepLayout } from './StepLayout';

export type VerifyRow = {
    platform: PlatformCardInfo;
    // Its integration IDs, as the ping names them.
    integrations: string[];
    // Paused platforms aren't on the storefront.
    paused: boolean;
    // A re-run's IDs that differ from the saved ones; the storefront still has the saved ones.
    pending: boolean;
};

export type VerifyStepProps = {
    rows: VerifyRow[];
    // Respect cookie choices is on for the storefront.
    consent: boolean;
    // A re-run doesn't hold Continue for the check.
    draft: boolean;
    onBack: () => void;
    onSkip: () => void;
    skipLabel?: string;
    onNext: () => void;
};

// idle: no test yet; starting: making the link; waiting, slow (after a minute): polling; stopped: gave up; replaced: a newer link from another window; seen: the ping arrived; failed: no link.
type Phase =
    | 'idle'
    | 'starting'
    | 'waiting'
    | 'slow'
    | 'stopped'
    | 'replaced'
    | 'seen'
    | 'failed';

type Tone = 'busy' | 'idle' | 'ok' | 'warn' | 'off';

const toneClass: Record< Tone, string > = {
    busy: 'bg-primary/10 text-primary',
    idle: 'bg-primary/10 text-primary',
    ok: 'bg-tone-ok-bg text-tone-ok-fg',
    warn: 'bg-tone-warn-bg text-tone-warn-fg',
    off: 'bg-tone-off-bg text-tone-off-fg',
};

const toneIcon = ( tone: Tone ): ReactNode => {
    switch ( tone ) {
        case 'ok':
            return <CircleCheck className="size-4.5" />;
        case 'warn':
            return <CircleAlert className="size-4.5" />;
        case 'off':
            return <Clock className="size-4.5" />;
        default:
            return (
                <LoaderCircle
                    className={ cn(
                        'size-4.5',
                        tone === 'busy' &&
                            'animate-spin motion-reduce:animate-none'
                    ) }
                />
            );
    }
};

/**
 * Step 5, "Check it works": opens the store through a test link and turns each platform green when the page checks in.
 *
 * @param {VerifyStepProps} props Component props.
 */
export const VerifyStep = ( {
    rows,
    consent,
    draft,
    onBack,
    onSkip,
    skipLabel,
    onNext,
}: VerifyStepProps ) => {
    const checkable = rows.filter( ( row ) => ! row.pending && ! row.paused );
    const [ phase, setPhase ] = useState< Phase >( 'idle' );
    const [ seen, setSeen ] = useState< Record< string, boolean > >( {} );
    const [ link, setLink ] = useState( '' );
    const [ error, setError ] = useState( '' );
    // Set after three polls in a row failed in this admin tab, not on the store.
    const [ pollError, setPollError ] = useState( '' );

    // Each test gets an ID; replies for an older one are dropped.
    const run = useRef( 0 );
    const timer = useRef< number >();
    // Checks at once, when the admin tab is shown again.
    const checkNow = useRef< ( () => void ) | null >( null );
    const rowsRef = useRef( rows );
    const openButton = useRef< HTMLButtonElement >( null );

    rowsRef.current = rows;

    const stop = () => {
        window.clearTimeout( timer.current );
        checkNow.current = null;
    };

    // Whether every checkable row is sent, so polling can stop.
    const allSent = ( platforms: Record< string, boolean > ) =>
        rowsRef.current.every(
            ( row ) =>
                row.pending ||
                row.paused ||
                platformCheck( row.integrations, platforms ) === 'sent'
        );

    // Poll with back-off until the ping arrives (and every platform is sent), or for at most POLL_CAP.
    const listen = (
        id: number,
        test: string,
        opened: number,
        heardBefore = false
    ) => {
        let attempt = 0;
        let busy = false;
        let failures = 0;
        // The ping arrived; later polls only look for warnings turning green.
        let heard = heardBefore;

        const next = () => {
            timer.current = window.setTimeout( check, pollDelay( attempt++ ) );
        };

        const settle = () => {
            const waited = Date.now() - opened;

            if ( waited >= POLL_CAP ) {
                setPhase( ( previous ) =>
                    previous === 'seen' ? previous : 'stopped'
                );
                stop();

                return;
            }

            if ( ! heard && waited >= HINT_AFTER ) {
                setPhase( 'slow' );
            }

            next();
        };

        function check() {
            if ( busy ) {
                return;
            }

            busy = true;
            window.clearTimeout( timer.current );

            fetchVerify()
                .then( ( state ) => {
                    busy = false;

                    if ( id !== run.current ) {
                        return;
                    }

                    failures = 0;
                    setPollError( '' );

                    if ( state.test !== test ) {
                        setPhase( 'replaced' );
                        stop();

                        return;
                    }

                    if ( state.seen_at > 0 ) {
                        heard = true;
                        setSeen( state.platforms );
                        setPhase( 'seen' );

                        // A later ping (say, after accepting cookies) can still turn a warning green.
                        if ( allSent( state.platforms ) ) {
                            stop();

                            return;
                        }
                    }

                    settle();
                } )
                .catch( ( reason: unknown ) => {
                    busy = false;

                    if ( id !== run.current ) {
                        return;
                    }

                    // The admin side failing is a different problem from a quiet store.
                    if ( ++failures >= 3 ) {
                        setPollError( errorMessage( reason ) );
                    }

                    settle();
                } );
        }

        checkNow.current = check;
        next();
    };

    useEffect( () => {
        // The test counter, not a DOM node; bumping it on unmount drops replies still on their way.
        const tests = run;
        const id = tests.current;
        const visible = () => {
            if ( document.visibilityState === 'visible' ) {
                checkNow.current?.();
            }
        };

        document.addEventListener( 'visibilitychange', visible );
        window.addEventListener( 'focus', visible );

        // After a reload or a step back, pick up a test still being listened for.
        fetchVerify()
            .then( ( state ) => {
                if (
                    id !== tests.current ||
                    ! state.active ||
                    ! state.url ||
                    state.age * 1000 >= POLL_CAP
                ) {
                    return;
                }

                const opened = Date.now() - state.age * 1000;
                const heard = state.seen_at > 0;
                const resumed = ++tests.current;

                setLink( state.url );

                if ( heard ) {
                    setSeen( state.platforms );
                    setPhase( 'seen' );

                    if ( allSent( state.platforms ) ) {
                        return;
                    }
                } else {
                    setPhase(
                        state.age * 1000 >= HINT_AFTER ? 'slow' : 'waiting'
                    );
                }

                listen( resumed, state.test, opened, heard );
            } )
            .catch( () => {} );

        return () => {
            tests.current++;
            window.clearTimeout( timer.current );
            document.removeEventListener( 'visibilitychange', visible );
            window.removeEventListener( 'focus', visible );
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps -- once, on mount.
    }, [] );

    const open = () => {
        if ( phase === 'starting' || ! checkable.length ) {
            return;
        }

        // Opened within the click, so popup blockers allow it; it gets the test link once the server made one.
        const tab = window.open( '', '_blank' );
        const id = ++run.current;

        stop();
        setPhase( 'starting' );
        setError( '' );
        setPollError( '' );
        setSeen( {} );

        startVerify()
            .then( ( { url, test } ) => {
                if ( id !== run.current ) {
                    tab?.close();

                    return;
                }

                setLink( url );

                if ( tab && ! tab.closed ) {
                    tab.opener = null;
                    tab.location.replace( url );
                }

                setPhase( 'waiting' );
                listen( id, test, Date.now() );
            } )
            .catch( ( reason: unknown ) => {
                tab?.close();

                if ( id === run.current ) {
                    setError( errorMessage( reason ) );
                    setPhase( 'failed' );
                }
            } );
    };

    // Try again lives in the hint, which goes away; focus moves to Open your store.
    const retry = () => {
        open();
        openButton.current?.focus();
    };

    const results = checkable.map( ( row ) => ( {
        name: row.platform.name,
        check: platformCheck( row.integrations, seen ),
    } ) );
    const named = ( check: string ) =>
        phase === 'seen'
            ? results
                  .filter( ( result ) => result.check === check )
                  .map( ( result ) => result.name )
            : [];
    const sent = named( 'sent' );
    const blocked = named( 'blocked' );
    const missing = named( 'missing' );
    const listening = [ 'starting', 'waiting', 'slow' ].includes( phase );

    const rowView = ( row: VerifyRow ): { tone: Tone; text: string } => {
        if ( row.pending ) {
            return {
                tone: 'off',
                text: __(
                    'Saved when you finish. Until then, your store uses the saved settings.',
                    'woocommerce-conversion-tracking'
                ),
            };
        }

        if ( row.paused ) {
            return {
                tone: 'off',
                text: __(
                    'Tracking is paused. Resume it on the Platforms page to check it.',
                    'woocommerce-conversion-tracking'
                ),
            };
        }

        if ( phase === 'seen' ) {
            switch ( platformCheck( row.integrations, seen ) ) {
                case 'sent':
                    return {
                        tone: 'ok',
                        text: __(
                            'First event sent: Page view, just now.',
                            'woocommerce-conversion-tracking'
                        ),
                    };
                case 'blocked':
                    return {
                        tone: 'warn',
                        text: __(
                            "Your store checked in, but this platform's script didn't load.",
                            'woocommerce-conversion-tracking'
                        ),
                    };
                default:
                    return {
                        tone: 'warn',
                        text: __(
                            "Your store checked in, but this platform isn't on it yet.",
                            'woocommerce-conversion-tracking'
                        ),
                    };
            }
        }

        if ( listening || phase === 'idle' ) {
            return {
                tone: listening ? 'busy' : 'idle',
                text: __(
                    'Waiting for the first event…',
                    'woocommerce-conversion-tracking'
                ),
            };
        }

        return {
            tone: 'off',
            text: __( 'No event yet.', 'woocommerce-conversion-tracking' ),
        };
    };

    // One message for screen readers per change, instead of reading every row.
    const status = ( (): string => {
        switch ( phase ) {
            case 'starting':
                return __(
                    'Opening your store…',
                    'woocommerce-conversion-tracking'
                );
            case 'waiting':
                return __(
                    'Waiting for the first event from your store.',
                    'woocommerce-conversion-tracking'
                );
            case 'slow':
                return __(
                    'Nothing has arrived yet. Still checking.',
                    'woocommerce-conversion-tracking'
                );
            case 'stopped':
                return __(
                    'Nothing arrived from your store, so we stopped checking.',
                    'woocommerce-conversion-tracking'
                );
            case 'replaced':
                return __(
                    'A newer test link was opened in another window.',
                    'woocommerce-conversion-tracking'
                );
            case 'failed':
                return error;
            case 'seen':
                return [
                    sent.length
                        ? sprintf(
                              /* translators: %s: platform names */
                              __(
                                  'First event sent to %s.',
                                  'woocommerce-conversion-tracking'
                              ),
                              listOf( sent )
                          )
                        : '',
                    blocked.length || missing.length
                        ? sprintf(
                              /* translators: %s: platform names */
                              __(
                                  'Not sent to %s.',
                                  'woocommerce-conversion-tracking'
                              ),
                              listOf( [ ...blocked, ...missing ] )
                          )
                        : '',
                ]
                    .filter( Boolean )
                    .join( ' ' );
            default:
                return '';
        }
    } )();

    const tryAgain = (
        <div>
            <Button variant="outline" size="sm" onClick={ retry }>
                { __( 'Try again', 'woocommerce-conversion-tracking' ) }
                <span className="sr-only">
                    { __(
                        '(opens a new test link in a new tab)',
                        'woocommerce-conversion-tracking'
                    ) }
                </span>
            </Button>
        </div>
    );

    const reasons = ( items: string[] ) => (
        <ul className="m-0 flex list-disc flex-col gap-1 pl-5">
            { items.map( ( item ) => (
                <li key={ item } className="m-0">
                    { item }
                </li>
            ) ) }
        </ul>
    );

    let help: ReactNode = null;

    if ( pollError && phase !== 'seen' ) {
        help = (
            <Notice
                variant="destructive"
                role={ undefined }
                className="max-w-140 gap-2"
                data-testid="wcct-verify-error"
            >
                <NoticeTitle>
                    { __(
                        "We couldn't check for the page view. We keep trying.",
                        'woocommerce-conversion-tracking'
                    ) }
                </NoticeTitle>
                <p className="m-0 text-sm">{ pollError }</p>
            </Notice>
        );
    } else if (
        phase === 'slow' ||
        phase === 'stopped' ||
        phase === 'replaced'
    ) {
        help = (
            <Notice
                variant="warning"
                role={ undefined }
                className="max-w-140 gap-2"
                data-testid="wcct-verify-hint"
            >
                <NoticeTitle>
                    { phase === 'replaced'
                        ? __(
                              'A newer test link was opened in another window, so this one stopped working.',
                              'woocommerce-conversion-tracking'
                          )
                        : __(
                              "We haven't heard from your store yet.",
                              'woocommerce-conversion-tracking'
                          ) }
                </NoticeTitle>
                <p className="m-0 text-sm">
                    { __(
                        'Keep the store tab open until the page has finished loading. If nothing arrives, one of these is the usual cause:',
                        'woocommerce-conversion-tracking'
                    ) }
                </p>
                { reasons( [
                    __(
                        'A page cache or an optimization plugin delays or combines scripts. Clear the cache, or leave Conversion Tracking out of the script optimization.',
                        'woocommerce-conversion-tracking'
                    ),
                    __(
                        'An ad blocker or a privacy extension stopped the check. Open the store in a private window.',
                        'woocommerce-conversion-tracking'
                    ),
                    __(
                        "The store tab was closed, or it didn't open. Use the test link below.",
                        'woocommerce-conversion-tracking'
                    ),
                ] ) }
                <p className="m-0 text-sm">
                    { draft
                        ? __(
                              'Still stuck? The Doctor checks your store for these.',
                              'woocommerce-conversion-tracking'
                          )
                        : __(
                              "Still stuck? The Doctor checks your store for these. You can also skip for now and come back from the dashboard's checklist.",
                              'woocommerce-conversion-tracking'
                          ) }{ ' ' }
                    <a href="#/doctor">
                        { __(
                            'Open the Doctor',
                            'woocommerce-conversion-tracking'
                        ) }
                    </a>
                </p>
                { tryAgain }
            </Notice>
        );
    } else if ( phase === 'failed' ) {
        help = (
            <Notice
                variant="destructive"
                role={ undefined }
                className="max-w-140 gap-2"
                data-testid="wcct-verify-error"
            >
                <NoticeTitle>
                    { __(
                        "We couldn't make a test link.",
                        'woocommerce-conversion-tracking'
                    ) }
                </NoticeTitle>
                <p className="m-0 text-sm">{ error }</p>
                { tryAgain }
            </Notice>
        );
    } else if ( blocked.length || missing.length ) {
        const items: string[] = [];

        if ( blocked.length ) {
            items.push(
                __(
                    'An ad blocker or a browser privacy setting stopped the script. Open the store in a private window with extensions off.',
                    'woocommerce-conversion-tracking'
                )
            );

            if ( consent ) {
                items.push(
                    __(
                        'Respect cookie choices is on, so tags wait for consent. Accept marketing cookies in the banner on the test page.',
                        'woocommerce-conversion-tracking'
                    )
                );
            }
        }

        if ( missing.length ) {
            items.push(
                __(
                    'A platform shows on your store once its ID is saved and tracking is on. Check it on the Platforms page.',
                    'woocommerce-conversion-tracking'
                )
            );
        }

        help = (
            <Notice
                variant="warning"
                role={ undefined }
                className="max-w-140 gap-2"
                data-testid="wcct-verify-hint"
            >
                <NoticeTitle>
                    { sprintf(
                        /* translators: %s: platform names */
                        __(
                            'Your store checked in, but nothing was sent to %s.',
                            'woocommerce-conversion-tracking'
                        ),
                        listOf( [ ...blocked, ...missing ] )
                    ) }
                </NoticeTitle>
                { reasons( items ) }
                <p className="m-0 text-sm">
                    { __(
                        'Then try again. We keep checking for a few minutes, so a reload of the store tab also counts.',
                        'woocommerce-conversion-tracking'
                    ) }{ ' ' }
                    { ! draft &&
                        ! sent.length &&
                        __(
                            "Continue opens once one platform sends its first event. You can also skip for now and come back from the dashboard's checklist.",
                            'woocommerce-conversion-tracking'
                        ) }
                </p>
                { tryAgain }
            </Notice>
        );
    }

    return (
        <StepLayout
            step={ 5 }
            title={ __( 'Check it works', 'woocommerce-conversion-tracking' ) }
            lead={ __(
                'Open your store with the button below. It opens a test link, so this turns green as soon as that page view is sent.',
                'woocommerce-conversion-tracking'
            ) }
            onBack={ onBack }
            onSkip={ onSkip }
            skipLabel={ skipLabel }
            action={
                <Button
                    onClick={ onNext }
                    // Open once one platform sent its first event; Skip for now is the way out.
                    disabled={
                        ! draft && checkable.length > 0 && ! sent.length
                    }
                >
                    { __( 'Continue', 'woocommerce-conversion-tracking' ) }
                </Button>
            }
        >
            <div className="flex flex-col gap-2">
                <div>
                    { /* Stays focusable while it works, so focus isn't lost; aria-disabled instead of disabled. */ }
                    <Button
                        ref={ openButton }
                        variant="outline"
                        onClick={ open }
                        aria-disabled={
                            ! checkable.length || phase === 'starting'
                                ? true
                                : undefined
                        }
                        aria-busy={ phase === 'starting' || undefined }
                        className="aria-disabled:cursor-not-allowed aria-disabled:opacity-50"
                        data-testid="wcct-verify-open"
                    >
                        { phase === 'starting' ? (
                            <LoaderCircle
                                className="animate-spin motion-reduce:animate-none"
                                aria-hidden="true"
                            />
                        ) : (
                            <ExternalLink aria-hidden="true" />
                        ) }
                        { __(
                            'Open your store',
                            'woocommerce-conversion-tracking'
                        ) }
                        <NewTabNote />
                    </Button>
                </div>
                { link && (
                    <p className="m-0 text-[13px] text-muted-foreground">
                        { __(
                            "The test link works for an hour, in any browser. Didn't open?",
                            'woocommerce-conversion-tracking'
                        ) }{ ' ' }
                        <a
                            href={ link }
                            target="_blank"
                            rel="noreferrer"
                            data-testid="wcct-verify-link"
                        >
                            { __(
                                'Open the test link',
                                'woocommerce-conversion-tracking'
                            ) }
                            <NewTabNote />
                        </a>
                    </p>
                ) }
            </div>
            { rows.length ? (
                <ul
                    className="m-0 flex max-w-140 list-none flex-col gap-3 p-0"
                    data-testid="wcct-verify-rows"
                >
                    { rows.map( ( row ) => {
                        const view = rowView( row );

                        return (
                            <li
                                key={ row.platform.id }
                                className={ cn(
                                    'm-0 flex items-center gap-3.5 rounded-xl border border-border px-4 py-3.5',
                                    view.tone === 'ok' &&
                                        'border-tone-ok-dot/40 bg-tone-ok-bg/40',
                                    view.tone === 'warn' &&
                                        'border-tone-warn-dot/40'
                                ) }
                                data-testid={ `wcct-verify-${ row.platform.id }` }
                                data-state={ view.tone }
                            >
                                <span
                                    className={ cn(
                                        'inline-flex size-9 shrink-0 items-center justify-center rounded-full',
                                        toneClass[ view.tone ]
                                    ) }
                                    aria-hidden="true"
                                >
                                    { toneIcon( view.tone ) }
                                </span>
                                <span className="flex flex-col text-sm">
                                    <b>{ row.platform.name }</b>
                                    <span className="text-page-muted-foreground">
                                        { view.text }
                                    </span>
                                </span>
                            </li>
                        );
                    } ) }
                </ul>
            ) : (
                <p className="m-0 text-[13px] text-muted-foreground">
                    { __(
                        'No platform is connected yet. Go back to connect one, or skip for now.',
                        'woocommerce-conversion-tracking'
                    ) }
                </p>
            ) }
            { help }
            <p
                className="sr-only"
                role="status"
                data-testid="wcct-verify-status"
            >
                { status }
            </p>
        </StepLayout>
    );
};
