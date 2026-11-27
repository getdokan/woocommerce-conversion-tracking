/**
 * External dependencies
 */
import { Card, Skeleton } from '@wedevs/plugin-ui';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Full-page skeleton shown until the settings first load.
 */
export const PageSkeleton = () => (
    <div
        className="flex flex-col gap-6 px-8 py-8"
        role="status"
        aria-live="polite"
        aria-busy="true"
        data-testid="wcct-page-skeleton"
    >
        <span className="sr-only">
            { __( 'Loading…', 'woocommerce-conversion-tracking' ) }
        </span>
        <div className="flex flex-col gap-2">
            <Skeleton className="h-7.5 w-48" />
            <Skeleton className="h-4 w-72" />
        </div>
        <div className="grid grid-cols-4 gap-4 max-[1100px]:grid-cols-2">
            { [ 0, 1, 2, 3 ].map( ( key ) => (
                <Skeleton key={ key } className="h-23 rounded-xl" />
            ) ) }
        </div>
        <div className="grid grid-cols-[2fr_1fr] gap-4 max-[1100px]:grid-cols-1">
            <Card className="gap-4 px-6">
                <Skeleton className="h-5 w-56" />
                <Skeleton className="h-40 w-full" />
            </Card>
            <Card className="gap-4 px-6">
                <Skeleton className="h-5 w-36" />
                <Skeleton className="h-4 w-full" />
                <Skeleton className="h-9 w-32" />
            </Card>
        </div>
    </div>
);

/**
 * Skeleton lines for one card while its data refreshes.
 *
 * @param {Object} props       Component props.
 * @param {number} props.lines Number of text lines.
 */
export const CardSkeleton = ( { lines = 2 }: { lines?: number } ) => (
    <div
        className="flex flex-col gap-3"
        role="status"
        aria-busy="true"
        data-testid="wcct-card-skeleton"
    >
        <span className="sr-only">
            { __( 'Refreshing…', 'woocommerce-conversion-tracking' ) }
        </span>
        <Skeleton className="h-5 w-1/3" />
        { Array.from( { length: lines }, ( _, key ) => (
            <Skeleton key={ key } className="h-4 w-full" />
        ) ) }
    </div>
);
