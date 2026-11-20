/**
 * External dependencies
 */
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    Badge,
    Button,
    Card,
    Spinner,
    cn,
} from '@wedevs/plugin-ui';
import { StatusPill, type StatusTone } from '@wcct/components';

/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { Finding } from '../../data/doctor';
import { NewTabNote } from '../../shell/NewTabNote';

export type FindingCardProps = {
    finding: Finding;
    // The finding's check is running again.
    checking: boolean;
    onRecheck: ( finding: Finding ) => void;
    onDismiss: ( finding: Finding ) => void;
};

/**
 * The pill: the finding's category in a warning tone; unsure findings and notes stay grey.
 *
 * @param {Finding} finding Finding.
 */
const pill = ( finding: Finding ): { tone: StatusTone; text: string } => {
    if ( finding.severity === 'info' ) {
        return {
            tone: 'off',
            text:
                finding.label ||
                __( 'Good to know', 'woocommerce-conversion-tracking' ),
        };
    }

    if ( finding.confidence === 'unsure' ) {
        return {
            tone: 'off',
            text: __( 'Not sure', 'woocommerce-conversion-tracking' ),
        };
    }

    return {
        tone: 'warn',
        text:
            finding.label ||
            __( 'Worth a look', 'woocommerce-conversion-tracking' ),
    };
};

/**
 * Open a link the way the finding asks: app routes and admin pages in this tab, external pages in a new one.
 *
 * @param {Finding} finding Finding.
 */
const follow = ( finding: Finding ) => {
    if ( finding.action.external ) {
        window.open( finding.action.url, '_blank', 'noopener,noreferrer' );
        return;
    }

    window.location.assign( finding.action.url );
};

/**
 * The finding's one action: Check again, a link, or a link behind a confirmation.
 *
 * @param {FindingCardProps} props Component props.
 */
const FindingAction = ( {
    finding,
    checking,
    onRecheck,
}: FindingCardProps ) => {
    const [ confirming, setConfirming ] = useState( false );
    const { action } = finding;
    const variant = finding.confidence === 'unsure' ? 'outline' : 'default';

    if ( action.type === 'recheck' ) {
        return (
            <Button
                variant={ variant }
                disabled={ checking }
                onClick={ () => onRecheck( finding ) }
            >
                { checking && <Spinner aria-hidden="true" /> }
                { checking
                    ? __( 'Checking…', 'woocommerce-conversion-tracking' )
                    : action.label ||
                      __( 'Check again', 'woocommerce-conversion-tracking' ) }
            </Button>
        );
    }

    if ( action.confirm ) {
        return (
            <>
                <Button
                    variant={ variant }
                    onClick={ () => setConfirming( true ) }
                >
                    { action.label }
                </Button>
                <AlertDialog open={ confirming } onOpenChange={ setConfirming }>
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>
                                { action.label }
                            </AlertDialogTitle>
                            <AlertDialogDescription>
                                { action.confirm }
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel>
                                { __(
                                    'Cancel',
                                    'woocommerce-conversion-tracking'
                                ) }
                            </AlertDialogCancel>
                            <AlertDialogAction
                                onClick={ () => {
                                    setConfirming( false );
                                    follow( finding );
                                } }
                            >
                                { __(
                                    'Continue',
                                    'woocommerce-conversion-tracking'
                                ) }
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
            </>
        );
    }

    return (
        <Button
            variant={ variant }
            nativeButton={ false }
            render={
                // eslint-disable-next-line jsx-a11y/anchor-has-content, jsx-a11y/no-redundant-roles -- the button renders the label; Base UI would make it role=button.
                <a
                    href={ action.url }
                    role="link"
                    { ...( action.external
                        ? { target: '_blank', rel: 'noreferrer' }
                        : {} ) }
                />
            }
        >
            { action.label }
            { action.external && <NewTabNote /> }
        </Button>
    );
};

/**
 * One finding: what we found, why it matters, the steps, one action and Dismiss.
 *
 * @param {FindingCardProps} props Component props.
 */
export const FindingCard = ( props: FindingCardProps ) => {
    const { finding, onDismiss } = props;
    const { tone, text } = pill( finding );
    const titleId = `wcct-finding-${ finding.hash }`;
    const tags = [ ...finding.tags ];

    // The pill already says "Not sure" for the others.
    if ( finding.severity === 'warning' && finding.confidence === 'sure' ) {
        tags.push( __( 'Sure', 'woocommerce-conversion-tracking' ) );
    }

    return (
        <Card
            data-testid="wcct-finding"
            data-check={ finding.check }
            role="group"
            aria-labelledby={ titleId }
            className={ cn(
                'flex-row items-start gap-4 p-5 max-[782px]:flex-col',
                finding.confidence === 'unsure' && 'bg-muted/50'
            ) }
        >
            <StatusPill tone={ tone } className="mt-0.5 shrink-0">
                { text }
            </StatusPill>
            <div className="flex min-w-0 flex-1 flex-col gap-2">
                <h3
                    id={ titleId }
                    className="m-0 p-0 text-base font-semibold text-foreground"
                >
                    { finding.found }
                </h3>
                { ( tags.length > 0 || finding.new ) && (
                    <div className="flex flex-wrap gap-1.5">
                        { finding.new && (
                            <Badge variant="warning">
                                { __(
                                    'New',
                                    'woocommerce-conversion-tracking'
                                ) }
                            </Badge>
                        ) }
                        { tags.map( ( tag ) => (
                            <Badge key={ tag } variant="secondary">
                                { tag }
                            </Badge>
                        ) ) }
                    </div>
                ) }
                { finding.why && (
                    <p className="m-0 text-sm text-tone-off-fg">
                        { finding.why }
                    </p>
                ) }
                { finding.steps.length > 0 && (
                    <ol className="m-0 flex list-decimal flex-col gap-1 pl-5 text-sm">
                        { finding.steps.map( ( step, index ) => (
                            <li key={ index } className="m-0">
                                { step }
                            </li>
                        ) ) }
                    </ol>
                ) }
            </div>
            <div className="flex shrink-0 flex-col items-stretch gap-2">
                { ( finding.action.label ||
                    finding.action.type === 'recheck' ) && (
                    <FindingAction { ...props } />
                ) }
                <Button
                    variant="link"
                    size="sm"
                    className="text-muted-foreground"
                    data-dismiss={ finding.hash }
                    onClick={ () => onDismiss( finding ) }
                    aria-describedby={ titleId }
                >
                    { __( 'Dismiss', 'woocommerce-conversion-tracking' ) }
                </Button>
            </div>
        </Card>
    );
};
