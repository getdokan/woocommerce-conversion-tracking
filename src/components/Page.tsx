/**
 * External dependencies
 */
import type { ReactNode } from 'react';
import { cn } from '@wedevs/plugin-ui';

export type PageProps = {
    title: ReactNode;
    description?: ReactNode;
    actions?: ReactNode;
    className?: string;
    children?: ReactNode;
};

/**
 * A full-width page under the top bar: title, description, actions and content.
 *
 * @param {PageProps} props Component props.
 */
export const Page = ( {
    title,
    description,
    actions,
    className,
    children,
}: PageProps ) => (
    <div className={ cn( 'flex flex-col gap-6 px-8 py-8', className ) }>
        <div className="flex flex-wrap items-start justify-between gap-4">
            <div>
                { /* Focused after a route change, so screen readers hear the new page. */ }
                <h1
                    tabIndex={ -1 }
                    className="m-0 p-0 text-2xl leading-tight font-bold text-foreground"
                >
                    { title }
                </h1>
                { description && (
                    <p className="mt-1 text-sm text-page-muted-foreground">
                        { description }
                    </p>
                ) }
            </div>
            { actions && (
                <div className="flex items-center gap-2.5">{ actions }</div>
            ) }
        </div>
        { children }
    </div>
);
