/**
 * External dependencies
 */
import { Button, Card, cn } from '@wedevs/plugin-ui';
import { Check, Clock } from 'lucide-react';

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { OnboardingState } from '../../data/types';

/**
 * Setup steps, done once the wizard went past the step that covers them.
 *
 * @param {OnboardingState} onboarding Wizard progress.
 */
const steps = ( { step }: OnboardingState ) => [
    {
        label: __( 'Plugin installed', 'woocommerce-conversion-tracking' ),
        done: true,
    },
    {
        label: __(
            'Pick where you run ads',
            'woocommerce-conversion-tracking'
        ),
        done: step > 2,
    },
    {
        label: __(
            'Connect your platforms, about 3 minutes',
            'woocommerce-conversion-tracking'
        ),
        done: step > 3,
    },
    {
        label: __(
            'Tell us if you sell to Europe',
            'woocommerce-conversion-tracking'
        ),
        done: step > 4,
    },
    {
        label: __(
            'See the first event arrive',
            'woocommerce-conversion-tracking'
        ),
        // Step 6 opens once Verify passes (#120).
        done: step > 5,
    },
];

/**
 * New install: the setup checklist and "Continue setup", which resumes the wizard at the furthest step reached.
 *
 * @param {Object}          props            Component props.
 * @param {OnboardingState} props.onboarding Wizard progress.
 */
export const WelcomeCard = ( {
    onboarding,
}: {
    onboarding: OnboardingState;
} ) => {
    // A finished setup with nothing connected has no step to resume; the Platforms page connects one.
    const done = onboarding.status === 'done';

    return (
        <Card
            className="flex-row flex-wrap items-center justify-between gap-7 p-6"
            aria-labelledby="wcct-welcome-title"
            data-testid="wcct-welcome"
        >
            <div className="flex min-w-65 flex-1 flex-col gap-3 text-sm">
                <h2
                    id="wcct-welcome-title"
                    className="m-0 text-lg font-semibold text-foreground"
                >
                    { __(
                        "Welcome. Let's get one platform working.",
                        'woocommerce-conversion-tracking'
                    ) }
                </h2>
                <p className="m-0 text-muted-foreground">
                    { done
                        ? __(
                              'Setup is finished, but no platform is connected yet. Connect one to start tracking.',
                              'woocommerce-conversion-tracking'
                          )
                        : __(
                              'Pick up setup where you left off. This page turns green when your store sends its first event. No warnings and no upsells until then.',
                              'woocommerce-conversion-tracking'
                          ) }
                </p>
                { ! done && (
                    <ul
                        className="m-0 flex list-none flex-col gap-2 p-0"
                        data-testid="wcct-setup-checklist"
                    >
                        { steps( onboarding ).map( ( step ) => (
                            <li
                                key={ step.label }
                                className="m-0 flex items-center gap-2.5"
                                data-done={ step.done || undefined }
                            >
                                <span
                                    className={ cn(
                                        'inline-flex size-5 shrink-0 items-center justify-center rounded-full',
                                        step.done
                                            ? 'bg-tone-ok-bg text-tone-ok-fg'
                                            : 'bg-muted text-muted-foreground'
                                    ) }
                                    aria-hidden="true"
                                >
                                    { step.done ? (
                                        <Check className="size-3" />
                                    ) : (
                                        <Clock className="size-3" />
                                    ) }
                                </span>
                                <span aria-hidden="true">{ step.label }</span>
                                <span className="sr-only">
                                    { step.done
                                        ? sprintf(
                                              /* translators: %s: setup step */
                                              __(
                                                  '%s, done',
                                                  'woocommerce-conversion-tracking'
                                              ),
                                              step.label
                                          )
                                        : step.label }
                                </span>
                            </li>
                        ) ) }
                    </ul>
                ) }
            </div>
            <Button
                size="lg"
                nativeButton={ false }
                // eslint-disable-next-line jsx-a11y/anchor-has-content -- the button renders the label inside.
                render={ <a href={ done ? '#/platforms' : '#/onboarding' } /> }
                className="px-3"
            >
                { done
                    ? __(
                          'Connect a platform',
                          'woocommerce-conversion-tracking'
                      )
                    : __(
                          'Continue setup',
                          'woocommerce-conversion-tracking'
                      ) }
            </Button>
        </Card>
    );
};
