/**
 * External dependencies
 */
import type { ReactNode } from 'react';
import { Button, CrownIcon } from '@wedevs/plugin-ui';
import { ProBadge, StatusPill } from '@wcct/components';
import { Crown } from 'lucide-react';

/**
 * WordPress dependencies
 */
import { createInterpolateElement } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import {
    isPro,
    isProOutdated,
    manageUrl,
    proUpdateUrl,
    upgradeUrl,
} from '../../data/plan';
import { NewTabNote } from '../../shell/NewTabNote';
import { FeatureList, LINK_CLASS, SectionCard } from './SectionCard';

const freeFeatures = () => [
    __( 'Meta, Google, X, custom scripts', 'woocommerce-conversion-tracking' ),
    __(
        'Counted once, failed orders skipped',
        'woocommerce-conversion-tracking'
    ),
    __( 'Respect cookie choices', 'woocommerce-conversion-tracking' ),
    __( 'Tracking Doctor', 'woocommerce-conversion-tracking' ),
    __( 'Activity log, 24 hours', 'woocommerce-conversion-tracking' ),
    __(
        'A badge for not confirmed purchases on the dashboard',
        'woocommerce-conversion-tracking'
    ),
];

const proFeatures = () => [
    __( 'Microsoft Advertising', 'woocommerce-conversion-tracking' ),
    __(
        'More than one Meta pixel, each with its own events',
        'woocommerce-conversion-tracking'
    ),
    __(
        'Richer customer matching, Enhanced conversions',
        'woocommerce-conversion-tracking'
    ),
    __(
        'Extra events: View category, Add to wishlist, Registration',
        'woocommerce-conversion-tracking'
    ),
    __( 'Custom parameters on any event', 'woocommerce-conversion-tracking' ),
    <a key="catalog" href="#/catalog" className={ LINK_CLASS }>
        { __( 'Meta catalog feed', 'woocommerce-conversion-tracking' ) }
    </a>,
    <a key="access" href="#/settings/access" className={ LINK_CLASS }>
        { __(
            'Choose which roles can manage tracking',
            'woocommerce-conversion-tracking'
        ) }
    </a>,
];

/**
 * Text link to the Pro page on WooCommerce.com.
 *
 * @param {Object}    props          Component props.
 * @param {ReactNode} props.children Link text, filled by createInterpolateElement.
 */
const UpgradeLink = ( { children }: { children?: ReactNode } ) => (
    <a
        href={ upgradeUrl() }
        target="_blank"
        rel="noreferrer"
        className={ LINK_CLASS }
    >
        { children }
        <NewTabNote />
    </a>
);

/**
 * The Plan section's description: on Pro, on Free, or on Free with an older Pro paused.
 *
 * @param {boolean} pro      A Pro built for this Free is running.
 * @param {boolean} outdated An older Pro is installed and paused.
 */
const planDescription = ( pro: boolean, outdated: boolean ): string => {
    if ( pro ) {
        return __(
            "You're on Pro. Thanks for supporting the plugin.",
            'woocommerce-conversion-tracking'
        );
    }

    if ( outdated ) {
        return sprintf(
            /* translators: %s: installed Pro version */
            __(
                'Pro %s is installed but paused until you update it to 3.0. Free keeps tracking meanwhile.',
                'woocommerce-conversion-tracking'
            ),
            window.wcctAdmin?.proVersion ?? ''
        );
    }

    return __(
        "You're on Free. Here's what Pro adds.",
        'woocommerce-conversion-tracking'
    );
};

/**
 * Update Pro: an older Pro is installed and paused.
 */
const UpdateProButton = () => (
    <Button
        nativeButton={ false }
        className="border-transparent bg-pro text-pro-foreground hover:bg-pro/85"
        render={
            // eslint-disable-next-line jsx-a11y/anchor-has-content, jsx-a11y/no-redundant-roles -- the button renders the label; Base UI would make it role=button.
            <a href={ proUpdateUrl() } role="link" />
        }
    >
        { __( 'Update Pro', 'woocommerce-conversion-tracking' ) }
    </Button>
);

/**
 * Settings > Plan: Free vs Pro, with the way to buy or manage Pro on WooCommerce.com.
 */
export const PlanSection = () => {
    const pro = isPro();
    const outdated = isProOutdated();

    return (
        <SectionCard
            id="plan"
            icon={ <Crown /> }
            title={ __( 'Plan', 'woocommerce-conversion-tracking' ) }
            description={ planDescription( pro, outdated ) }
        >
            <div className="grid grid-cols-2 max-[900px]:grid-cols-1">
                <div
                    className="flex flex-col items-start gap-3 p-6"
                    data-testid="wcct-plan-free"
                >
                    <div>
                        <h3 className="m-0 p-0 text-base font-semibold text-foreground">
                            { __( 'Free', 'woocommerce-conversion-tracking' ) }
                        </h3>
                        <p className="m-0 mt-1 text-[13px] text-muted-foreground">
                            { __(
                                'Tracking that works',
                                'woocommerce-conversion-tracking'
                            ) }
                        </p>
                    </div>
                    <FeatureList items={ freeFeatures() } />
                    { ! pro && (
                        <StatusPill tone="ok">
                            { __(
                                'Your plan',
                                'woocommerce-conversion-tracking'
                            ) }
                        </StatusPill>
                    ) }
                </div>
                <div
                    className="flex flex-col items-start gap-3 border-l border-border bg-pro/8 p-6 max-[900px]:border-t max-[900px]:border-l-0"
                    data-testid="wcct-plan-pro"
                >
                    <div>
                        <h3 className="m-0 flex items-center gap-2 p-0 text-base font-semibold text-foreground">
                            { __( 'Pro', 'woocommerce-conversion-tracking' ) }
                            <ProBadge />
                        </h3>
                        <p className="m-0 mt-1 text-[13px] text-muted-foreground">
                            { __(
                                'Everything in Free, plus',
                                'woocommerce-conversion-tracking'
                            ) }
                        </p>
                    </div>
                    <FeatureList items={ proFeatures() } />
                    { pro && (
                        <a
                            href={ manageUrl() }
                            target="_blank"
                            rel="noreferrer"
                            className={ `${ LINK_CLASS } text-sm` }
                        >
                            { __(
                                'Manage your subscription on WooCommerce.com',
                                'woocommerce-conversion-tracking'
                            ) }
                            <NewTabNote />
                        </a>
                    ) }
                    { outdated && <UpdateProButton /> }
                    { ! pro && ! outdated && (
                        <div className="flex flex-col items-start gap-2">
                            <Button
                                nativeButton={ false }
                                className="border-transparent bg-pro text-pro-foreground hover:bg-pro/85"
                                render={
                                    // eslint-disable-next-line jsx-a11y/anchor-has-content, jsx-a11y/no-redundant-roles -- the button renders the label; Base UI would make it role=button.
                                    <a
                                        href={ upgradeUrl() }
                                        target="_blank"
                                        rel="noreferrer"
                                        role="link"
                                    />
                                }
                            >
                                { __(
                                    'Upgrade to Pro',
                                    'woocommerce-conversion-tracking'
                                ) }
                                <CrownIcon
                                    className="size-4"
                                    aria-hidden="true"
                                />
                                <NewTabNote />
                            </Button>
                            <p className="m-0 text-xs text-muted-foreground">
                                { createInterpolateElement(
                                    __(
                                        "<a>See pricing</a> on WooCommerce.com. Free keeps working if you don't upgrade.",
                                        'woocommerce-conversion-tracking'
                                    ),
                                    { a: <UpgradeLink /> }
                                ) }
                            </p>
                        </div>
                    ) }
                </div>
            </div>
        </SectionCard>
    );
};
