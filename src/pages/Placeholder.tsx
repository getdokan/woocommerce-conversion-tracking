/**
 * External dependencies
 */
import { Card, CardContent } from '@wedevs/plugin-ui';
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
 * Stub for a page a later task builds.
 *
 * @param {PlaceholderProps} props Component props.
 */
const Placeholder = ( { title, description }: PlaceholderProps ) => (
    <Page title={ title } description={ description }>
        <Card data-testid="wcct-placeholder">
            <CardContent>
                <p className="m-0 text-sm text-muted-foreground">
                    { __(
                        'This page is coming in an update.',
                        'woocommerce-conversion-tracking'
                    ) }
                </p>
            </CardContent>
        </Card>
    </Page>
);

export default Placeholder;
