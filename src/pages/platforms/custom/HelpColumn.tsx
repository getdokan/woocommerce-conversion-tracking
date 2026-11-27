/**
 * WordPress dependencies
 */
import { createInterpolateElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * The Custom script's help column: adding a tag and how often it runs.
 */
export const HelpColumn = () => (
    <aside
        className="flex flex-col gap-6 border-l border-border bg-muted/40 p-6 text-sm max-[1100px]:border-t max-[1100px]:border-l-0"
        aria-label={ __( 'Help', 'woocommerce-conversion-tracking' ) }
    >
        <div className="flex flex-col gap-2">
            <h3 className="m-0 text-sm font-semibold text-foreground">
                { __(
                    'Adding a custom script',
                    'woocommerce-conversion-tracking'
                ) }
            </h3>
            <ol className="m-0 flex list-decimal flex-col gap-2 pl-5 text-foreground">
                <li className="m-0">
                    { __(
                        "Copy the tag from the platform's setup page. Most give you a base tag and a purchase tag.",
                        'woocommerce-conversion-tracking'
                    ) }
                </li>
                <li className="m-0">
                    { createInterpolateElement(
                        __(
                            'Paste the base tag into <b>Every page, in the header</b>.',
                            'woocommerce-conversion-tracking'
                        ),
                        { b: <b /> }
                    ) }
                </li>
                <li className="m-0">
                    { createInterpolateElement(
                        __(
                            'Paste the purchase tag into <b>Thank-you page</b> and swap the fixed values for placeholders like <code>{order_total}</code>.',
                            'woocommerce-conversion-tracking'
                        ),
                        { b: <b />, code: <code className="font-mono" /> }
                    ) }
                </li>
            </ol>
        </div>
        <div className="flex flex-col gap-2">
            <h3 className="m-0 text-sm font-semibold text-foreground">
                { __( 'Counted once', 'woocommerce-conversion-tracking' ) }
            </h3>
            <p className="m-0 text-foreground">
                { __(
                    'The thank-you script runs once per order, even if the page reloads.',
                    'woocommerce-conversion-tracking'
                ) }
            </p>
        </div>
    </aside>
);
