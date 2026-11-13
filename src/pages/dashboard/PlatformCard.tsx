/**
 * External dependencies
 */
import { Button, Card, cn } from '@wedevs/plugin-ui';
import { PlatformLogo, ProBadge, StatusPill } from '@wcct/components';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { MetricsPlatform, PlatformState } from '../../data/metrics';
import { upgradeUrl } from '../../data/plan';
import type { PlatformCardInfo } from '../../data/platforms';
import { NewTabNote } from '../../shell/NewTabNote';
import { CardSkeleton } from '../../shell/PageSkeleton';

const logoUrl = ( file: string ) =>
    file ? `${ window.wcctAdmin?.logosUrl ?? '' }${ file }` : '';

type Props = {
    platform: PlatformCardInfo;
    refreshing?: boolean;
};

/**
 * Name, subtitle and logo row shared by both card kinds.
 *
 * @param {Object}           props          Component props.
 * @param {PlatformCardInfo} props.platform Platform.
 * @param {boolean}          props.off      Grey logo.
 */
const Identity = ( {
    platform,
    off,
}: {
    platform: PlatformCardInfo;
    off: boolean;
} ) => (
    <span className="flex min-w-0 items-center gap-3">
        <PlatformLogo src={ logoUrl( platform.logo ) } off={ off } />
        <span className="min-w-0">
            <span className="flex items-center gap-2 font-semibold text-foreground">
                { platform.name }
                { platform.locked && <ProBadge /> }
                { platform.customised && (
                    <StatusPill
                        tone="off"
                        className="px-1.5 text-[11px] font-medium before:hidden"
                    >
                        { __(
                            'Customised',
                            'woocommerce-conversion-tracking'
                        ) }
                    </StatusPill>
                ) }
            </span>
            <span className="block text-xs text-muted-foreground">
                { platform.meta }
            </span>
        </span>
    </span>
);

/**
 * The state a connected platform's card shows: from the metrics route, or paused / waiting from the settings until it answers.
 *
 * @param {PlatformCardInfo} platform Platform.
 * @param {MetricsPlatform=} metric   The platform's metrics.
 */
export const cardState = (
    platform: PlatformCardInfo,
    metric?: MetricsPlatform
): PlatformState => {
    if ( metric && metric.state !== 'not_set_up' ) {
        return metric.state;
    }

    return platform.paused ? 'paused' : 'waiting';
};

const PILLS = (): Record<
    PlatformState,
    { tone: 'ok' | 'warn' | 'off'; label: string }
> => ( {
    working: {
        tone: 'ok',
        label: __( 'Working', 'woocommerce-conversion-tracking' ),
    },
    attention: {
        tone: 'warn',
        label: __( 'Needs attention', 'woocommerce-conversion-tracking' ),
    },
    waiting: {
        tone: 'off',
        label: __(
            'Waiting for first event',
            'woocommerce-conversion-tracking'
        ),
    },
    paused: {
        tone: 'off',
        label: __( 'Paused', 'woocommerce-conversion-tracking' ),
    },
    not_set_up: {
        tone: 'off',
        label: __( 'Not set up', 'woocommerce-conversion-tracking' ),
    },
} );

/**
 * The card's line when the metrics route didn't answer.
 *
 * @param {PlatformState} state State.
 */
const fallbackNote = ( state: PlatformState ): string =>
    state === 'paused'
        ? __(
              'Tracking is paused. Nothing is sent until you resume it.',
              'woocommerce-conversion-tracking'
          )
        : __(
              'This updates when the first purchase checks in. Place a test order to see it now.',
              'woocommerce-conversion-tracking'
          );

/**
 * A connected platform: the whole card opens its page.
 *
 * @param {Props}            props        Component props.
 * @param {MetricsPlatform=} props.metric The platform's metrics; missing while they load or when they failed.
 */
export const ConnectedPlatformCard = ( {
    platform,
    refreshing,
    metric,
}: Props & { metric?: MetricsPlatform } ) => {
    const state = cardState( platform, metric );
    const pill = PILLS()[ state ];
    const note = metric?.note ?? null;

    return (
        <Card
            size="sm"
            className="relative gap-4 px-4 transition-shadow hover:shadow-md"
            data-testid={ `wcct-platform-card-${ platform.id }` }
            data-state={ refreshing ? undefined : state }
        >
            { refreshing ? (
                <CardSkeleton lines={ 1 } />
            ) : (
                <>
                    <span className="flex items-center justify-between gap-2.5">
                        <a
                            href={ `#/platforms/${ platform.id }` }
                            className="min-w-0 text-inherit no-underline after:absolute after:inset-0 after:content-[''] hover:text-inherit"
                        >
                            <Identity platform={ platform } off={ false } />
                        </a>
                        <StatusPill tone={ pill.tone }>
                            { pill.label }
                        </StatusPill>
                    </span>
                    <span
                        className="block text-[13px] text-muted-foreground"
                        data-testid={ `wcct-platform-note-${ platform.id }` }
                    >
                        { note ? note.text : fallbackNote( state ) }
                        { note?.link && (
                            <>
                                { ' ' }
                                <a
                                    href={ note.link }
                                    className="relative z-10 font-medium text-primary no-underline hover:underline"
                                >
                                    { note.link_label }
                                </a>
                            </>
                        ) }
                    </span>
                </>
            ) }
        </Card>
    );
};

/**
 * A platform that isn't set up: Connect, or the Pro upgrade line when it's locked.
 *
 * @param {Props} props Component props.
 */
export const SetupPlatformCard = ( { platform }: Props ) => (
    <Card
        size="sm"
        className="gap-4 px-4"
        data-testid={ `wcct-platform-card-${ platform.id }` }
    >
        <div className="flex items-center justify-between gap-2.5">
            <Identity platform={ platform } off />
            <StatusPill tone="off">
                { __( 'Not set up', 'woocommerce-conversion-tracking' ) }
            </StatusPill>
        </div>
        { platform.locked ? (
            <div className="flex items-center justify-between gap-3 rounded-lg border border-dashed border-input bg-muted/50 px-4 py-3 text-[13px]">
                <span>
                    { __(
                        'Track Bing and Microsoft ads with Pro.',
                        'woocommerce-conversion-tracking'
                    ) }
                </span>
                <a
                    href={ upgradeUrl() }
                    target="_blank"
                    rel="noreferrer"
                    className="shrink-0 font-medium text-primary underline underline-offset-3"
                >
                    { __( 'Compare plans', 'woocommerce-conversion-tracking' ) }
                    <NewTabNote />
                </a>
            </div>
        ) : (
            <Button
                variant="outline"
                nativeButton={ false }
                className={ cn( 'self-start' ) }
                // eslint-disable-next-line jsx-a11y/anchor-has-content -- the button renders the label inside.
                render={ <a href={ `#/platforms/${ platform.id }` } /> }
            >
                { platform.connectLabel }
            </Button>
        ) }
    </Card>
);
