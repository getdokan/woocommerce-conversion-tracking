/**
 * External dependencies
 */
import { Card, CardContent, CardHeader, CardTitle } from '@wedevs/plugin-ui';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { NextStep } from '../../data/metrics';
import { CardSkeleton } from '../../shell/PageSkeleton';

/**
 * "What to do next": one action per amber state, from the metrics route; empty when everything is green.
 *
 * @param {Object}     props            Component props.
 * @param {NextStep[]} props.steps      Steps.
 * @param {boolean}    props.refreshing Show a skeleton.
 */
export const NextSteps = ( {
    steps,
    refreshing,
}: {
    steps: NextStep[];
    refreshing: boolean;
} ) => (
    <Card data-testid="wcct-next-steps">
        <CardHeader>
            <CardTitle>
                <h2 className="m-0 text-base font-medium">
                    { __(
                        'What to do next',
                        'woocommerce-conversion-tracking'
                    ) }
                </h2>
            </CardTitle>
        </CardHeader>
        <CardContent>
            { refreshing && <CardSkeleton lines={ 1 } /> }
            { ! refreshing && ! steps.length && (
                <p className="m-0 text-[13px] text-muted-foreground">
                    { __(
                        'Nothing to do right now. Steps show here when a platform needs attention.',
                        'woocommerce-conversion-tracking'
                    ) }
                </p>
            ) }
            { ! refreshing && steps.length > 0 && (
                <ol className="m-0 flex list-decimal flex-col gap-2 pl-5 text-[13px] text-foreground">
                    { steps.map( ( step ) => (
                        <li
                            key={ step.id }
                            className="m-0"
                            data-testid={ `wcct-next-step-${ step.id }` }
                        >
                            { step.text }{ ' ' }
                            { step.link && (
                                <a
                                    href={ step.link }
                                    className="font-medium text-primary underline underline-offset-3 hover:text-primary/80"
                                >
                                    { step.link_label }
                                </a>
                            ) }
                        </li>
                    ) ) }
                </ol>
            ) }
        </CardContent>
    </Card>
);
