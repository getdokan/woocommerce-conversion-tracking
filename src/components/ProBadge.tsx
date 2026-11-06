/**
 * External dependencies
 */
import { Badge, CrownIcon, cn } from '@wedevs/plugin-ui';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * The PRO marker on Pro-only items: CrownIcon and "PRO" on the PRO colour.
 *
 * @param {Object} props           Component props.
 * @param {string} props.className Extra classes.
 */
export const ProBadge = ( { className }: { className?: string } ) => (
    <Badge
        variant="secondary"
        className={ cn(
            'border-transparent! bg-pro pr-2 pl-1.5 text-[11px] font-semibold tracking-[0.04em] text-pro-foreground',
            className
        ) }
    >
        <CrownIcon className="size-3" />
        { __( 'PRO', 'woocommerce-conversion-tracking' ) }
    </Badge>
);
