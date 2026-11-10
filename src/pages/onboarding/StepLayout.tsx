/**
 * External dependencies
 */
import type { ReactNode } from 'react';
import { Button, Card, cn } from '@wedevs/plugin-ui';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

type StepLayoutProps = {
    step: number;
    title: ReactNode;
    lead?: ReactNode;
    // Above the title, like the Done step's tick.
    icon?: ReactNode;
    children?: ReactNode;
    // Footer, left to right: Back, a note, Skip for now, the main action.
    onBack?: () => void;
    note?: ReactNode;
    onSkip?: () => void;
    // A re-run says "Close without saving" instead.
    skipLabel?: string;
    action?: ReactNode;
};

/**
 * One wizard step: a card with the title and content, and the footer with its buttons.
 *
 * @param {StepLayoutProps} props Component props.
 */
export const StepLayout = ( {
    step,
    title,
    lead,
    icon,
    children,
    onBack,
    note,
    onSkip,
    skipLabel,
    action,
}: StepLayoutProps ) => (
    <div className="px-8 py-8 max-[782px]:px-4">
        <Card
            className="gap-0 py-0"
            aria-labelledby={ `wcct-onboarding-title-${ step }` }
            data-testid={ `wcct-onboarding-step-${ step }` }
        >
            <div className="flex flex-col gap-6 p-10 max-[782px]:p-6">
                <div className="flex flex-col gap-2">
                    { icon }
                    { /* Focused when the step changes, so screen readers follow. */ }
                    <h1
                        id={ `wcct-onboarding-title-${ step }` }
                        tabIndex={ -1 }
                        data-wcct-step-heading
                        className="m-0 p-0 text-[28px] leading-tight font-bold text-foreground"
                    >
                        { title }
                    </h1>
                    { lead && (
                        <p className="m-0 text-[15px] text-page-muted-foreground">
                            { lead }
                        </p>
                    ) }
                </div>
                { children }
            </div>
            <div className="flex flex-wrap items-center gap-3 border-t border-border px-10 py-4 max-[782px]:px-6">
                { onBack && (
                    <Button variant="ghost" onClick={ onBack }>
                        { __( 'Back', 'woocommerce-conversion-tracking' ) }
                    </Button>
                ) }
                { /* Always rendered, so changes to the note are announced. */ }
                <p
                    className="m-0 text-sm text-page-muted-foreground"
                    aria-live="polite"
                    data-testid="wcct-onboarding-note"
                >
                    { note }
                </p>
                { onSkip && (
                    <Button
                        variant="ghost"
                        className={ cn( action ? 'ml-auto' : '' ) }
                        onClick={ onSkip }
                    >
                        { skipLabel ??
                            __(
                                'Skip for now',
                                'woocommerce-conversion-tracking'
                            ) }
                    </Button>
                ) }
                { action && (
                    <div className={ cn( ! onSkip && 'ml-auto' ) }>
                        { action }
                    </div>
                ) }
            </div>
        </Card>
    </div>
);
