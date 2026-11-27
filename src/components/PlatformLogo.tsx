/**
 * External dependencies
 */
import { cn } from '@wedevs/plugin-ui';

export type PlatformLogoProps = {
    src: string;
    // Greyed out for platforms that aren't set up.
    off?: boolean;
    className?: string;
};

/**
 * A platform mark in a 40px rounded frame.
 *
 * @param {PlatformLogoProps} props Component props.
 */
export const PlatformLogo = ( { src, off, className }: PlatformLogoProps ) => (
    <span
        className={ cn(
            'inline-flex size-10 shrink-0 items-center justify-center rounded-lg border border-border bg-background',
            className
        ) }
    >
        { src && (
            <img
                src={ src }
                alt=""
                className={ cn(
                    'size-6 object-contain',
                    off && 'opacity-70 grayscale'
                ) }
            />
        ) }
    </span>
);
