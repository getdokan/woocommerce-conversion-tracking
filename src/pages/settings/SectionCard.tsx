/**
 * External dependencies
 */
import type { ReactNode } from 'react';
import { Card, cn } from '@wedevs/plugin-ui';
import { Check } from 'lucide-react';

export type SectionCardProps = {
    id: string;
    icon: ReactNode;
    title: ReactNode;
    description: ReactNode;
    children: ReactNode;
    footer?: ReactNode;
};

/**
 * One Settings section: icon, heading and description above its content.
 *
 * @param {SectionCardProps} props Component props.
 */
export const SectionCard = ( {
    id,
    icon,
    title,
    description,
    children,
    footer,
}: SectionCardProps ) => (
    <section
        aria-labelledby={ `wcct-settings-${ id }-title` }
        data-testid={ `wcct-settings-${ id }` }
    >
        <Card className="gap-0 py-0">
            <div className="flex items-start gap-3.5 border-b border-border px-6 py-5">
                <span
                    className="inline-flex size-10 shrink-0 items-center justify-center rounded-lg border border-border text-foreground [&_svg]:size-4.5"
                    aria-hidden="true"
                >
                    { icon }
                </span>
                <div className="min-w-0">
                    { /* Focused when another section is picked (Shell). */ }
                    <h2
                        id={ `wcct-settings-${ id }-title` }
                        tabIndex={ -1 }
                        data-wcct-section-heading
                        className="m-0 flex flex-wrap items-center gap-2 p-0 text-base leading-snug font-semibold text-foreground"
                    >
                        { title }
                    </h2>
                    <p className="m-0 mt-0.5 text-sm text-muted-foreground">
                        { description }
                    </p>
                </div>
            </div>
            { children }
            { footer && <SectionFooter>{ footer }</SectionFooter> }
        </Card>
    </section>
);

/**
 * The bar under a section's content.
 *
 * @param {Object}    props          Component props.
 * @param {ReactNode} props.children Footer content.
 */
export const SectionFooter = ( { children }: { children: ReactNode } ) => (
    <div className="flex flex-wrap items-center gap-3 border-t border-border px-6 py-4">
        { children }
    </div>
);

/**
 * The main column and the help column of a section.
 *
 * @param {Object}    props       Component props.
 * @param {ReactNode} props.main  Main column.
 * @param {ReactNode} props.aside Help column.
 * @param {string}    props.label Name of the help column.
 */
export const SectionBody = ( {
    main,
    aside,
    label,
}: {
    main: ReactNode;
    aside: ReactNode;
    label: string;
} ) => (
    <div className="grid grid-cols-[minmax(0,1fr)_320px] max-[1100px]:grid-cols-1">
        <div className="flex min-w-0 flex-col gap-5 p-6">{ main }</div>
        <aside
            aria-label={ label }
            className="flex flex-col gap-3 border-l border-border bg-muted/40 p-6 text-sm max-[1100px]:border-t max-[1100px]:border-l-0"
        >
            { aside }
        </aside>
    </div>
);

/**
 * A list of features, each with a check mark.
 *
 * @param {Object}      props           Component props.
 * @param {ReactNode[]} props.items     Items.
 * @param {string=}     props.className Extra classes.
 */
export const FeatureList = ( {
    items,
    className,
}: {
    items: ReactNode[];
    className?: string;
} ) => (
    <ul className={ cn( 'm-0 flex list-none flex-col gap-2 p-0', className ) }>
        { items.map( ( item, index ) => (
            <li
                key={ index }
                className="m-0 flex items-start gap-2.5 text-sm text-foreground"
            >
                <Check
                    className="mt-0.5 size-4 shrink-0 text-tone-ok-fg"
                    aria-hidden="true"
                />
                <span>{ item }</span>
            </li>
        ) ) }
    </ul>
);

// Links inside the app: Tailwind's preflight drops WordPress's link styles.
export const LINK_CLASS =
    'font-medium text-primary underline underline-offset-3 hover:text-primary/80';
