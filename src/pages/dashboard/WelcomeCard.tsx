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
 * Setup steps; only "Plugin installed" is known until the wizard stores its progress.
 */
const steps = () => [
    {
        label: __( 'Plugin installed', 'woocommerce-conversion-tracking' ),
        done: true,
    },
    {
        label: __(
            'Pick where you run ads',
            'woocommerce-conversion-tracking'
        ),
        done: false,
    },
    {
        label: __(
            'Connect your platforms, about 3 minutes',
            'woocommerce-conversion-tracking'
        ),
        done: false,
    },
    {
        label: __(
            'Tell us if you sell to Europe',
            'woocommerce-conversion-tracking'
        ),
        done: false,
    },
    {
        label: __(
            'See the first event arrive',
            'woocommerce-conversion-tracking'
        ),
        done: false,
    },
];

/**
 * New install: the setup checklist and "Continue setup".
 */
export const WelcomeCard = () => (
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
                { __(
                    'Pick up setup where you left off. This page turns green when your store sends its first event. No warnings and no upsells until then.',
                    'woocommerce-conversion-tracking'
                ) }
            </p>
            <ul className="m-0 flex list-none flex-col gap-2 p-0">
                { steps().map( ( step ) => (
                    <li
                        key={ step.label }
                        className="m-0 flex items-center gap-2.5"
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
        </div>
        <Button
            size="lg"
            nativeButton={ false }
            // eslint-disable-next-line jsx-a11y/anchor-has-content -- the button renders the label inside.
            render={ <a href="#/onboarding" /> }
            className="px-3"
        >
            { __( 'Continue setup', 'woocommerce-conversion-tracking' ) }
        </Button>
    </Card>
);
