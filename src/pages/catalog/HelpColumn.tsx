/**
 * External dependencies
 */
import type { ReactNode } from 'react';

/**
 * WordPress dependencies
 */
import { createInterpolateElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

const COMMERCE_MANAGER_URL = 'https://business.facebook.com/commerce/';

const bold = { b: <b /> };

/**
 * The setup steps with their <b> markup.
 */
const steps = (): ReactNode[] => [
    createInterpolateElement(
        __(
            'Open <a>Commerce Manager<span> (opens in a new tab)</span></a> and choose <b>Create catalog</b>.',
            'woocommerce-conversion-tracking'
        ),
        {
            ...bold,
            a: (
                // eslint-disable-next-line jsx-a11y/anchor-has-content -- the label is interpolated.
                <a
                    href={ COMMERCE_MANAGER_URL }
                    target="_blank"
                    rel="noreferrer"
                    className="text-primary underline underline-offset-3"
                />
            ),
            span: <span className="sr-only" />,
        }
    ),
    createInterpolateElement(
        __(
            'Pick <b>E-commerce</b> as the catalog type.',
            'woocommerce-conversion-tracking'
        ),
        bold
    ),
    __(
        'Name it, for example after your store.',
        'woocommerce-conversion-tracking'
    ),
    createInterpolateElement(
        __(
            'In the new catalog, open <b>Data sources</b> and choose <b>Add items</b>.',
            'woocommerce-conversion-tracking'
        ),
        bold
    ),
    createInterpolateElement(
        __(
            'Choose <b>Data feed</b>, then <b>Scheduled feed</b>.',
            'woocommerce-conversion-tracking'
        ),
        bold
    ),
    __(
        'Paste the feed URL from this page.',
        'woocommerce-conversion-tracking'
    ),
    createInterpolateElement(
        __(
            'Set a daily schedule and choose <b>Start upload</b>.',
            'woocommerce-conversion-tracking'
        ),
        bold
    ),
];

/**
 * The help column: connecting the feed in Commerce Manager.
 */
export const HelpColumn = () => (
    <aside
        className="flex flex-col gap-6 border-l border-border bg-muted/40 p-6 text-sm max-[1100px]:border-t max-[1100px]:border-l-0"
        aria-label={ __( 'Help', 'woocommerce-conversion-tracking' ) }
    >
        <div className="flex flex-col gap-2">
            <h3 className="m-0 text-sm font-semibold text-foreground">
                { __(
                    'Connect the feed in Commerce Manager',
                    'woocommerce-conversion-tracking'
                ) }
            </h3>
            <ol className="m-0 flex list-decimal flex-col gap-2 pl-5 text-foreground">
                { steps().map( ( step, index ) => (
                    <li key={ index } className="m-0">
                        { step }
                    </li>
                ) ) }
            </ol>
        </div>
        <div className="flex flex-col gap-2">
            <h3 className="m-0 text-sm font-semibold text-foreground">
                { __(
                    'After the first upload',
                    'woocommerce-conversion-tracking'
                ) }
            </h3>
            <p className="m-0 text-foreground">
                { createInterpolateElement(
                    __(
                        "Meta takes up to an hour to read your products. When you run the <a>Doctor</a>, it checks that the feed's product IDs match your pixel.",
                        'woocommerce-conversion-tracking'
                    ),
                    {
                        a: (
                            // eslint-disable-next-line jsx-a11y/anchor-has-content -- the label is interpolated.
                            <a
                                href="#/doctor"
                                className="text-primary underline underline-offset-3"
                            />
                        ),
                    }
                ) }
            </p>
        </div>
    </aside>
);
