/**
 * External dependencies
 */
import type { ReactNode } from 'react';
import {
    Checkbox,
    Label,
    RadioCard,
    RadioGroup,
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
    Switch,
} from '@wedevs/plugin-ui';
import { ProBadge } from '@wcct/components';

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import {
    controlId,
    roleOptions,
    skippedRoles,
} from '../../../data/platformForm';
import { upgradeUrl } from '../../../data/plan';
import type { SchemaElement } from '../../../data/types';
import { NewTabNote } from '../../../shell/NewTabNote';

const text = ( value: unknown ): string =>
    typeof value === 'string' ? value : '';

type Option = { value: string; label: string; description?: string };

const optionsOf = ( field: SchemaElement ): Option[] =>
    ( Array.isArray( field.options ) ? field.options : [] ).map(
        ( option: Record< string, unknown > ) => ( {
            value: text( option.value ),
            label: text( option.label ) || text( option.value ),
            description: text( option.description ),
        } )
    );

type ControlProps = {
    platform: string;
    field: SchemaElement;
    readOnly: boolean;
    error?: string;
};

/**
 * A field's error line.
 *
 * @param {Object} props       Component props.
 * @param {string} props.id    Control ID.
 * @param {string} props.error Message.
 */
const FieldError = ( { id, error }: { id: string; error?: string } ) =>
    error ? (
        <p
            id={ `${ id }-error` }
            role="alert"
            className="m-0 text-[13px] text-destructive"
        >
            { error }
        </p>
    ) : null;

type SelectFieldProps = ControlProps & {
    value: string;
    onChange: ( value: string ) => void;
    children?: ReactNode;
    // Options the page adds, like "Choose roles".
    extra?: Option[];
};

/**
 * A labelled select, one setting per line.
 *
 * @param {SelectFieldProps} props Component props.
 */
export const SelectField = ( {
    platform,
    field,
    readOnly,
    error,
    value,
    onChange,
    children,
    extra = [],
}: SelectFieldProps ) => {
    const id = controlId( platform, field.id );
    const items = [ ...optionsOf( field ), ...extra ];
    const description = text( field.description );

    return (
        <div className="flex flex-col gap-1.5" data-testid={ `${ id }-field` }>
            <Label htmlFor={ id }>{ text( field.label ) || field.id }</Label>
            <Select
                items={ items }
                value={ value }
                disabled={ readOnly }
                onValueChange={ ( next ) => onChange( String( next ) ) }
            >
                <SelectTrigger
                    id={ id }
                    className="w-full max-w-140"
                    aria-describedby={
                        [
                            description && `${ id }-hint`,
                            error && `${ id }-error`,
                        ]
                            .filter( Boolean )
                            .join( ' ' ) || undefined
                    }
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    { items.map( ( item ) => (
                        <SelectItem key={ item.value } value={ item.value }>
                            { item.label }
                        </SelectItem>
                    ) ) }
                </SelectContent>
            </Select>
            { description && (
                <p
                    id={ `${ id }-hint` }
                    className="m-0 max-w-140 text-[13px] text-muted-foreground"
                >
                    { description }
                </p>
            ) }
            { children }
            <FieldError id={ id } error={ error } />
        </div>
    );
};

/**
 * A setting with a switch on the right, like Respect cookie choices.
 *
 * @param {Object}    props             Component props.
 * @param {string}    props.id          Control ID.
 * @param {string}    props.title       Title.
 * @param {string}    props.description Description.
 * @param {boolean}   props.checked     On.
 * @param {boolean}   props.disabled    Read-only.
 * @param {boolean}   props.locked      A Pro setting on Free.
 * @param {Function}  props.onChange    Change handler.
 * @param {ReactNode} props.children    Lines under the description.
 */
export const SwitchRow = ( {
    id,
    title,
    description,
    checked,
    disabled,
    locked = false,
    onChange,
    children,
}: {
    id: string;
    title: string;
    description?: string;
    checked: boolean;
    disabled: boolean;
    // A Pro setting on Free: PRO badge and upgrade link.
    locked?: boolean;
    onChange: ( checked: boolean ) => void;
    children?: ReactNode;
} ) => (
    <div
        className="flex items-start gap-4 rounded-lg border border-border px-4 py-3.5"
        data-testid={ `${ id }-row` }
    >
        <div className="min-w-0 flex-1">
            <div
                id={ `${ id }-title` }
                className="flex items-center gap-2 text-sm font-medium text-foreground"
            >
                { title }
                { locked && <ProBadge /> }
            </div>
            { description && (
                <div
                    id={ `${ id }-desc` }
                    className="text-[13px] text-muted-foreground"
                >
                    { description }
                </div>
            ) }
            { children }
            { locked && (
                <a
                    href={ upgradeUrl() }
                    target="_blank"
                    rel="noreferrer"
                    aria-label={ sprintf(
                        /* translators: %s: Pro setting, e.g. Enhanced conversions */
                        __(
                            'Upgrade to get %s (opens in a new tab)',
                            'woocommerce-conversion-tracking'
                        ),
                        title
                    ) }
                    className="mt-1 inline-block text-[13px] font-medium text-primary underline underline-offset-3"
                >
                    { __( 'Upgrade', 'woocommerce-conversion-tracking' ) }
                    <NewTabNote />
                </a>
            ) }
        </div>
        <Switch
            id={ id }
            checked={ checked }
            disabled={ disabled }
            onCheckedChange={ onChange }
            aria-labelledby={ `${ id }-title` }
            aria-describedby={ description ? `${ id }-desc` : undefined }
        />
    </div>
);

type RadioFieldProps = ControlProps & {
    value: string;
    onChange: ( value: string ) => void;
};

/**
 * Google's "What do you use?": radio cards that show only the IDs needed.
 *
 * @param {RadioFieldProps} props Component props.
 */
export const RadioField = ( {
    platform,
    field,
    readOnly,
    value,
    onChange,
}: RadioFieldProps ) => {
    const id = controlId( platform, field.id );

    return (
        <fieldset className="m-0 flex flex-col gap-2 border-0 p-0">
            <legend className="mb-2 p-0 text-sm font-medium text-foreground">
                { text( field.label ) || field.id }
            </legend>
            <RadioGroup
                id={ id }
                value={ value }
                disabled={ readOnly }
                onValueChange={ ( next ) => onChange( String( next ) ) }
                className="max-w-140"
            >
                { optionsOf( field ).map( ( option ) => (
                    <RadioCard
                        key={ option.value }
                        id={ `${ id }-${ option.value }` }
                        value={ option.value }
                        label={ option.label }
                        description={ option.description }
                    />
                ) ) }
            </RadioGroup>
        </fieldset>
    );
};

const CHOOSE = 'choose';

type SkipRolesFieldProps = ControlProps & {
    value: unknown;
    onChange: ( value: string | string[] ) => void;
};

/**
 * "Skip these roles": a preset, or the site's roles picked one by one.
 *
 * @param {SkipRolesFieldProps} props Component props.
 */
export const SkipRolesField = ( {
    platform,
    field,
    readOnly,
    error,
    value,
    onChange,
}: SkipRolesFieldProps ) => {
    const roles = roleOptions( field );
    const picking = Array.isArray( value );
    const known = roles.map( ( role ) => role.value );
    // A saved role the site no longer has isn't sent back.
    const picked = skippedRoles( value ).filter(
        ( role ) => ! known.length || known.includes( role )
    );
    const id = controlId( platform, field.id );

    return (
        <SelectField
            platform={ platform }
            field={ field }
            readOnly={ readOnly }
            error={ error }
            value={ picking ? CHOOSE : String( value ?? '' ) }
            extra={
                roles.length
                    ? [
                          {
                              value: CHOOSE,
                              label: __(
                                  'Choose roles…',
                                  'woocommerce-conversion-tracking'
                              ),
                          },
                      ]
                    : []
            }
            onChange={ ( next ) => onChange( next === CHOOSE ? picked : next ) }
        >
            { picking && (
                <fieldset
                    className="m-0 mt-1 flex max-w-140 flex-col gap-2 rounded-lg border border-border px-4 py-3"
                    data-testid={ `${ id }-roles` }
                >
                    <legend className="px-1 text-[13px] text-muted-foreground">
                        { __(
                            'Roles not tracked',
                            'woocommerce-conversion-tracking'
                        ) }
                    </legend>
                    { roles.map( ( role ) => (
                        <div
                            key={ role.value }
                            className="flex items-center gap-2"
                        >
                            <Checkbox
                                id={ `${ id }-role-${ role.value }` }
                                checked={ picked.includes( role.value ) }
                                disabled={ readOnly }
                                onCheckedChange={ ( on ) =>
                                    onChange(
                                        on
                                            ? [ ...picked, role.value ]
                                            : picked.filter(
                                                  ( item ) =>
                                                      item !== role.value
                                              )
                                    )
                                }
                            />
                            <Label
                                htmlFor={ `${ id }-role-${ role.value }` }
                                className="font-normal"
                            >
                                { role.label }
                            </Label>
                        </div>
                    ) ) }
                </fieldset>
            ) }
        </SelectField>
    );
};
