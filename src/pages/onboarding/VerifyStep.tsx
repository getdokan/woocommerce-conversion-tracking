/**
 * External dependencies
 */
import { Button } from '@wedevs/plugin-ui';
import { ExternalLink, LoaderCircle } from 'lucide-react';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { PlatformCardInfo } from '../../data/platforms';
import { NewTabNote } from '../../shell/NewTabNote';
import { StepLayout } from './StepLayout';

export type VerifyStepProps = {
    // Platforms saved as connected on step 3.
    connected: PlatformCardInfo[];
    onBack: () => void;
    onSkip: () => void;
    onNext: () => void;
};

/**
 * Step 5, "Check it works"; #120 adds the test link and waits for the check-in before Continue.
 *
 * @param {VerifyStepProps} props Component props.
 */
export const VerifyStep = ( {
    connected,
    onBack,
    onSkip,
    onNext,
}: VerifyStepProps ) => (
    <StepLayout
        step={ 5 }
        title={ __( 'Check it works', 'woocommerce-conversion-tracking' ) }
        lead={ __(
            'Open your store and look around. Each platform starts sending as soon as a page loads.',
            'woocommerce-conversion-tracking'
        ) }
        onBack={ onBack }
        onSkip={ onSkip }
        action={
            <Button onClick={ onNext }>
                { __( 'Continue', 'woocommerce-conversion-tracking' ) }
            </Button>
        }
    >
        <div>
            <Button
                variant="outline"
                nativeButton={ false }
                render={
                    // eslint-disable-next-line jsx-a11y/anchor-has-content -- the button renders the label inside.
                    <a
                        href={ window.wcctAdmin?.homeUrl ?? '/' }
                        target="_blank"
                        rel="noreferrer"
                    />
                }
            >
                <ExternalLink aria-hidden="true" />
                { __( 'Open your store', 'woocommerce-conversion-tracking' ) }
                <NewTabNote />
            </Button>
        </div>
        { connected.length ? (
            <ul className="m-0 flex max-w-140 list-none flex-col gap-3 p-0">
                { connected.map( ( platform ) => (
                    <li
                        key={ platform.id }
                        className="m-0 flex items-center gap-3.5 rounded-xl border border-border px-4 py-3.5"
                    >
                        <span
                            className="inline-flex size-9 shrink-0 items-center justify-center rounded-full bg-tone-off-bg text-tone-off-fg"
                            aria-hidden="true"
                        >
                            <LoaderCircle className="size-4.5" />
                        </span>
                        <span className="flex flex-col text-sm">
                            <b>{ platform.name }</b>
                            <span className="text-tone-off-fg">
                                { __(
                                    'Waiting for the first event…',
                                    'woocommerce-conversion-tracking'
                                ) }
                            </span>
                        </span>
                    </li>
                ) ) }
            </ul>
        ) : (
            <p className="m-0 text-[13px] text-muted-foreground">
                { __(
                    'No platform is connected yet. Go back to connect one, or skip for now.',
                    'woocommerce-conversion-tracking'
                ) }
            </p>
        ) }
    </StepLayout>
);
