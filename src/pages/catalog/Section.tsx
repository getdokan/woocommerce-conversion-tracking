/**
 * External dependencies
 */
import type { ReactNode } from 'react';
import { cn } from '@wedevs/plugin-ui';

/**
 * A titled settings section with its rows in one bordered box.
 *
 * @param {Object}    props             Component props.
 * @param {string}    props.id          Heading ID.
 * @param {string}    props.title       Heading.
 * @param {string}    props.description Text under the heading.
 * @param {ReactNode} props.children    Rows.
 */
export const Section = ( {
    id,
    title,
    description,
    children,
}: {
    id: string;
    title: string;
    description: string;
    children: ReactNode;
} ) => (
    <section className="flex flex-col gap-3" aria-labelledby={ id }>
        <div className="flex flex-col gap-1">
            <h2
                id={ id }
                className="m-0 text-base font-semibold text-foreground"
            >
                { title }
            </h2>
            <p className="m-0 text-[13px] text-muted-foreground">
                { description }
            </p>
        </div>
        <div className="flex flex-col divide-y divide-border rounded-xl border border-border">
            { children }
        </div>
    </section>
);

/**
 * One row of a Section.
 *
 * @param {Object}    props           Component props.
 * @param {string=}   props.className Extra classes.
 * @param {ReactNode} props.children  Content.
 */
export const SectionRow = ( {
    className,
    children,
}: {
    className?: string;
    children: ReactNode;
} ) => <div className={ cn( 'p-4', className ) }>{ children }</div>;
