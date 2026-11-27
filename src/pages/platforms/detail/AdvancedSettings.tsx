/**
 * External dependencies
 */
import type { ReactNode } from 'react';
import { ProBadge } from '@wcct/components';
import { ChevronRight, Lock, Terminal } from 'lucide-react';

/**
 * WordPress dependencies
 */
import { createInterpolateElement } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { isPro, upgradeUrl } from '../../../data/plan';
import type { SchemaPlatform } from '../../../data/types';
import { NewTabNote } from '../../../shell/NewTabNote';

/**
 * A Pro feature Free shows locked, with its upgrade link.
 *
 * @param {Object} props             Component props.
 * @param {string} props.title       Title.
 * @param {string} props.description Description.
 */
const LockedRow = ( {
    title,
    description,
}: {
    title: string;
    description: string;
} ) => (
    <div className="flex items-center gap-3.5 border-t border-border px-4 py-3.5 first:border-t-0">
        <Lock
            className="size-4 shrink-0 text-muted-foreground"
            aria-hidden="true"
        />
        <div className="min-w-0 flex-1">
            <div className="flex items-center gap-2 text-sm font-medium text-foreground">
                { title }
                <ProBadge />
            </div>
            <div className="text-[13px] text-muted-foreground">
                { description }
            </div>
        </div>
        <a
            href={ upgradeUrl() }
            target="_blank"
            rel="noreferrer"
            aria-label={ sprintf(
                /* translators: %s: Pro feature, e.g. Custom parameters */
                __(
                    'Upgrade to get %s (opens in a new tab)',
                    'woocommerce-conversion-tracking'
                ),
                title
            ) }
            className="shrink-0 text-[13px] font-medium text-primary underline underline-offset-3"
        >
            { __( 'Upgrade', 'woocommerce-conversion-tracking' ) }
            <NewTabNote />
        </a>
    </div>
);

/**
 * Meta's Pro features in Free: custom parameters, richer matching and more pixels.
 */
export const MetaProPreview = () => (
    <div
        className="rounded-lg border border-border"
        data-testid="wcct-facebook-pro-preview"
    >
        <LockedRow
            title={ __(
                'Custom parameters',
                'woocommerce-conversion-tracking'
            ) }
            description={ __(
                'Up to 5 extra fields, each sent with the event you pick.',
                'woocommerce-conversion-tracking'
            ) }
        />
        <LockedRow
            title={ __(
                'Richer customer matching',
                'woocommerce-conversion-tracking'
            ) }
            description={ __(
                "Hashed address and customer ID for logged-in shoppers, so Meta matches more sales. Hashed before it's sent to the platform.",
                'woocommerce-conversion-tracking'
            ) }
        />
        <LockedRow
            title={ __(
                'More than one pixel',
                'woocommerce-conversion-tracking'
            ) }
            description={ __(
                'Run a second pixel, for an agency or a second ad account, with its own events.',
                'woocommerce-conversion-tracking'
            ) }
        />
    </div>
);

type DeveloperInfo = {
    key: ReactNode;
    eventIds: string[];
    filters: string[];
};

/**
 * The integration key, event IDs and hooks a developer needs for a platform.
 *
 * @param {SchemaPlatform} platform Platform schema.
 */
const developerInfo = ( platform: SchemaPlatform ): DeveloperInfo => {
    const integrations = platform.integrations.filter(
        ( id ) => id !== platform.id
    );
    const code = ( value: string ) => <code key={ value }>{ value }</code>;

    return {
        key:
            integrations.length > 0 ? (
                <>
                    { code( platform.id ) }{ ' ' }
                    { __(
                        'in the settings API; underneath, the integrations',
                        'woocommerce-conversion-tracking'
                    ) }{ ' ' }
                    { integrations.map( code ) }
                </>
            ) : (
                code( platform.id )
            ),
        eventIds: [ 'wcct_{order_id}_purchase', 'wcct_<uuid>' ],
        filters: [
            'wcct_event_value',
            'wcct_value_mode',
            'wcct_product_id_mode',
            'wcct_skip_roles',
            'wcct_page_events',
            'wcct_platform_paused',
            'wcct_consent_allows',
            'wcct_platforms',
        ],
    };
};

/**
 * "For developers": the real integration IDs, the event IDs and the hook names, read-only.
 *
 * @param {Object}         props          Component props.
 * @param {SchemaPlatform} props.platform Platform schema.
 */
export const DeveloperBlock = ( {
    platform,
}: {
    platform: SchemaPlatform;
} ) => {
    const info = developerInfo( platform );
    const titleId = `wcct-${ platform.id }-dev-title`;
    const docs = window.wcctAdmin?.docs.home ?? '';

    return (
        <section
            aria-labelledby={ titleId }
            className="flex flex-col gap-2.5 border-t border-border pt-5 [&_code]:rounded [&_code]:bg-muted [&_code]:px-1.5 [&_code]:py-0.5 [&_code]:font-mono [&_code]:text-[12px] [&_code]:text-foreground"
            data-testid={ `wcct-${ platform.id }-developer` }
        >
            <h3
                id={ titleId }
                className="m-0 flex items-center gap-2 text-sm font-semibold text-foreground"
            >
                <Terminal className="size-4" aria-hidden="true" />
                { __( 'For developers', 'woocommerce-conversion-tracking' ) }
            </h3>
            <dl className="m-0 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-[13px] text-muted-foreground">
                <dt className="text-tone-off-fg">
                    { __(
                        'Integration key',
                        'woocommerce-conversion-tracking'
                    ) }
                </dt>
                <dd className="m-0 flex flex-wrap items-center gap-1">
                    { info.key }
                </dd>
                <dt className="text-tone-off-fg">
                    { __( 'Event IDs', 'woocommerce-conversion-tracking' ) }
                </dt>
                <dd className="m-0">
                    { createInterpolateElement(
                        __(
                            '<purchase /> for Purchase; <other /> for other events.',
                            'woocommerce-conversion-tracking'
                        ),
                        {
                            purchase: <code>{ info.eventIds[ 0 ] }</code>,
                            other: <code>{ info.eventIds[ 1 ] }</code>,
                        }
                    ) }
                </dd>
                <dt className="text-tone-off-fg">
                    { __( 'Filters', 'woocommerce-conversion-tracking' ) }
                </dt>
                <dd className="m-0 flex flex-wrap gap-1">
                    { info.filters.map( ( hook ) => (
                        <code key={ hook }>{ hook }</code>
                    ) ) }
                </dd>
                <dt className="text-tone-off-fg">
                    { __( 'Terms', 'woocommerce-conversion-tracking' ) }
                </dt>
                <dd className="m-0">
                    { __(
                        '"Counted once" = deduplication by event ID. "Respect cookie choices" = Consent Mode v2 and the WP Consent API.',
                        'woocommerce-conversion-tracking'
                    ) }
                </dd>
            </dl>
            { docs && (
                <a
                    href={ docs }
                    target="_blank"
                    rel="noreferrer"
                    className="self-start text-[13px] text-primary underline underline-offset-3"
                >
                    { __(
                        'Hook reference',
                        'woocommerce-conversion-tracking'
                    ) }
                    <NewTabNote />
                </a>
            ) }
        </section>
    );
};

type AdvancedSettingsProps = {
    platform: SchemaPlatform;
    open: boolean;
    onToggle: ( open: boolean ) => void;
    subtitle: string;
    children: ReactNode;
};

/**
 * The collapsed "Advanced settings" section of a platform page.
 *
 * @param {AdvancedSettingsProps} props Component props.
 */
export const AdvancedSettings = ( {
    platform,
    open,
    onToggle,
    subtitle,
    children,
}: AdvancedSettingsProps ) => (
    <details
        className="group rounded-lg border border-border"
        open={ open }
        onToggle={ ( event ) =>
            onToggle( ( event.currentTarget as HTMLDetailsElement ).open )
        }
        id={ `wcct-${ platform.id }-advanced` }
        data-testid={ `wcct-${ platform.id }-advanced` }
    >
        <summary className="flex cursor-pointer list-none items-center gap-2.5 rounded-lg px-4 py-3.5 text-sm font-semibold text-foreground focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none [&::-webkit-details-marker]:hidden">
            <ChevronRight
                className="size-4 text-muted-foreground transition-transform group-open:rotate-90"
                aria-hidden="true"
            />
            { __( 'Advanced settings', 'woocommerce-conversion-tracking' ) }
            <span className="text-[13px] font-normal text-muted-foreground">
                { subtitle }
            </span>
        </summary>
        <div className="flex flex-col gap-6 border-t border-border px-4 py-5">
            { children }
        </div>
    </details>
);

/**
 * Whether Free shows Meta's locked Pro rows.
 *
 * @param {string} id Platform ID.
 */
export const showsMetaPreview = ( id: string ): boolean =>
    id === 'facebook' && ! isPro();
