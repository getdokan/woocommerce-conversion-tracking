/**
 * External dependencies
 */
import { cn } from '@wedevs/plugin-ui';
import { PlatformLogo, ProBadge, StatusPill } from '@wcct/components';
import { Check } from 'lucide-react';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { POPULAR } from '../../data/onboarding';
import type { PlatformCardInfo } from '../../data/platforms';

export const logoUrl = ( file: string ) =>
    file ? `${ window.wcctAdmin?.logosUrl ?? '' }${ file }` : '';

type PickTilesProps = {
    platforms: PlatformCardInfo[];
    picked: string[];
    onToggle: ( id: string ) => void;
};

/**
 * Step 2's multi-select tiles, one toggle button per platform.
 *
 * @param {PickTilesProps} props Component props.
 */
export const PickTiles = ( {
    platforms,
    picked,
    onToggle,
}: PickTilesProps ) => (
    <div className="grid grid-cols-3 gap-4 max-[1100px]:grid-cols-2 max-[600px]:grid-cols-1">
        { platforms.map( ( platform ) => {
            const pressed = picked.includes( platform.id );

            return (
                <button
                    key={ platform.id }
                    type="button"
                    aria-pressed={ pressed }
                    onClick={ () => onToggle( platform.id ) }
                    data-testid={ `wcct-pick-${ platform.id }` }
                    className={ cn(
                        'relative flex min-h-36 cursor-pointer flex-col items-start gap-3 rounded-xl border bg-card p-5 text-left shadow-xs transition-colors',
                        pressed
                            ? 'border-primary bg-primary/5'
                            : 'border-border hover:border-primary/30'
                    ) }
                >
                    <span
                        className={ cn(
                            'absolute top-4 right-4 inline-flex size-5 items-center justify-center rounded-full border',
                            pressed
                                ? 'border-primary bg-primary text-primary-foreground'
                                : 'border-input bg-background'
                        ) }
                        aria-hidden="true"
                    >
                        { pressed && <Check className="size-3.5" /> }
                    </span>
                    <PlatformLogo
                        src={ logoUrl( platform.logo ) }
                        className="mt-5 size-12"
                    />
                    <span>
                        <span className="flex items-center gap-2 text-base font-semibold text-foreground">
                            { platform.name }
                            { platform.locked && <ProBadge /> }
                        </span>
                        <span className="mt-0.5 block text-[13px] text-muted-foreground">
                            { platform.meta }
                        </span>
                    </span>
                    { /* After the name, so the button reads "Meta, …, Popular". */ }
                    { POPULAR.includes( platform.id ) && (
                        <StatusPill
                            tone="ok"
                            className="absolute top-4 left-4 before:hidden"
                        >
                            { __(
                                'Popular',
                                'woocommerce-conversion-tracking'
                            ) }
                        </StatusPill>
                    ) }
                </button>
            );
        } ) }
    </div>
);
