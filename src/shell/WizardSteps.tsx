/**
 * External dependencies
 */
import { cn } from '@wedevs/plugin-ui';

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { stepNames } from '../data/onboarding';

/**
 * The setup wizard's steps in the top bar, in place of the page nav.
 *
 * @param {Object} props         Component props.
 * @param {number} props.current Current step, from 1.
 */
export const WizardSteps = ( { current }: { current: number } ) => (
    <ol
        className="m-0 flex h-full list-none items-center gap-7 p-0 max-[1180px]:gap-3.5"
        aria-label={ __( 'Setup steps', 'woocommerce-conversion-tracking' ) }
        data-testid="wcct-onboarding-steps"
    >
        { stepNames().map( ( name, index ) => {
            const step = index + 1;
            const done = step < current;
            const here = step === current;

            return (
                <li
                    key={ name }
                    aria-current={ here ? 'step' : undefined }
                    className={ cn(
                        'm-0 flex items-center gap-2 text-sm font-medium whitespace-nowrap',
                        here ? 'text-foreground' : 'text-muted-foreground'
                    ) }
                >
                    <b
                        className={ cn(
                            'inline-flex size-5.5 items-center justify-center rounded-full text-xs font-semibold',
                            here && 'bg-primary text-primary-foreground',
                            done && 'bg-tone-ok-bg text-tone-ok-fg',
                            ! here && ! done && 'bg-muted text-foreground'
                        ) }
                        aria-hidden="true"
                    >
                        { step }
                    </b>
                    <span
                        aria-hidden={ done || undefined }
                        className={ cn( ! here && 'max-[1180px]:sr-only' ) }
                    >
                        { name }
                    </span>
                    { done && (
                        <span className="sr-only">
                            { sprintf(
                                /* translators: %s: setup step */
                                __(
                                    '%s, done',
                                    'woocommerce-conversion-tracking'
                                ),
                                name
                            ) }
                        </span>
                    ) }
                </li>
            );
        } ) }
    </ol>
);
