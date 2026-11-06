/**
 * External dependencies
 */
import { TopBar, cn, type TopBarProps } from '@wedevs/plugin-ui';
import { Activity } from 'lucide-react';

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { isProOutdated } from '../data/plan';
import { DEFAULT_PLATFORM, type Route } from '../router';
import { HelpMenu } from './HelpMenu';

type NavItem = {
    value: string;
    label: string;
    hash: string;
};

const navItems = (): NavItem[] => [
    {
        value: 'dashboard',
        label: __( 'Dashboard', 'woocommerce-conversion-tracking' ),
        hash: '#/dashboard',
    },
    {
        value: 'platforms',
        label: __( 'Platforms', 'woocommerce-conversion-tracking' ),
        hash: `#/platforms/${ DEFAULT_PLATFORM }`,
    },
    {
        value: 'catalog',
        label: __( 'Catalog', 'woocommerce-conversion-tracking' ),
        hash: '#/catalog',
    },
    {
        value: 'log',
        label: __( 'Activity log', 'woocommerce-conversion-tracking' ),
        hash: '#/log',
    },
    {
        value: 'doctor',
        label: __( 'Doctor', 'woocommerce-conversion-tracking' ),
        hash: '#/doctor',
    },
    {
        value: 'settings',
        label: __( 'Settings', 'woocommerce-conversion-tracking' ),
        hash: '#/settings',
    },
];

/**
 * The nav tab a route lights up; What's new belongs to the dashboard.
 *
 * @param {Route} route Current route.
 */
const activeTab = ( route: Route ): string | null => {
    switch ( route.name ) {
        case 'whats-new':
            return 'dashboard';
        case 'onboarding':
            return null;
        default:
            return route.name;
    }
};

/**
 * plugin-ui TopBar with the logo, version pills, page nav and Help menu.
 *
 * @param {Object} props       Component props.
 * @param {Route}  props.route Current route.
 */
export const AppTopBar = ( { route }: { route: Route } ) => {
    const { version = '', proVersion = '' } = window.wcctAdmin ?? {};

    // The pills drop out below 1180px, as in the prototype.
    const versions: NonNullable< TopBarProps[ 'versions' ] > = [
        {
            version: sprintf(
                /* translators: %s: plugin version */
                __( 'Lite %s', 'woocommerce-conversion-tracking' ),
                version
            ),
            isPro: false,
            // TopBar's own classes drop the outline colour, so set it again.
            className: 'border-border! max-[1180px]:hidden',
        },
    ];

    if ( proVersion ) {
        versions.push( {
            version: isProOutdated()
                ? sprintf(
                      /* translators: %s: plugin version */
                      __( 'Pro %s, paused', 'woocommerce-conversion-tracking' ),
                      proVersion
                  )
                : sprintf(
                      /* translators: %s: plugin version */
                      __( 'Pro %s', 'woocommerce-conversion-tracking' ),
                      proVersion
                  ),
            isPro: true,
            // TopBar's own classes drop the outline colour, so set it again.
            className: 'border-border! max-[1180px]:hidden',
        } );
    }

    return (
        <TopBar
            // Nav and Help share the right slot, which grows to fill the bar.
            className="h-16.5 max-h-none items-center gap-6.25 border-b border-border px-8 py-0 md:py-0 max-[1180px]:gap-4.5 max-[1180px]:px-6 [&>div]:h-full [&>div]:items-center [&>div:first-child>div]:h-7.5 [&>div:last-child]:min-w-0 [&>div:last-child]:flex-1 [&>div:last-child>div]:w-full [&>div:last-child>div]:min-w-0"
            logo={
                <a
                    href="#/dashboard"
                    className="flex h-7.5 items-center gap-2.5 text-[15px] font-semibold whitespace-nowrap text-foreground no-underline hover:text-foreground focus:shadow-none"
                >
                    <span className="inline-flex size-7.5 items-center justify-center rounded-md bg-primary text-primary-foreground">
                        <Activity className="size-4.5" aria-hidden="true" />
                    </span>
                    <span className="max-[900px]:sr-only">
                        { __(
                            'Conversion Tracking',
                            'woocommerce-conversion-tracking'
                        ) }
                    </span>
                </a>
            }
            versions={ versions }
            rightSideComponents={
                <>
                    <nav
                        className="h-full min-w-0 self-stretch overflow-x-auto [scrollbar-width:none]"
                        aria-label={ __(
                            'Conversion Tracking pages',
                            'woocommerce-conversion-tracking'
                        ) }
                    >
                        { /* Links styled like plugin-ui's line Tabs; Tabs' roving focus would hide them from Tab. */ }
                        <ul className="m-0 flex h-full list-none items-stretch gap-1 p-0">
                            { navItems().map( ( item ) => {
                                const current =
                                    item.value === activeTab( route );

                                return (
                                    <li key={ item.value } className="m-0 flex">
                                        <a
                                            href={ item.hash }
                                            aria-current={
                                                current ? 'page' : undefined
                                            }
                                            className={ cn(
                                                'inline-flex items-center border-b-2 border-transparent px-2 text-sm font-medium whitespace-nowrap text-foreground/60 no-underline transition-all hover:text-foreground focus:shadow-none',
                                                current &&
                                                    'border-b-primary text-primary hover:text-primary'
                                            ) }
                                        >
                                            { item.label }
                                        </a>
                                    </li>
                                );
                            } ) }
                        </ul>
                    </nav>
                    <div className="ml-auto flex items-center">
                        <HelpMenu />
                    </div>
                </>
            }
        />
    );
};
