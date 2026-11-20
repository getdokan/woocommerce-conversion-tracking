/**
 * External dependencies
 */
import type { ReactNode } from 'react';
import { Button, Notice, Spinner, cn } from '@wedevs/plugin-ui';
import { StatusPill, type StatusTone } from '@wcct/components';
import { CircleAlert, CircleCheck, Copy, RefreshCw } from 'lucide-react';

/**
 * WordPress dependencies
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import {
    feedState,
    formatCount,
    type CatalogData,
    type FeedState,
} from '../../data/catalog';

const count = formatCount;

/**
 * "Generated N hours ago, rebuilt every 24 hours."
 *
 * @param {number} hours Age in whole hours.
 */
export const generatedText = ( hours: number ): string =>
    hours < 1
        ? __(
              'Generated less than an hour ago, rebuilt every 24 hours.',
              'woocommerce-conversion-tracking'
          )
        : sprintf(
              /* translators: %s: number of hours. */
              _n(
                  'Generated %s hour ago, rebuilt every 24 hours.',
                  'Generated %s hours ago, rebuilt every 24 hours.',
                  hours,
                  'woocommerce-conversion-tracking'
              ),
              count( hours )
          );

type Summary = {
    tone: StatusTone;
    pill: string;
    title: string;
    sub: ReactNode;
};

/**
 * Heading, line and pill for each feed state.
 *
 * @param {FeedState}   state Feed state.
 * @param {CatalogData} data  Catalog data.
 */
const summary = ( state: FeedState, data: CatalogData ): Summary => {
    const age = data.age_hours ?? 0;

    switch ( state ) {
        case 'error':
            return {
                tone: 'bad',
                pill: __( 'Failed', 'woocommerce-conversion-tracking' ),
                title: __(
                    'The last feed build failed',
                    'woocommerce-conversion-tracking'
                ),
                sub: data.generated_at
                    ? __(
                          'Meta keeps getting the last good file until a build finishes.',
                          'woocommerce-conversion-tracking'
                      )
                    : __(
                          'There is no feed file yet, so Meta has nothing to fetch.',
                          'woocommerce-conversion-tracking'
                      ),
            };
        case 'missing':
            return {
                tone: 'warn',
                pill: __( 'Not built', 'woocommerce-conversion-tracking' ),
                title: __(
                    "Your feed hasn't been built yet",
                    'woocommerce-conversion-tracking'
                ),
                sub: __(
                    'Build it now, then connect the feed URL in Commerce Manager.',
                    'woocommerce-conversion-tracking'
                ),
            };
        case 'building':
            return {
                tone: 'off',
                pill: __( 'Building', 'woocommerce-conversion-tracking' ),
                title: __(
                    'Your feed is being built',
                    'woocommerce-conversion-tracking'
                ),
                sub: data.build_rows
                    ? sprintf(
                          /* translators: %s: number of products written so far. */
                          _n(
                              '%s product so far. This page updates when it is ready.',
                              '%s products so far. This page updates when it is ready.',
                              data.build_rows,
                              'woocommerce-conversion-tracking'
                          ),
                          count( data.build_rows )
                      )
                    : __(
                          'Starting. This page updates when it is ready.',
                          'woocommerce-conversion-tracking'
                      ),
            };
        case 'stale':
            return {
                tone: 'warn',
                pill: __( 'Out of date', 'woocommerce-conversion-tracking' ),
                title: sprintf(
                    /* translators: %s: number of hours. */
                    _n(
                        'Your feed is %s hour old',
                        'Your feed is %s hours old',
                        age,
                        'woocommerce-conversion-tracking'
                    ),
                    count( age )
                ),
                sub: __(
                    'It should be rebuilt every 24 hours, so Meta may be showing old prices or stock.',
                    'woocommerce-conversion-tracking'
                ),
            };
        default:
            return {
                tone: 'ok',
                pill: __( 'Ready', 'woocommerce-conversion-tracking' ),
                title: __(
                    'Your feed is ready',
                    'woocommerce-conversion-tracking'
                ),
                sub: generatedText( age ),
            };
    }
};

const ICON: Record< StatusTone, string > = {
    ok: 'bg-tone-ok-bg text-tone-ok-fg',
    warn: 'bg-tone-warn-bg text-tone-warn-fg',
    bad: 'bg-tone-bad-bg text-tone-bad-fg',
    off: 'bg-tone-off-bg text-tone-off-fg',
};

/**
 * Feed URL with a Copy button.
 *
 * @param {Object} props     Component props.
 * @param {string} props.url Feed URL.
 */
const FeedUrl = ( { url }: { url: string } ) => {
    const [ copied, setCopied ] = useState( false );
    const code = useRef< HTMLElement >( null );
    const timer = useRef< number >( 0 );

    useEffect( () => () => window.clearTimeout( timer.current ), [] );

    const selectUrl = () => {
        const selection =
            code.current?.ownerDocument.defaultView?.getSelection();

        if ( code.current && selection ) {
            selection.selectAllChildren( code.current );
        }
    };

    const copy = async () => {
        try {
            await window.navigator.clipboard.writeText( url );
            setCopied( true );
            window.clearTimeout( timer.current );
            timer.current = window.setTimeout( () => setCopied( false ), 2500 );
        } catch {
            // No clipboard access (http, permissions): select it for Ctrl+C.
            selectUrl();
        }
    };

    return (
        <div className="flex flex-wrap items-center gap-3 rounded-lg border border-border bg-muted/40 px-3 py-2.5">
            <span className="text-[13px] text-muted-foreground">
                { __( 'Feed URL', 'woocommerce-conversion-tracking' ) }
            </span>
            <code
                id="wcct-feed-url"
                ref={ code }
                className="min-w-0 flex-1 bg-transparent p-0 font-mono text-[13px] break-all text-foreground"
            >
                { url }
            </code>
            <Button variant="secondary" size="sm" onClick={ copy }>
                { copied ? (
                    <CircleCheck aria-hidden="true" />
                ) : (
                    <Copy aria-hidden="true" />
                ) }
                { copied
                    ? __( 'Copied', 'woocommerce-conversion-tracking' )
                    : __( 'Copy URL', 'woocommerce-conversion-tracking' ) }
            </Button>
            <span className="sr-only" role="status">
                { copied
                    ? __(
                          'Feed URL copied.',
                          'woocommerce-conversion-tracking'
                      )
                    : '' }
            </span>
        </div>
    );
};

export type FeedStatusProps = {
    data: CatalogData;
    // The build hasn't moved for several polls, or polling gave up.
    stalled: boolean;
    rebuilding: boolean;
    rebuildError: string;
    onRebuild: () => void;
    children?: ReactNode;
};

/**
 * The feed status card: freshness, product count, URL and, below it, the login.
 *
 * @param {FeedStatusProps} props Component props.
 */
export const FeedStatus = ( {
    data,
    stalled,
    rebuilding,
    rebuildError,
    onRebuild,
    children,
}: FeedStatusProps ) => {
    const state = feedState( data );
    const { tone, pill, title, sub } = summary( state, data );
    const canRebuild =
        stalled ||
        ( ! data.building &&
            [ 'error', 'missing', 'stale' ].includes( state ) );
    const hadRebuild = useRef( canRebuild );
    const heading = useRef< HTMLHeadingElement >( null );

    // The button goes away once a build is queued; keep focus in the card instead of dropping it to the page.
    useEffect( () => {
        const active = heading.current?.ownerDocument.activeElement;
        const lost = ! active || active === heading.current?.ownerDocument.body;

        if ( hadRebuild.current && ! canRebuild && lost ) {
            heading.current?.focus();
        }

        hadRebuild.current = canRebuild;
    }, [ canRebuild ] );

    const Icon = tone === 'ok' ? CircleCheck : CircleAlert;

    return (
        <section
            className="flex flex-col gap-4 rounded-xl border border-border p-5"
            aria-labelledby="wcct-feed-title"
            data-testid="wcct-feed-status"
            data-state={ state }
        >
            <div className="flex items-start gap-3.5">
                <span
                    className={ cn(
                        'flex size-10 shrink-0 items-center justify-center rounded-full',
                        ICON[ tone ]
                    ) }
                >
                    { state === 'building' ? (
                        <Spinner
                            className="size-5"
                            role="presentation"
                            aria-hidden="true"
                        />
                    ) : (
                        <Icon aria-hidden="true" className="size-5" />
                    ) }
                </span>
                <div className="flex min-w-0 flex-1 flex-col gap-1">
                    <h2
                        id="wcct-feed-title"
                        ref={ heading }
                        tabIndex={ -1 }
                        className="m-0 text-base font-semibold text-foreground"
                    >
                        { title }
                    </h2>
                    <p className="m-0 text-sm text-muted-foreground">{ sub }</p>
                </div>
                <StatusPill tone={ tone } className="shrink-0">
                    { pill }
                </StatusPill>
            </div>

            { state === 'error' && (
                <p
                    className="m-0 text-[13px] text-muted-foreground"
                    data-testid="wcct-feed-error"
                >
                    { sprintf(
                        /* translators: %s: error message from the feed builder. */
                        __( 'Error: %s', 'woocommerce-conversion-tracking' ),
                        data.error
                    ) }
                </p>
            ) }

            { data.building && !! data.generated_at && (
                <p className="m-0 flex items-center gap-2 text-[13px] text-muted-foreground">
                    <Spinner
                        className="size-4"
                        role="presentation"
                        aria-hidden="true"
                    />
                    { data.build_rows
                        ? sprintf(
                              /* translators: %s: number of products written so far. */
                              _n(
                                  'Rebuilding now: %s product so far. Meta keeps getting the current file until the new one is ready.',
                                  'Rebuilding now: %s products so far. Meta keeps getting the current file until the new one is ready.',
                                  data.build_rows,
                                  'woocommerce-conversion-tracking'
                              ),
                              count( data.build_rows )
                          )
                        : __(
                              'Rebuilding now. Meta keeps getting the current file until the new one is ready.',
                              'woocommerce-conversion-tracking'
                          ) }
                </p>
            ) }

            { canRebuild && (
                <div className="flex flex-col items-start gap-2">
                    <Button
                        onClick={ onRebuild }
                        disabled={ rebuilding }
                        variant={ state === 'stale' ? 'default' : 'outline' }
                    >
                        <RefreshCw aria-hidden="true" />
                        { state === 'missing'
                            ? __(
                                  'Build now',
                                  'woocommerce-conversion-tracking'
                              )
                            : __(
                                  'Rebuild now',
                                  'woocommerce-conversion-tracking'
                              ) }
                    </Button>
                    { rebuildError && (
                        <Notice variant="destructive" role="alert">
                            <p className="m-0">{ rebuildError }</p>
                        </Notice>
                    ) }
                    { stalled && (
                        <p
                            className="m-0 text-[13px] text-foreground"
                            data-testid="wcct-feed-stalled"
                        >
                            { __(
                                "The build hasn't moved for a while, so WordPress's scheduled tasks may not be running. Rebuild now queues it again.",
                                'woocommerce-conversion-tracking'
                            ) }
                        </p>
                    ) }
                    { ( stalled || state !== 'missing' ) && (
                        <p className="m-0 text-[13px] text-muted-foreground">
                            { __(
                                "The feed is rebuilt by WordPress's scheduled tasks, which only run when someone visits your site. On a store with few visits, ask your host to run WP-Cron from a real server cron job so the feed stays fresh.",
                                'woocommerce-conversion-tracking'
                            ) }
                        </p>
                    ) }
                </div>
            ) }

            { !! data.generated_at && (
                <dl className="m-0 flex flex-wrap items-baseline gap-x-3 gap-y-1 text-[13px]">
                    <dt className="text-muted-foreground">
                        { __(
                            'Product count',
                            'woocommerce-conversion-tracking'
                        ) }
                    </dt>
                    <dd
                        className="m-0 font-semibold text-foreground"
                        data-testid="wcct-feed-rows"
                    >
                        { count( data.rows ) }
                    </dd>
                    <dd className="m-0 text-muted-foreground">
                        { __(
                            'Items in the feed; each variation counts as one.',
                            'woocommerce-conversion-tracking'
                        ) }
                    </dd>
                </dl>
            ) }

            { data.url && <FeedUrl url={ data.url } /> }

            { children }
        </section>
    );
};
