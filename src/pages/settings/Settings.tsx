/**
 * External dependencies
 */
import type { ComponentType } from 'react';
import { cn } from '@wedevs/plugin-ui';
import { Page, ProBadge } from '@wcct/components';
import { Bell, Crown, RefreshCw, Users } from 'lucide-react';

/**
 * WordPress dependencies
 */
import { useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { isPro } from '../../data/plan';
import { replaceHash } from '../../router';
import { pageInfo } from '../titles';
import { AccessSection } from './AccessSection';
import { PlanSection } from './PlanSection';
import { SectionCard } from './SectionCard';

type SectionId = 'alerts' | 'access' | 'setup' | 'plan';

type SectionInfo = {
    id: SectionId;
    label: string;
    description: string;
    Icon: ComponentType< { className?: string } >;
    pro?: boolean;
};

const sections = (): SectionInfo[] => [
    {
        id: 'alerts',
        label: __( 'Alerts', 'woocommerce-conversion-tracking' ),
        description: __(
            "We tell you when we couldn't confirm a sale was sent to a platform. Every alert ends in something you can do.",
            'woocommerce-conversion-tracking'
        ),
        Icon: Bell,
    },
    {
        id: 'access',
        label: __( 'Integration access', 'woocommerce-conversion-tracking' ),
        description: '',
        Icon: Users,
        pro: true,
    },
    {
        id: 'setup',
        label: __( 'Setup', 'woocommerce-conversion-tracking' ),
        description: __(
            'The guided setup you saw when you installed the plugin.',
            'woocommerce-conversion-tracking'
        ),
        Icon: RefreshCw,
    },
    {
        id: 'plan',
        label: __( 'Plan', 'woocommerce-conversion-tracking' ),
        description: '',
        Icon: Crown,
    },
];

/**
 * A section a later task builds.
 *
 * @param {Object}      props         Component props.
 * @param {SectionInfo} props.section Section.
 */
const ComingSection = ( { section }: { section: SectionInfo } ) => (
    <SectionCard
        id={ section.id }
        icon={ <section.Icon /> }
        title={ section.label }
        description={ section.description }
    >
        <p
            className="m-0 px-6 py-5 text-sm text-muted-foreground"
            data-testid="wcct-placeholder"
        >
            { __(
                'This section is coming with the new admin.',
                'woocommerce-conversion-tracking'
            ) }
        </p>
    </SectionCard>
);

/**
 * The open section.
 *
 * @param {Object}      props         Component props.
 * @param {SectionInfo} props.section Section.
 */
const SectionPanel = ( { section }: { section: SectionInfo } ) => {
    switch ( section.id ) {
        case 'access':
            return <AccessSection />;
        case 'plan':
            return <PlanSection />;
        default:
            return <ComingSection section={ section } />;
    }
};

/**
 * Settings: one section at a time, picked by #/settings/<section>; #/settings opens Alerts.
 *
 * @param {Object} props         Component props.
 * @param {string} props.section Section from the route.
 */
const Settings = ( { section }: { section: string } ) => {
    const all = sections();
    const known = all.find( ( item ) => item.id === section );
    const current = known ?? all[ 0 ];
    const { title, description } = pageInfo( {
        name: 'settings',
        section,
        query: '',
    } );
    const pro = isPro();

    useEffect( () => {
        if ( section && ! known ) {
            replaceHash( '#/settings' );
        }
    }, [ section, known ] );

    return (
        <Page title={ title } description={ description }>
            <div className="grid grid-cols-[240px_minmax(0,1fr)] items-start gap-6 max-[960px]:grid-cols-1">
                <nav
                    aria-label={ __(
                        'Settings sections',
                        'woocommerce-conversion-tracking'
                    ) }
                >
                    <ul className="m-0 flex list-none flex-col gap-1 p-0 max-[960px]:flex-row max-[960px]:flex-wrap">
                        { all.map( ( item ) => {
                            const active = item.id === current.id;

                            return (
                                <li key={ item.id } className="m-0">
                                    <a
                                        href={ `#/settings/${ item.id }` }
                                        aria-current={
                                            active ? 'page' : undefined
                                        }
                                        className={ cn(
                                            'flex items-center gap-2 rounded-lg border-l-2 border-transparent px-3 py-2 text-sm font-medium whitespace-nowrap text-foreground/80 no-underline hover:bg-background hover:text-foreground focus:shadow-none',
                                            active &&
                                                'border-l-primary bg-background text-foreground shadow-xs'
                                        ) }
                                    >
                                        <item.Icon
                                            className="size-4 shrink-0 text-muted-foreground"
                                            aria-hidden="true"
                                        />
                                        { item.label }
                                        { item.pro && ! pro && (
                                            <ProBadge className="ml-auto" />
                                        ) }
                                    </a>
                                </li>
                            );
                        } ) }
                    </ul>
                </nav>
                <div className="min-w-0">
                    <SectionPanel section={ current } />
                </div>
            </div>
        </Page>
    );
};

export default Settings;
