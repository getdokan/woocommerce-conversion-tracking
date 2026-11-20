/**
 * External dependencies
 */
import { Button } from '@wedevs/plugin-ui';
import { Check, Lock } from 'lucide-react';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { upgradeUrl } from '../../data/plan';
import { NewTabNote } from '../../shell/NewTabNote';

const FeatureList = ( { items }: { items: string[] } ) => (
    <ul className="m-0 flex list-none flex-col gap-2 p-0">
        { items.map( ( item ) => (
            <li
                key={ item }
                className="m-0 flex items-start gap-2 text-sm text-foreground"
            >
                <Check
                    aria-hidden="true"
                    className="mt-0.5 size-4 shrink-0 text-tone-ok-fg"
                />
                { item }
            </li>
        ) ) }
    </ul>
);

/**
 * Free's Catalog: what the Pro feed does and where to get it; no greyed-out settings.
 */
export const UpgradeCard = () => (
    <div
        className="grid grid-cols-[minmax(0,1fr)_320px] max-[1100px]:grid-cols-1"
        data-testid="wcct-catalog-upgrade"
    >
        <div className="flex flex-col gap-5 p-6">
            <div className="flex flex-col items-start gap-3">
                <span className="flex size-12 items-center justify-center rounded-full bg-muted text-muted-foreground">
                    <Lock aria-hidden="true" className="size-5" />
                </span>
                <h2 className="m-0 text-lg font-semibold text-foreground">
                    { __(
                        'The Meta catalog feed is part of Pro',
                        'woocommerce-conversion-tracking'
                    ) }
                </h2>
                <p className="m-0 text-sm text-muted-foreground">
                    { __(
                        'A product feed Meta reads on a schedule, so dynamic ads and shops always show the right item, price and stock. Your pixel and events keep working on Free.',
                        'woocommerce-conversion-tracking'
                    ) }
                </p>
            </div>
            <FeatureList
                items={ [
                    __(
                        'One feed URL that Meta refreshes every day',
                        'woocommerce-conversion-tracking'
                    ),
                    __(
                        'Leave out product types or categories',
                        'woocommerce-conversion-tracking'
                    ),
                    __(
                        'Product IDs that match what your pixel sends, checked by the Doctor',
                        'woocommerce-conversion-tracking'
                    ),
                ] }
            />
            <div className="flex flex-wrap items-center gap-2">
                <Button
                    nativeButton={ false }
                    render={
                        // eslint-disable-next-line jsx-a11y/anchor-has-content -- the button renders the label inside.
                        <a
                            href={ upgradeUrl() }
                            target="_blank"
                            rel="noreferrer"
                        />
                    }
                >
                    { __( 'Compare plans', 'woocommerce-conversion-tracking' ) }
                    <NewTabNote />
                </Button>
                <Button
                    variant="ghost"
                    nativeButton={ false }
                    // eslint-disable-next-line jsx-a11y/anchor-has-content -- the button renders the label inside.
                    render={ <a href="#/platforms/facebook" /> }
                >
                    { __(
                        'Continue with Free',
                        'woocommerce-conversion-tracking'
                    ) }
                </Button>
            </div>
        </div>
        <aside
            className="flex flex-col gap-3 border-l border-border bg-muted/40 p-6 max-[1100px]:border-t max-[1100px]:border-l-0"
            aria-labelledby="wcct-catalog-also-pro"
        >
            <h3
                id="wcct-catalog-also-pro"
                className="m-0 text-sm font-semibold text-foreground"
            >
                { __( 'Also in Pro', 'woocommerce-conversion-tracking' ) }
            </h3>
            <FeatureList
                items={ [
                    __(
                        'Microsoft Advertising',
                        'woocommerce-conversion-tracking'
                    ),
                    __(
                        'More than one Meta pixel',
                        'woocommerce-conversion-tracking'
                    ),
                    __(
                        'Role access for your team',
                        'woocommerce-conversion-tracking'
                    ),
                    __(
                        'Emails about not confirmed purchases and a weekly health email',
                        'woocommerce-conversion-tracking'
                    ),
                ] }
            />
        </aside>
    </div>
);
