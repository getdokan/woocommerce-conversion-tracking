/**
 * External dependencies
 */
import { Button, Card, cn } from '@wedevs/plugin-ui';
import { Page, PlatformLogo, ProBadge, StatusPill } from '@wcct/components';
import { Check, Lock } from 'lucide-react';

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { useAppData } from '../../data/AppData';
import { needsAttention } from '../../data/platformForm';
import {
    platformCards,
    platformLogoUrl,
    type PlatformCardInfo,
} from '../../data/platforms';
import { upgradeUrl } from '../../data/plan';
import { NewTabNote } from '../../shell/NewTabNote';
import { pageInfo } from '../titles';
import CustomScript from './custom/CustomScript';
import { PlatformDetail } from './detail/PlatformDetail';

/**
 * The status dot of a platform in the list.
 *
 * @param {Object}           props           Component props.
 * @param {PlatformCardInfo} props.platform  Platform.
 * @param {boolean}          props.attention It needs the merchant.
 */
const StatusDot = ( {
    platform,
    attention,
}: {
    platform: PlatformCardInfo;
    attention: boolean;
} ) => {
    if ( platform.locked ) {
        return <ProBadge className="ml-auto" />;
    }

    let label: string = __( 'Not set up', 'woocommerce-conversion-tracking' );
    let tone = 'bg-tone-off-dot';

    if ( platform.paused ) {
        label = __( 'Paused', 'woocommerce-conversion-tracking' );
    } else if ( platform.connected && attention ) {
        label = __( 'Needs attention', 'woocommerce-conversion-tracking' );
        tone = 'bg-tone-warn-dot';
    } else if ( platform.connected ) {
        label = __( 'On', 'woocommerce-conversion-tracking' );
        tone = 'bg-tone-ok-dot';
    }

    return (
        <span className="ml-auto inline-flex size-4 items-center justify-center">
            <span className={ cn( 'size-2 rounded-full', tone ) } />
            <span className="sr-only">{ label }</span>
        </span>
    );
};

/**
 * The list of platforms beside the open one.
 *
 * @param {Object}             props           Component props.
 * @param {PlatformCardInfo[]} props.platforms Platforms.
 * @param {string}             props.current   Open platform ID.
 * @param {string[]}           props.attention Platforms that need the merchant.
 */
const PlatformList = ( {
    platforms,
    current,
    attention,
}: {
    platforms: PlatformCardInfo[];
    current: string;
    attention: string[];
} ) => (
    <nav
        aria-labelledby="wcct-platform-list-label"
        className="border-r border-border p-3 max-[960px]:border-r-0 max-[960px]:border-b"
    >
        <div
            id="wcct-platform-list-label"
            className="px-3 pt-2 pb-3 text-xs text-muted-foreground"
        >
            { __( 'Your platforms', 'woocommerce-conversion-tracking' ) }
        </div>
        <ul className="m-0 flex list-none flex-col gap-1 p-0">
            { platforms.map( ( platform ) => {
                const active = platform.id === current;

                return (
                    <li key={ platform.id } className="m-0">
                        <a
                            href={ `#/platforms/${ platform.id }` }
                            aria-current={ active ? 'page' : undefined }
                            className={ cn(
                                'flex items-center gap-3 rounded-lg border-l-2 border-transparent px-3 py-2 text-sm font-medium text-foreground no-underline hover:bg-muted focus:shadow-none',
                                active && 'border-l-primary bg-muted'
                            ) }
                        >
                            <PlatformLogo
                                src={ platformLogoUrl( platform.logo ) }
                                className="size-7 rounded-md [&>img]:size-4"
                            />
                            { platform.name }
                            { platform.customised && (
                                <StatusPill
                                    tone="off"
                                    className="px-1.5 text-[11px] before:hidden"
                                >
                                    { __(
                                        'Customised',
                                        'woocommerce-conversion-tracking'
                                    ) }
                                </StatusPill>
                            ) }
                            <StatusDot
                                platform={ platform }
                                attention={ attention.includes( platform.id ) }
                            />
                        </a>
                    </li>
                );
            } ) }
        </ul>
    </nav>
);

type LockedCopy = { intro: string; text: string; features: string[] };

/**
 * What a Pro platform does, for its locked page on Free.
 *
 * @param {PlatformCardInfo} platform Platform.
 */
const lockedCopy = ( platform: PlatformCardInfo ): LockedCopy => {
    if ( platform.id === 'bing_ads' ) {
        return {
            intro: __(
                "Sends your store's events to the UET tag for Bing and Microsoft Start ads.",
                'woocommerce-conversion-tracking'
            ),
            text: __(
                'Track purchases from Bing and Microsoft Start ads with the UET tag, counted once like your other platforms. Everything you set up for Meta, Google and X keeps working on Free.',
                'woocommerce-conversion-tracking'
            ),
            features: [
                __(
                    'UET tag on every page, purchases with order value',
                    'woocommerce-conversion-tracking'
                ),
                __(
                    'Start checkout on classic and block checkout',
                    'woocommerce-conversion-tracking'
                ),
                __(
                    'Respects cookie choices like the other platforms',
                    'woocommerce-conversion-tracking'
                ),
            ],
        };
    }

    return { intro: platform.meta, text: '', features: [] };
};

/**
 * A list with a check mark per line.
 *
 * @param {Object}   props       Component props.
 * @param {string[]} props.items Lines.
 */
const FeatureList = ( { items }: { items: string[] } ) => (
    <ul className="m-0 flex list-none flex-col gap-2 p-0 text-sm text-foreground">
        { items.map( ( item ) => (
            <li key={ item } className="m-0 flex items-start gap-2">
                <Check
                    className="mt-0.5 size-4 shrink-0 text-tone-ok-fg"
                    aria-hidden="true"
                />
                { item }
            </li>
        ) ) }
    </ul>
);

/**
 * A Pro platform on Free: what it does and where to get it, never a modal; Continue with Free stays in reach.
 *
 * @param {Object}           props          Component props.
 * @param {PlatformCardInfo} props.platform Platform.
 */
const LockedDetail = ( { platform }: { platform: PlatformCardInfo } ) => {
    const copy = lockedCopy( platform );
    const docsUrl =
        platform.id === 'bing_ads'
            ? window.wcctAdmin?.docs.tiles.find(
                  ( tile ) => tile.id === 'microsoft'
              )?.url ?? ''
            : '';

    return (
        <section
            aria-labelledby="wcct-platform-title"
            className="flex flex-col"
            data-testid={ `wcct-platform-${ platform.id }` }
        >
            <div className="flex items-start gap-3.5 border-b border-border px-6 py-5">
                <PlatformLogo src={ platformLogoUrl( platform.logo ) } />
                <div className="min-w-0 flex-1">
                    <h2
                        id="wcct-platform-title"
                        className="m-0 flex items-center gap-2 p-0 text-base leading-snug font-semibold text-foreground"
                    >
                        { platform.name }
                        <ProBadge />
                    </h2>
                    <p className="m-0 mt-0.5 text-sm text-muted-foreground">
                        { copy.intro }{ ' ' }
                        { docsUrl && (
                            <a
                                href={ docsUrl }
                                target="_blank"
                                rel="noreferrer"
                                className="font-medium text-primary underline underline-offset-3"
                            >
                                { __(
                                    'Learn more',
                                    'woocommerce-conversion-tracking'
                                ) }
                                <NewTabNote />
                            </a>
                        ) }
                    </p>
                </div>
                <StatusPill tone="off">
                    { __( 'Not set up', 'woocommerce-conversion-tracking' ) }
                </StatusPill>
            </div>
            <div className="grid grid-cols-1 min-[1280px]:grid-cols-[minmax(0,1fr)_320px]">
                <div className="flex min-w-0 flex-col gap-5 p-6 min-[1280px]:border-r min-[1280px]:border-border">
                    <div className="flex flex-col gap-2">
                        <span className="inline-flex size-13 items-center justify-center rounded-full bg-muted text-muted-foreground">
                            <Lock className="size-5" aria-hidden="true" />
                        </span>
                        <h3 className="m-0 mt-2 text-lg font-semibold text-foreground">
                            { sprintf(
                                /* translators: %s: platform name, e.g. Microsoft Advertising */
                                __(
                                    '%s is part of Pro',
                                    'woocommerce-conversion-tracking'
                                ),
                                platform.name
                            ) }
                        </h3>
                        { copy.text && (
                            <p className="m-0 max-w-140 text-sm text-muted-foreground">
                                { copy.text }
                            </p>
                        ) }
                    </div>
                    { copy.features.length > 0 && (
                        <FeatureList items={ copy.features } />
                    ) }
                    <div className="flex flex-wrap items-center gap-3">
                        <Button
                            nativeButton={ false }
                            render={
                                // eslint-disable-next-line jsx-a11y/anchor-has-content -- Button fills it.
                                <a
                                    href={ upgradeUrl() }
                                    target="_blank"
                                    rel="noreferrer"
                                />
                            }
                            data-testid="wcct-locked-compare"
                        >
                            { __(
                                'Compare plans',
                                'woocommerce-conversion-tracking'
                            ) }
                            <NewTabNote />
                        </Button>
                        <Button
                            variant="ghost"
                            nativeButton={ false }
                            render={
                                // eslint-disable-next-line jsx-a11y/anchor-has-content -- Button fills it.
                                <a href="#/platforms/facebook" />
                            }
                        >
                            { __(
                                'Continue with Free',
                                'woocommerce-conversion-tracking'
                            ) }
                        </Button>
                    </div>
                </div>
                <aside
                    aria-label={ __(
                        'What you get',
                        'woocommerce-conversion-tracking'
                    ) }
                    className="flex flex-col gap-3 bg-muted/50 p-6"
                >
                    <h3 className="m-0 text-sm font-semibold text-foreground">
                        { __(
                            'Also in Pro',
                            'woocommerce-conversion-tracking'
                        ) }
                    </h3>
                    <FeatureList
                        items={ [
                            __(
                                'More than one Meta pixel',
                                'woocommerce-conversion-tracking'
                            ),
                            __(
                                'Meta catalog feed',
                                'woocommerce-conversion-tracking'
                            ),
                            __(
                                'Role access for your team',
                                'woocommerce-conversion-tracking'
                            ),
                        ] }
                    />
                </aside>
            </div>
        </section>
    );
};

/**
 * Platforms: the list of platforms and the open one's page (#/platforms/:id).
 *
 * @param {Object} props       Component props.
 * @param {string} props.id    Platform ID from the route.
 * @param          props.query
 */
const Platforms = ( { id, query }: { id: string; query: string } ) => {
    const { data } = useAppData();
    const { title, description } = pageInfo( {
        name: 'platforms',
        id,
        query: '',
    } );
    const platforms = data ? platformCards( data ) : [];
    const current = platforms.find( ( platform ) => platform.id === id );
    const schema = current?.locked
        ? undefined
        : data?.schema.platforms.find( ( platform ) => platform.id === id );
    const attention = ( data?.schema.platforms ?? [] )
        .filter( ( platform ) =>
            needsAttention(
                platform,
                data?.settings.platforms[ platform.id ] ?? {}
            )
        )
        .map( ( platform ) => platform.id );

    return (
        <Page title={ title } description={ description }>
            <Card
                className="grid grid-cols-[240px_minmax(0,1fr)] gap-0 py-0 max-[960px]:grid-cols-1"
                data-testid="wcct-platforms"
            >
                <PlatformList
                    platforms={ platforms }
                    current={ id }
                    attention={ attention }
                />
                <div className="min-w-0">
                    { current && id === 'custom' && <CustomScript /> }
                    { current?.locked && <LockedDetail platform={ current } /> }
                    { current && schema && id !== 'custom' && (
                        <PlatformDetail
                            key={ current.id }
                            card={ current }
                            platform={ schema }
                            query={ query }
                        />
                    ) }
                </div>
            </Card>
        </Page>
    );
};

export default Platforms;
