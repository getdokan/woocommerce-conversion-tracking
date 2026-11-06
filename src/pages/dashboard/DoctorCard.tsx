/**
 * External dependencies
 */
import {
    Button,
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@wedevs/plugin-ui';
import { StatusPill } from '@wcct/components';

/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { openProblems, type DoctorReport } from '../../data/doctor';
import { CardSkeleton } from '../../shell/PageSkeleton';

/**
 * The dashboard's Tracking Doctor card: the last result, and "Doctor found something new" after a quiet re-check.
 *
 * @param {Object}            props         Component props.
 * @param {DoctorReport|null} props.report  Last report, null while it loads or when it failed.
 * @param {boolean}           props.loading Show a skeleton.
 */
export const DoctorCard = ( {
    report,
    loading,
}: {
    report: DoctorReport | null;
    loading: boolean;
} ) => {
    let body;

    if ( loading ) {
        body = <CardSkeleton />;
    } else if ( ! report?.ran_at ) {
        body = (
            <>
                <p className="m-0 text-[13px] text-muted-foreground">
                    { __(
                        'Want us to check your store for duplicate or broken tracking? Takes a few seconds and changes nothing.',
                        'woocommerce-conversion-tracking'
                    ) }
                </p>
                <Button
                    nativeButton={ false }
                    // eslint-disable-next-line jsx-a11y/anchor-has-content -- the button renders the label inside.
                    render={ <a href="#/doctor" /> }
                >
                    { __(
                        'Check my store',
                        'woocommerce-conversion-tracking'
                    ) }
                </Button>
            </>
        );
    } else {
        const problems = openProblems( report );

        body = (
            <>
                <div className="flex flex-wrap gap-2">
                    { report.new > 0 && (
                        <span data-testid="wcct-doctor-new">
                            <StatusPill tone="warn">
                                { __(
                                    'Doctor found something new',
                                    'woocommerce-conversion-tracking'
                                ) }
                            </StatusPill>
                        </span>
                    ) }
                    { problems.length > 0 ? (
                        <StatusPill tone="warn">
                            { sprintf(
                                /* translators: %d: number of findings */
                                _n(
                                    '%d thing worth a look',
                                    '%d things worth a look',
                                    problems.length,
                                    'woocommerce-conversion-tracking'
                                ),
                                problems.length
                            ) }
                        </StatusPill>
                    ) : (
                        <StatusPill tone="ok">
                            { __(
                                'Everything looks good',
                                'woocommerce-conversion-tracking'
                            ) }
                        </StatusPill>
                    ) }
                </div>
                { problems.length > 0 && (
                    <ul className="m-0 flex list-none flex-col gap-1 p-0 text-[13px] text-muted-foreground">
                        { problems.slice( 0, 3 ).map( ( finding ) => (
                            <li key={ finding.hash } className="m-0">
                                { finding.found }
                            </li>
                        ) ) }
                    </ul>
                ) }
                <Button
                    variant="outline"
                    nativeButton={ false }
                    // eslint-disable-next-line jsx-a11y/anchor-has-content -- the button renders the label inside.
                    render={ <a href="#/doctor" /> }
                >
                    { __(
                        'See the checklist',
                        'woocommerce-conversion-tracking'
                    ) }
                </Button>
            </>
        );
    }

    return (
        <Card data-testid="wcct-doctor-card">
            <CardHeader>
                <CardTitle>
                    <h2 className="m-0 text-base font-medium">
                        { __(
                            'Tracking Doctor',
                            'woocommerce-conversion-tracking'
                        ) }
                    </h2>
                </CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col items-start gap-3">
                { body }
            </CardContent>
        </Card>
    );
};
