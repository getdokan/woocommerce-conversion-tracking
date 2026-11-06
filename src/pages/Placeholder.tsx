/**
 * External dependencies
 */
import { Button, Card, CardContent } from '@wedevs/plugin-ui';
import { Page } from '@wcct/components';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

export type PlaceholderProps = {
    title: string;
    description?: string;
};

/**
 * Stub for a page a later task builds; the classic screens stay reachable meanwhile.
 *
 * @param {PlaceholderProps} props Component props.
 */
const Placeholder = ( { title, description }: PlaceholderProps ) => {
    const classicUrl = window.wcctAdmin?.classicUrl ?? '';

    return (
        <Page title={ title } description={ description }>
            <Card data-testid="wcct-placeholder">
                <CardContent className="flex flex-wrap items-center justify-between gap-4">
                    <p className="m-0 text-sm text-muted-foreground">
                        { __(
                            'This page is coming with the new admin. Until then, change your settings on the classic screen.',
                            'woocommerce-conversion-tracking'
                        ) }
                    </p>
                    { classicUrl && (
                        <Button
                            variant="outline"
                            nativeButton={ false }
                            // eslint-disable-next-line jsx-a11y/anchor-has-content -- the button renders the label inside.
                            render={ <a href={ classicUrl } /> }
                        >
                            { __(
                                'Open the classic settings',
                                'woocommerce-conversion-tracking'
                            ) }
                        </Button>
                    ) }
                </CardContent>
            </Card>
        </Page>
    );
};

export default Placeholder;
