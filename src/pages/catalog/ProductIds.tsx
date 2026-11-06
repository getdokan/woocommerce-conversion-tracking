/**
 * External dependencies
 */
import {
    Label,
    Notice,
    NoticeTitle,
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@wedevs/plugin-ui';
import { CircleCheck } from 'lucide-react';

/**
 * WordPress dependencies
 */
import { createInterpolateElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { ProductIdMode } from '../../data/catalog';
import { Section, SectionRow } from './Section';

const modeLabel = ( mode: ProductIdMode ): string =>
    mode === 'sku'
        ? __( 'SKU', 'woocommerce-conversion-tracking' )
        : __( 'Product ID', 'woocommerce-conversion-tracking' );

export type ProductIdsProps = {
    feedMode: ProductIdMode;
    pixelMode: ProductIdMode;
    onChange: ( mode: ProductIdMode ) => void;
};

/**
 * The ID the feed writes, next to the one Meta's pixel sends, with a warning when they differ.
 *
 * @param {ProductIdsProps} props Component props.
 */
export const ProductIds = ( {
    feedMode,
    pixelMode,
    onChange,
}: ProductIdsProps ) => {
    const items = [
        {
            value: 'id',
            label: __(
                'Product ID (recommended)',
                'woocommerce-conversion-tracking'
            ),
        },
        {
            value: 'sku',
            label: __(
                'SKU (the product ID when a product has none)',
                'woocommerce-conversion-tracking'
            ),
        },
    ];

    return (
        <Section
            id="wcct-catalog-ids"
            title={ __( 'Product IDs', 'woocommerce-conversion-tracking' ) }
            description={ __(
                'Meta matches pixel events to catalog items by ID, so the feed and the pixel must use the same one. Each variation of a variable product is its own item in the feed, grouped under its parent with item_group_id, and the pixel sends the variation the shopper picked.',
                'woocommerce-conversion-tracking'
            ) }
        >
            <SectionRow>
                <div className="flex max-w-md flex-col gap-1.5">
                    <Label htmlFor="wcct-feed-id-type">
                        { __(
                            'ID used in the feed',
                            'woocommerce-conversion-tracking'
                        ) }
                    </Label>
                    <Select
                        items={ items }
                        value={ feedMode }
                        onValueChange={ ( value ) =>
                            onChange( value === 'sku' ? 'sku' : 'id' )
                        }
                    >
                        <SelectTrigger
                            id="wcct-feed-id-type"
                            className="w-full"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            { items.map( ( item ) => (
                                <SelectItem
                                    key={ item.value }
                                    value={ item.value }
                                >
                                    { item.label }
                                </SelectItem>
                            ) ) }
                        </SelectContent>
                    </Select>
                </div>
            </SectionRow>
            <SectionRow className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex flex-col gap-0.5">
                    <span className="text-sm font-medium text-foreground">
                        { __(
                            'Your Meta pixel sends',
                            'woocommerce-conversion-tracking'
                        ) }
                    </span>
                    <span className="text-[13px] text-muted-foreground">
                        { __(
                            'Set in Meta, Advanced settings, Product ID sent.',
                            'woocommerce-conversion-tracking'
                        ) }
                    </span>
                </div>
                <span className="flex items-center gap-3 text-sm">
                    <b data-testid="wcct-pixel-id-mode">
                        { modeLabel( pixelMode ) }
                    </b>
                    <a
                        href="#/platforms/facebook"
                        className="text-primary underline underline-offset-3"
                    >
                        { createInterpolateElement(
                            __(
                                'Change<span> the Product ID sent by Meta</span>',
                                'woocommerce-conversion-tracking'
                            ),
                            { span: <span className="sr-only" /> }
                        ) }
                    </a>
                </span>
            </SectionRow>
            <SectionRow>
                { /* Polite: it changes as the select changes. */ }
                <div aria-live="polite">
                    { feedMode === pixelMode ? (
                        <p
                            className="m-0 flex items-center gap-2 text-[13px] text-tone-ok-fg"
                            data-testid="wcct-id-match"
                        >
                            <CircleCheck
                                aria-hidden="true"
                                className="size-4 shrink-0"
                            />
                            { __(
                                'They match, so Meta can tie ad clicks and purchases to products.',
                                'woocommerce-conversion-tracking'
                            ) }
                        </p>
                    ) : (
                        <Notice
                            variant="warning"
                            data-testid="wcct-id-mismatch"
                        >
                            <NoticeTitle>
                                { __(
                                    'The feed and the pixel use different IDs.',
                                    'woocommerce-conversion-tracking'
                                ) }
                            </NoticeTitle>
                            <p className="m-0">
                                { __(
                                    "Meta can't tie views and purchases to catalog items, so dynamic ads show the wrong products or none. Pick the same ID in both places. The Doctor checks this too.",
                                    'woocommerce-conversion-tracking'
                                ) }
                            </p>
                        </Notice>
                    ) }
                </div>
            </SectionRow>
        </Section>
    );
};
