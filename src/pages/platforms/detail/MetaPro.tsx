/**
 * External dependencies
 */
import {
    Button,
    Input,
    Label,
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
    Switch,
    cn,
} from '@wedevs/plugin-ui';
import { StatusPill } from '@wcct/components';
import { CircleCheck, Plus, TriangleAlert, X } from 'lucide-react';

/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import {
    countFor,
    maxParameters,
    maxPixels,
    nextEvent,
    orderTokens,
    parameterProblems,
    pixelIdField,
    pixelProblems,
    tokensOf,
    type Parameter,
    type Pixel,
} from '../../../data/metaPro';
import {
    checkId,
    controlId,
    type EventOption,
} from '../../../data/platformForm';
import { shortId } from '../../../data/platforms';
import type { SchemaElement } from '../../../data/types';
import { EventsTable } from './EventsTable';

const text = ( value: unknown ): string =>
    typeof value === 'string' ? value : '';

/**
 * Move focus to a control once it's rendered.
 *
 * @param {string} id Control ID.
 */
const focusSoon = ( id: string ) =>
    window.setTimeout( () => document.getElementById( id )?.focus(), 0 );

/**
 * A line under a control: an error, a warning or a good check.
 *
 * @param {Object}  props         Component props.
 * @param {string}  props.id      Element ID.
 * @param {string}  props.message Text.
 * @param {string}  props.tone    error, warn or ok.
 * @param {boolean} props.live    Announce changes.
 */
const Line = ( {
    id,
    message,
    tone,
    live = false,
}: {
    id: string;
    message: string;
    tone: 'error' | 'warn' | 'ok';
    live?: boolean;
} ) => (
    <p
        id={ id }
        aria-live={ live ? 'polite' : undefined }
        data-testid={ id }
        className={ cn(
            'm-0 flex items-start gap-1.5 text-[13px]',
            tone === 'ok' && 'text-tone-ok-fg',
            tone === 'warn' && 'text-tone-warn-fg',
            tone === 'error' && 'text-destructive'
        ) }
    >
        { message && tone === 'ok' && (
            <CircleCheck
                className="mt-0.5 size-3.5 shrink-0"
                aria-hidden="true"
            />
        ) }
        { message && tone !== 'ok' && (
            <TriangleAlert
                className="mt-0.5 size-3.5 shrink-0"
                aria-hidden="true"
            />
        ) }
        { message && <span>{ message }</span> }
    </p>
);

type ExtraPixelsProps = {
    platform: string;
    field: SchemaElement;
    pixels: Pixel[];
    // The main Pixel ID; its events are the table above.
    main: string;
    events: EventOption[];
    readOnly: boolean;
    // A failed save: missing IDs show too.
    attempted: boolean;
    error?: string;
    onChange: ( pixels: Pixel[] ) => void;
};

/**
 * More than one pixel (Pro): each extra pixel has its own Pixel ID and events.
 *
 * @param {ExtraPixelsProps} props Component props.
 */
export const ExtraPixels = ( {
    platform,
    field,
    pixels,
    main,
    events,
    readOnly,
    attempted,
    error,
    onChange,
}: ExtraPixelsProps ) => {
    const base = controlId( platform, field.id );
    const max = maxPixels( field );
    const problems = pixelProblems( field, pixels, main );
    const idField = pixelIdField( field );
    const full = pixels.length >= max;
    const recommended = events
        .filter( ( event ) => event.recommended && ! event.locked )
        .map( ( event ) => event.value );

    const update = ( index: number, next: Partial< Pixel > ) =>
        onChange(
            pixels.map( ( pixel, at ) =>
                at === index ? { ...pixel, ...next } : pixel
            )
        );

    const add = () => {
        onChange( [ ...pixels, { pixel_id: '', events: recommended } ] );
        focusSoon( `${ base }-${ pixels.length }-pixel_id` );
    };

    const remove = ( index: number ) => {
        onChange( pixels.filter( ( pixel, at ) => at !== index ) );
        focusSoon( `${ base }-add` );
    };

    return (
        <section
            id={ base }
            tabIndex={ -1 }
            className="flex flex-col gap-3 focus:outline-none"
            aria-labelledby={ `${ base }-title` }
            data-testid={ base }
        >
            <div>
                <h3
                    id={ `${ base }-title` }
                    className="m-0 text-sm font-semibold text-foreground"
                >
                    { __( 'Pixels', 'woocommerce-conversion-tracking' ) }
                </h3>
                <p className="m-0 text-[13px] text-muted-foreground">
                    { text( field.description ) }
                </p>
            </div>

            <div className="flex items-center gap-3 rounded-lg border border-border px-4 py-3">
                <StatusPill tone={ main ? 'ok' : 'off' } className="px-1.5">
                    <span className="sr-only">
                        { main
                            ? __( 'Set', 'woocommerce-conversion-tracking' )
                            : __(
                                  'Not set',
                                  'woocommerce-conversion-tracking'
                              ) }
                    </span>
                </StatusPill>
                <div className="min-w-0">
                    <div className="text-sm font-medium text-foreground">
                        { __(
                            'Main pixel',
                            'woocommerce-conversion-tracking'
                        ) }
                    </div>
                    <div className="text-[13px] text-muted-foreground">
                        { main
                            ? sprintf(
                                  /* translators: %s: shortened Pixel ID */
                                  __(
                                      '%s, the events above',
                                      'woocommerce-conversion-tracking'
                                  ),
                                  shortId( main )
                              )
                            : __(
                                  'Add the Pixel ID above first.',
                                  'woocommerce-conversion-tracking'
                              ) }
                    </div>
                </div>
            </div>

            { pixels.map( ( pixel, index ) => {
                const id = `${ base }-${ index }`;
                const inputId = `${ id }-pixel_id`;
                const problem = problems[ index ];
                const check = checkId( idField, pixel.pixel_id );
                const shown =
                    problem && ( attempted || check !== 'empty' )
                        ? problem
                        : null;
                let message = '';
                let tone: 'error' | 'warn' | 'ok' = 'ok';

                if ( shown ) {
                    message = shown.message;
                    tone = shown.blocks ? 'error' : 'warn';
                } else if ( check === 'valid' ) {
                    message = __(
                        'Looks like a Pixel ID.',
                        'woocommerce-conversion-tracking'
                    );
                }

                return (
                    <fieldset
                        key={ index }
                        className="m-0 flex min-w-0 flex-col gap-4 rounded-lg border border-border p-4"
                        data-testid={ id }
                    >
                        <legend className="px-1 text-sm font-semibold text-foreground">
                            { sprintf(
                                /* translators: %d: number of the extra pixel */
                                __(
                                    'Extra pixel %d',
                                    'woocommerce-conversion-tracking'
                                ),
                                index + 1
                            ) }
                        </legend>
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor={ inputId }>
                                { __(
                                    'Pixel ID',
                                    'woocommerce-conversion-tracking'
                                ) }
                            </Label>
                            <Input
                                id={ inputId }
                                value={ pixel.pixel_id }
                                placeholder={ __(
                                    '15 or 16 digits, numbers only',
                                    'woocommerce-conversion-tracking'
                                ) }
                                inputMode="numeric"
                                autoComplete="off"
                                spellCheck={ false }
                                readOnly={ readOnly }
                                aria-invalid={ tone === 'error' || undefined }
                                aria-describedby={ `${ inputId }-check` }
                                onChange={ ( event ) =>
                                    update( index, {
                                        pixel_id: event.target.value,
                                    } )
                                }
                                className={ cn(
                                    'max-w-140',
                                    readOnly && 'bg-muted'
                                ) }
                            />
                            <Line
                                id={ `${ inputId }-check` }
                                message={ message }
                                tone={ tone }
                                live
                            />
                        </div>
                        <EventsTable
                            platform={ `${ platform }-${ field.id }-${ index }` }
                            events={ events }
                            values={ { events: pixel.events } }
                            lastPurchase={ null }
                            connected={ false }
                            readOnly={ readOnly }
                            showSeen={ false }
                            onChange={ ( next ) =>
                                update( index, { events: next } )
                            }
                        />
                        { ! readOnly && (
                            <div>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={ () => remove( index ) }
                                    data-testid={ `${ id }-remove` }
                                >
                                    <X className="size-4" aria-hidden="true" />
                                    { sprintf(
                                        /* translators: %d: number of the extra pixel */
                                        __(
                                            'Remove extra pixel %d',
                                            'woocommerce-conversion-tracking'
                                        ),
                                        index + 1
                                    ) }
                                </Button>
                            </div>
                        ) }
                    </fieldset>
                );
            } ) }

            <Line
                id={ `${ base }-error` }
                message={ error ?? '' }
                tone="error"
                live
            />

            { ! readOnly && (
                <div>
                    <Button
                        id={ `${ base }-add` }
                        variant="outline"
                        onClick={ add }
                        disabled={ full }
                        data-testid={ `${ base }-add` }
                    >
                        <Plus className="size-4" aria-hidden="true" />
                        { full
                            ? sprintf(
                                  /* translators: %d: most extra pixels */
                                  __(
                                      'Up to %d extra pixels',
                                      'woocommerce-conversion-tracking'
                                  ),
                                  max
                              )
                            : __(
                                  'Add a pixel',
                                  'woocommerce-conversion-tracking'
                              ) }
                    </Button>
                </div>
            ) }
        </section>
    );
};

type CustomParametersProps = {
    platform: string;
    field: SchemaElement;
    parameters: Parameter[];
    events: EventOption[];
    readOnly: boolean;
    attempted: boolean;
    error?: string;
    onChange: ( parameters: Parameter[] ) => void;
};

/**
 * Custom parameters (Pro): up to five per event, each a field name and one of the tokens.
 *
 * @param {CustomParametersProps} props Component props.
 */
export const CustomParameters = ( {
    platform,
    field,
    parameters,
    events,
    readOnly,
    attempted,
    error,
    onChange,
}: CustomParametersProps ) => {
    const base = controlId( platform, field.id );
    const max = maxParameters( field );
    const tokens = tokensOf( field );
    const purchaseOnly = orderTokens( field );
    const allowed = events.filter( ( event ) =>
        (
            ( Array.isArray( field.allowed_events )
                ? field.allowed_events
                : [] ) as unknown[]
         ).includes( event.value )
    );
    const problems = parameterProblems( field, parameters );
    // Open without rows while the merchant is adding the first one.
    const [ opened, setOpened ] = useState( false );
    const on = parameters.length > 0 || opened;
    const next = nextEvent(
        parameters,
        allowed.map( ( event ) => event.value ),
        max
    );

    const update = ( index: number, change: Partial< Parameter > ) =>
        onChange(
            parameters.map( ( parameter, at ) =>
                at === index ? { ...parameter, ...change } : parameter
            )
        );

    const add = () => {
        if ( ! next ) {
            return;
        }

        onChange( [
            ...parameters,
            { event: next, name: '', token: tokens[ 0 ] ?? '' },
        ] );
        focusSoon( `${ base }-${ parameters.length }-name` );
    };

    const remove = ( index: number ) => {
        // The body stays open after the last row goes, so focus lands on Add.
        setOpened( true );
        onChange( parameters.filter( ( parameter, at ) => at !== index ) );
        focusSoon( `${ base }-add` );
    };

    const label = ( event: string ) =>
        allowed.find( ( option ) => option.value === event )?.label ?? event;

    return (
        <section
            className="rounded-lg border border-border"
            aria-labelledby={ `${ base }-title` }
            data-testid={ base }
        >
            <div className="flex items-start gap-3.5 px-4 py-3.5">
                <Switch
                    id={ base }
                    checked={ on }
                    disabled={ readOnly }
                    aria-labelledby={ `${ base }-title` }
                    aria-describedby={ `${ base }-desc` }
                    onCheckedChange={ ( checked ) => {
                        setOpened( checked );

                        // Off removes the parameters; nothing changes until Save.
                        if ( ! checked ) {
                            onChange( [] );
                        } else if ( ! parameters.length && next ) {
                            onChange( [
                                {
                                    event: next,
                                    name: '',
                                    token: tokens[ 0 ] ?? '',
                                },
                            ] );
                            focusSoon( `${ base }-0-name` );
                        }
                    } }
                />
                <div className="min-w-0 flex-1">
                    <div
                        id={ `${ base }-title` }
                        className="text-sm font-medium text-foreground"
                    >
                        { text( field.label ) }
                    </div>
                    <div
                        id={ `${ base }-desc` }
                        className="text-[13px] text-muted-foreground"
                    >
                        { sprintf(
                            /* translators: %d: most parameters per event */
                            __(
                                'Up to %d extra fields per event, each sent with the event you pick.',
                                'woocommerce-conversion-tracking'
                            ),
                            max
                        ) }
                    </div>
                </div>
                <StatusPill tone={ parameters.length ? 'ok' : 'off' }>
                    { parameters.length
                        ? __( 'On', 'woocommerce-conversion-tracking' )
                        : __( 'Off', 'woocommerce-conversion-tracking' ) }
                </StatusPill>
            </div>

            { on && (
                <div className="flex flex-col gap-4 border-t border-border p-4 text-[13px]">
                    { parameters.map( ( parameter, index ) => {
                        const id = `${ base }-${ index }`;
                        const problem = problems[ index ] ?? {};
                        const nameError =
                            problem.name &&
                            ( attempted || parameter.name.trim() !== '' )
                                ? problem.name
                                : '';
                        const onlyPurchase =
                            parameter.event !== 'purchase' &&
                            purchaseOnly.includes( parameter.token );

                        return (
                            <fieldset
                                key={ index }
                                className="m-0 flex min-w-0 flex-col gap-3 rounded-lg border border-border p-4"
                                data-testid={ id }
                            >
                                <legend className="px-1 text-sm font-semibold text-foreground">
                                    { sprintf(
                                        /* translators: %d: number of the parameter */
                                        __(
                                            'Parameter %d',
                                            'woocommerce-conversion-tracking'
                                        ),
                                        index + 1
                                    ) }
                                </legend>
                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor={ `${ id }-event` }>
                                        { __(
                                            'Send with',
                                            'woocommerce-conversion-tracking'
                                        ) }
                                    </Label>
                                    <Select
                                        items={ allowed.map( ( event ) => ( {
                                            value: event.value,
                                            label: event.label,
                                        } ) ) }
                                        value={ parameter.event }
                                        disabled={ readOnly }
                                        onValueChange={ ( value ) =>
                                            update( index, {
                                                event: String( value ),
                                            } )
                                        }
                                    >
                                        <SelectTrigger
                                            id={ `${ id }-event` }
                                            className="w-full max-w-140"
                                            aria-describedby={
                                                problem.event
                                                    ? `${ id }-event-error`
                                                    : undefined
                                            }
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            { allowed.map( ( event ) => {
                                                const full =
                                                    event.value !==
                                                        parameter.event &&
                                                    countFor(
                                                        parameters,
                                                        event.value
                                                    ) >= max;

                                                return (
                                                    <SelectItem
                                                        key={ event.value }
                                                        value={ event.value }
                                                        disabled={ full }
                                                    >
                                                        { full
                                                            ? sprintf(
                                                                  /* translators: 1: event name, 2: most parameters per event */
                                                                  __(
                                                                      '%1$s (%2$d already)',
                                                                      'woocommerce-conversion-tracking'
                                                                  ),
                                                                  event.label,
                                                                  max
                                                              )
                                                            : event.label }
                                                    </SelectItem>
                                                );
                                            } ) }
                                        </SelectContent>
                                    </Select>
                                    { problem.event && (
                                        <Line
                                            id={ `${ id }-event-error` }
                                            message={ problem.event }
                                            tone="error"
                                        />
                                    ) }
                                </div>
                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor={ `${ id }-name` }>
                                        { __(
                                            'Field name',
                                            'woocommerce-conversion-tracking'
                                        ) }
                                    </Label>
                                    <Input
                                        id={ `${ id }-name` }
                                        value={ parameter.name }
                                        placeholder={ __(
                                            'Like content_category',
                                            'woocommerce-conversion-tracking'
                                        ) }
                                        autoComplete="off"
                                        spellCheck={ false }
                                        readOnly={ readOnly }
                                        aria-invalid={
                                            !! nameError || undefined
                                        }
                                        aria-describedby={ `${ id }-name-check` }
                                        onChange={ ( event ) =>
                                            update( index, {
                                                name: event.target.value,
                                            } )
                                        }
                                        className={ cn(
                                            'max-w-140',
                                            readOnly && 'bg-muted'
                                        ) }
                                    />
                                    <Line
                                        id={ `${ id }-name-check` }
                                        message={ nameError }
                                        tone="error"
                                        live
                                    />
                                </div>
                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor={ `${ id }-token` }>
                                        { __(
                                            'Value',
                                            'woocommerce-conversion-tracking'
                                        ) }
                                    </Label>
                                    <Select
                                        items={ tokens.map( ( token ) => ( {
                                            value: token,
                                            label: token,
                                        } ) ) }
                                        value={ parameter.token }
                                        disabled={ readOnly }
                                        onValueChange={ ( value ) =>
                                            update( index, {
                                                token: String( value ),
                                            } )
                                        }
                                    >
                                        <SelectTrigger
                                            id={ `${ id }-token` }
                                            className="w-full max-w-140 font-mono"
                                            aria-describedby={
                                                onlyPurchase
                                                    ? `${ id }-token-note`
                                                    : undefined
                                            }
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            { tokens.map( ( token ) => {
                                                const orderOnly =
                                                    token !== parameter.token &&
                                                    parameter.event !==
                                                        'purchase' &&
                                                    purchaseOnly.includes(
                                                        token
                                                    );

                                                return (
                                                    <SelectItem
                                                        key={ token }
                                                        value={ token }
                                                        className="font-mono"
                                                        disabled={ orderOnly }
                                                    >
                                                        { orderOnly
                                                            ? sprintf(
                                                                  /* translators: %s: a token, like {customer_type} */
                                                                  __(
                                                                      '%s (Purchase only)',
                                                                      'woocommerce-conversion-tracking'
                                                                  ),
                                                                  token
                                                              )
                                                            : token }
                                                    </SelectItem>
                                                );
                                            } ) }
                                        </SelectContent>
                                    </Select>
                                    { onlyPurchase && (
                                        <Line
                                            id={ `${ id }-token-note` }
                                            message={ sprintf(
                                                /* translators: %s: event name, like View product */
                                                __(
                                                    'Sent with Purchase only, so %s goes without it.',
                                                    'woocommerce-conversion-tracking'
                                                ),
                                                label( parameter.event )
                                            ) }
                                            tone="warn"
                                        />
                                    ) }
                                    { problem.token && (
                                        <Line
                                            id={ `${ id }-token-error` }
                                            message={ problem.token }
                                            tone="error"
                                        />
                                    ) }
                                </div>
                                { ! readOnly && (
                                    <div>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={ () => remove( index ) }
                                            data-testid={ `${ id }-remove` }
                                        >
                                            <X
                                                className="size-4"
                                                aria-hidden="true"
                                            />
                                            { sprintf(
                                                /* translators: %d: number of the parameter */
                                                __(
                                                    'Remove parameter %d',
                                                    'woocommerce-conversion-tracking'
                                                ),
                                                index + 1
                                            ) }
                                        </Button>
                                    </div>
                                ) }
                            </fieldset>
                        );
                    } ) }

                    <Line
                        id={ `${ base }-error` }
                        message={ error ?? '' }
                        tone="error"
                        live
                    />

                    { ! readOnly && (
                        <div>
                            <Button
                                id={ `${ base }-add` }
                                variant="outline"
                                size="sm"
                                onClick={ add }
                                disabled={ ! next }
                                data-testid={ `${ base }-add` }
                            >
                                <Plus className="size-4" aria-hidden="true" />
                                { next
                                    ? __(
                                          'Add a parameter',
                                          'woocommerce-conversion-tracking'
                                      )
                                    : sprintf(
                                          /* translators: %d: most parameters per event */
                                          __(
                                              'Up to %d parameters per event',
                                              'woocommerce-conversion-tracking'
                                          ),
                                          max
                                      ) }
                            </Button>
                        </div>
                    ) }

                    <p className="m-0 text-xs text-muted-foreground [&_code]:rounded [&_code]:bg-muted [&_code]:px-1 [&_code]:font-mono">
                        { __(
                            'Values come from the product, the order or the shopper:',
                            'woocommerce-conversion-tracking'
                        ) }{ ' ' }
                        { tokens.map( ( token ) => (
                            <code key={ token } className="mr-1">
                                { token }
                            </code>
                        ) ) }
                        { __(
                            '(new or returning). Coupon and customer type are sent with Purchase only.',
                            'woocommerce-conversion-tracking'
                        ) }
                    </p>
                </div>
            ) }
        </section>
    );
};
