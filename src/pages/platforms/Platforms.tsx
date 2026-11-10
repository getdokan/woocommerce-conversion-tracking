/**
 * External dependencies
 */
import { Card, cn } from '@wedevs/plugin-ui';
import { Page, PlatformLogo, ProBadge, StatusPill } from '@wcct/components';

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { useAppData } from '../../data/AppData';
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
 * @param {Object}           props          Component props.
 * @param {PlatformCardInfo} props.platform Platform.
 */
const StatusDot = ( { platform }: { platform: PlatformCardInfo } ) => {
    if ( platform.locked ) {
        return <ProBadge className="ml-auto" />;
    }

    let label: string = __( 'Not set up', 'woocommerce-conversion-tracking' );
    let tone = 'bg-tone-off-dot';

    if ( platform.paused ) {
        label = __( 'Paused', 'woocommerce-conversion-tracking' );
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
 */
const PlatformList = ( {
    platforms,
    current,
}: {
    platforms: PlatformCardInfo[];
    current: string;
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
                            <StatusDot platform={ platform } />
                        </a>
                    </li>
                );
            } ) }
        </ul>
    </nav>
);

/**
 * A Pro platform on Free: what it does and where to get it.
 *
 * @param {Object}           props          Component props.
 * @param {PlatformCardInfo} props.platform Platform.
 */
const LockedDetail = ( { platform }: { platform: PlatformCardInfo } ) => (
    <section
        aria-labelledby="wcct-platform-title"
        className="flex flex-col gap-4 p-6"
        data-testid={ `wcct-platform-${ platform.id }` }
    >
        <div className="flex items-center gap-3.5">
            <PlatformLogo src={ platformLogoUrl( platform.logo ) } />
            <h2
                id="wcct-platform-title"
                className="m-0 p-0 text-base font-semibold text-foreground"
            >
                { platform.name }
            </h2>
            <ProBadge />
        </div>
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-dashed border-input bg-muted/50 px-4 py-3 text-[13px]">
            <span>
                { sprintf(
                    /* translators: %s: platform name, e.g. Microsoft Advertising */
                    __(
                        '%s is part of Pro.',
                        'woocommerce-conversion-tracking'
                    ),
                    platform.name
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
    </section>
);

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

    return (
        <Page title={ title } description={ description }>
            <Card
                className="grid grid-cols-[240px_minmax(0,1fr)] gap-0 py-0 max-[960px]:grid-cols-1"
                data-testid="wcct-platforms"
            >
                <PlatformList platforms={ platforms } current={ id } />
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
