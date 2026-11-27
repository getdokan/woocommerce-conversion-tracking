/**
 * External dependencies
 */
import { Checkbox, Input, Label } from '@wedevs/plugin-ui';
import { Search } from 'lucide-react';

/**
 * WordPress dependencies
 */
import {
    createInterpolateElement,
    useMemo,
    useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import {
    ancestorNames,
    childrenByParent,
    formatCount,
    type CatalogCategory,
    type CatalogExclusions,
} from '../../data/catalog';
import { Section, SectionRow } from './Section';

const LEGEND = 'mb-3 p-0 text-sm font-medium text-foreground';

/**
 * A checkbox with its label.
 *
 * @param {Object}   props          Component props.
 * @param {string}   props.id       Checkbox ID.
 * @param {string}   props.label    Label.
 * @param {string=}  props.hint     Muted text after the label, e.g. the parent path.
 * @param {boolean}  props.checked  Checked.
 * @param {Function} props.onChange Change handler.
 */
const Check = ( {
    id,
    label,
    hint,
    checked,
    onChange,
}: {
    id: string;
    label: string;
    hint?: string;
    checked: boolean;
    onChange: ( checked: boolean ) => void;
} ) => (
    <div className="flex items-center gap-2.5 py-1">
        <Checkbox
            id={ id }
            checked={ checked }
            onCheckedChange={ ( value ) => onChange( value === true ) }
        />
        <Label htmlFor={ id } className="font-normal">
            { label }
            { hint && (
                <>
                    { ' ' }
                    <span className="text-muted-foreground">{ hint }</span>
                </>
            ) }
        </Label>
    </div>
);

type TreeProps = {
    parent: number;
    tree: Map< number, CatalogCategory[] >;
    selected: Set< number >;
    onToggle: ( id: number, checked: boolean ) => void;
    depth?: number;
};

/**
 * Categories under a parent, nested.
 *
 * @param {TreeProps} props Component props.
 */
const CategoryTree = ( {
    parent,
    tree,
    selected,
    onToggle,
    depth = 0,
}: TreeProps ) => (
    <ul className="m-0 list-none p-0" style={ { paddingLeft: depth ? 24 : 0 } }>
        { ( tree.get( parent ) ?? [] ).map( ( term ) => (
            <li key={ term.id } className="m-0">
                <Check
                    id={ `wcct-exclude-cat-${ term.id }` }
                    label={ term.name }
                    checked={ selected.has( term.id ) }
                    onChange={ ( checked ) => onToggle( term.id, checked ) }
                />
                { tree.has( term.id ) && (
                    <CategoryTree
                        parent={ term.id }
                        tree={ tree }
                        selected={ selected }
                        onToggle={ onToggle }
                        depth={ depth + 1 }
                    />
                ) }
            </li>
        ) ) }
    </ul>
);

export type ExclusionsProps = {
    value: CatalogExclusions;
    types: Record< string, string >;
    categories: CatalogCategory[];
    onChange: ( next: CatalogExclusions ) => void;
};

/**
 * "Leave out of the feed": product types and categories, with a category search.
 *
 * @param {ExclusionsProps} props Component props.
 */
export const Exclusions = ( {
    value,
    types,
    categories,
    onChange,
}: ExclusionsProps ) => {
    const [ query, setQuery ] = useState( '' );
    const tree = useMemo(
        () => childrenByParent( categories ),
        [ categories ]
    );
    const selected = useMemo(
        () => new Set( value.categories ),
        [ value.categories ]
    );
    const known = categories.filter( ( term ) => selected.has( term.id ) );
    const needle = query.trim().toLowerCase();
    const matches = needle
        ? categories.filter( ( term ) =>
              term.name.toLowerCase().includes( needle )
          )
        : [];

    const toggleType = ( slug: string, checked: boolean ) =>
        onChange( {
            ...value,
            types: checked
                ? [ ...value.types.filter( ( item ) => item !== slug ), slug ]
                : value.types.filter( ( item ) => item !== slug ),
        } );

    const toggleCategory = ( id: number, checked: boolean ) =>
        onChange( {
            ...value,
            categories: checked
                ? [ ...value.categories.filter( ( item ) => item !== id ), id ]
                : value.categories.filter( ( item ) => item !== id ),
        } );

    return (
        <Section
            id="wcct-catalog-exclude"
            title={ __(
                'Leave out of the feed',
                'woocommerce-conversion-tracking'
            ) }
            description={ __(
                'Products you never want in ads. Everything else goes into the feed.',
                'woocommerce-conversion-tracking'
            ) }
        >
            <SectionRow>
                <fieldset className="m-0 border-0 p-0">
                    <legend className={ LEGEND }>
                        { __(
                            'By product type',
                            'woocommerce-conversion-tracking'
                        ) }
                    </legend>
                    { Object.entries( types ).map( ( [ slug, label ] ) => (
                        <Check
                            key={ slug }
                            id={ `wcct-exclude-type-${ slug }` }
                            label={ label }
                            checked={ value.types.includes( slug ) }
                            onChange={ ( checked ) =>
                                toggleType( slug, checked )
                            }
                        />
                    ) ) }
                </fieldset>
            </SectionRow>
            <SectionRow>
                <fieldset className="m-0 border-0 p-0">
                    <legend className={ LEGEND }>
                        { __(
                            'By category',
                            'woocommerce-conversion-tracking'
                        ) }
                    </legend>
                    { categories.length ? (
                        <div className="flex max-w-md flex-col overflow-hidden rounded-lg border border-border">
                            <div className="relative border-b border-border p-2">
                                <Search
                                    aria-hidden="true"
                                    className="pointer-events-none absolute top-1/2 left-4.5 size-4 -translate-y-1/2 text-muted-foreground"
                                />
                                <Label
                                    htmlFor="wcct-cat-search"
                                    className="sr-only"
                                >
                                    { __(
                                        'Find a category',
                                        'woocommerce-conversion-tracking'
                                    ) }
                                </Label>
                                <Input
                                    id="wcct-cat-search"
                                    type="search"
                                    autoComplete="off"
                                    className="pl-8"
                                    placeholder={ __(
                                        'Find a category',
                                        'woocommerce-conversion-tracking'
                                    ) }
                                    value={ query }
                                    onChange={ ( event ) =>
                                        setQuery( event.target.value )
                                    }
                                />
                            </div>
                            <div className="flex items-center justify-between gap-2 border-b border-border bg-muted/40 px-3 py-2 text-[13px] text-muted-foreground">
                                <span
                                    aria-live="polite"
                                    data-testid="wcct-cat-count"
                                >
                                    { sprintf(
                                        /* translators: 1: categories left out, 2: all categories. */
                                        __(
                                            '%1$s of %2$s left out',
                                            'woocommerce-conversion-tracking'
                                        ),
                                        formatCount( known.length ),
                                        formatCount( categories.length )
                                    ) }
                                </span>
                                { value.categories.length > 0 && (
                                    <button
                                        type="button"
                                        className="cursor-pointer border-0 bg-transparent p-0 text-[13px] text-primary underline underline-offset-3"
                                        onClick={ () => {
                                            // The button goes away; keep focus in the box.
                                            document
                                                .getElementById(
                                                    'wcct-cat-search'
                                                )
                                                ?.focus();
                                            onChange( {
                                                ...value,
                                                categories: [],
                                            } );
                                        } }
                                    >
                                        { createInterpolateElement(
                                            __(
                                                'Clear<span> the categories left out</span>',
                                                'woocommerce-conversion-tracking'
                                            ),
                                            {
                                                span: (
                                                    <span className="sr-only" />
                                                ),
                                            }
                                        ) }
                                    </button>
                                ) }
                            </div>
                            { /* Scrolls on its own, so it takes keyboard focus. */ }
                            <div
                                className="max-h-72 overflow-y-auto px-3 py-2"
                                role="group"
                                aria-label={ __(
                                    'Categories',
                                    'woocommerce-conversion-tracking'
                                ) }
                                // eslint-disable-next-line jsx-a11y/no-noninteractive-tabindex -- scroll container.
                                tabIndex={ 0 }
                            >
                                { needle ? (
                                    <ul className="m-0 list-none p-0">
                                        { matches.map( ( term ) => {
                                            const path = ancestorNames(
                                                term,
                                                categories
                                            );

                                            return (
                                                <li
                                                    key={ term.id }
                                                    className="m-0"
                                                >
                                                    <Check
                                                        id={ `wcct-exclude-cat-${ term.id }` }
                                                        label={ term.name }
                                                        hint={
                                                            path.length
                                                                ? sprintf(
                                                                      /* translators: %s: the category's parents, top level first, e.g. "Men › Tops". */
                                                                      __(
                                                                          '(in %s)',
                                                                          'woocommerce-conversion-tracking'
                                                                      ),
                                                                      path.join(
                                                                          ' › '
                                                                      )
                                                                  )
                                                                : ''
                                                        }
                                                        checked={ selected.has(
                                                            term.id
                                                        ) }
                                                        onChange={ (
                                                            checked
                                                        ) =>
                                                            toggleCategory(
                                                                term.id,
                                                                checked
                                                            )
                                                        }
                                                    />
                                                </li>
                                            );
                                        } ) }
                                    </ul>
                                ) : (
                                    <CategoryTree
                                        parent={ 0 }
                                        tree={ tree }
                                        selected={ selected }
                                        onToggle={ toggleCategory }
                                    />
                                ) }
                                { needle && ! matches.length && (
                                    <p className="m-0 py-1 text-[13px] text-muted-foreground">
                                        { __(
                                            'No categories match.',
                                            'woocommerce-conversion-tracking'
                                        ) }
                                    </p>
                                ) }
                            </div>
                        </div>
                    ) : (
                        <p className="m-0 text-[13px] text-muted-foreground">
                            { __(
                                'Your store has no product categories yet.',
                                'woocommerce-conversion-tracking'
                            ) }
                        </p>
                    ) }
                    { categories.length > 0 && (
                        <p className="m-0 mt-2 text-[13px] text-muted-foreground">
                            { __(
                                'Ticking a parent leaves out everything under it.',
                                'woocommerce-conversion-tracking'
                            ) }
                        </p>
                    ) }
                </fieldset>
            </SectionRow>
        </Section>
    );
};
