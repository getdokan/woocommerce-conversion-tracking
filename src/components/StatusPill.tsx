/**
 * External dependencies
 */
import type { ReactNode } from 'react';
import { Badge, cn } from '@wedevs/plugin-ui';

export type StatusTone = 'ok' | 'warn' | 'bad' | 'off';

const TONES: Record< StatusTone, string > = {
    ok: 'bg-tone-ok-bg text-tone-ok-fg before:bg-tone-ok-dot',
    warn: 'bg-tone-warn-bg text-tone-warn-fg before:bg-tone-warn-dot',
    bad: 'bg-tone-bad-bg text-tone-bad-fg before:bg-tone-bad-dot',
    off: 'bg-tone-off-bg text-tone-off-fg before:bg-tone-off-dot',
};

export type StatusPillProps = {
    tone: StatusTone;
    children: ReactNode;
    className?: string;
};

/**
 * plugin-ui Badge in a CT status tone, with its dot.
 *
 * @param {StatusPillProps} props Component props.
 */
export const StatusPill = ( {
    tone,
    children,
    className,
}: StatusPillProps ) => (
    <Badge
        variant="secondary"
        className={ cn(
            "border-transparent! before:size-1.5 before:shrink-0 before:rounded-full before:content-['']",
            TONES[ tone ],
            className
        ) }
    >
        { children }
    </Badge>
);
