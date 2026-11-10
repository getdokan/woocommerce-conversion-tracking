/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { PlatformValues, SchemaElement, SchemaPlatform } from './types';

// Variants the platform pages edit, with Meta Pro's extra pixels and custom parameters; display-only ones are left out.
export const EDITABLE_VARIANTS = [
    'text',
    'select',
    'switch',
    'radio_capsule',
    'multicheck',
    'wcct_pixels',
    'wcct_custom_parameters',
];

// Advanced settings that put the "Customised" badge on a platform's card when they differ from the default.
export const CUSTOMISED_FIELDS = [
    'value_mode',
    'product_id_mode',
    'skip_roles',
    'tag_id',
];

export type EventOption = {
    value: string;
    label: string;
    description: string;
    recommended: boolean;
    pro: boolean;
    locked: boolean;
    always: boolean;
    // Field holding this event's own event ID (X); the row stays off while it's empty.
    needs: string;
};

export type Preset = 'recommended' | 'all' | 'custom';

export type RoleOption = { value: string; label: string };

export type LastPurchase = { at: number; order_number: string } | null;

const text = ( value: unknown ): string =>
    typeof value === 'string' ? value : '';

/**
 * Whether a field applies, given the other values (schema `dependencies`).
 *
 * @param {SchemaElement} field  Field.
 * @param {Object}        values Platform values.
 */
export const applies = (
    field: SchemaElement,
    values: PlatformValues
): boolean =>
    ( field.dependencies ?? [] ).every(
        ( dependency ) =>
            ! Array.isArray( dependency.value ) ||
            dependency.value.includes( values[ dependency.key ] )
    );

/**
 * The fields the platform page edits, by priority.
 *
 * @param {SchemaPlatform} platform Platform schema.
 */
export const editableFields = ( platform: SchemaPlatform ): SchemaElement[] =>
    platform.schema
        .filter(
            ( element ) =>
                element.type === 'field' &&
                EDITABLE_VARIANTS.includes( element.variant ?? '' )
        )
        .sort(
            ( a, b ) => Number( a.priority ?? 0 ) - Number( b.priority ?? 0 )
        );

/**
 * A section's fields, by priority.
 *
 * @param {SchemaPlatform} platform Platform schema.
 * @param {string}         section  Section ID suffix, like connection or advanced.
 */
export const sectionFields = (
    platform: SchemaPlatform,
    section: string
): SchemaElement[] =>
    editableFields( platform ).filter(
        ( field ) => field.section_id === `${ platform.id }_${ section }`
    );

/**
 * The page's draft: the editable fields' values.
 *
 * @param {SchemaPlatform} platform Platform schema.
 * @param {Object}         values   Platform values.
 */
export const draftFrom = (
    platform: SchemaPlatform,
    values: PlatformValues
): PlatformValues => {
    const draft: PlatformValues = {};

    for ( const field of editableFields( platform ) ) {
        draft[ field.id ] =
            field.id in values ? values[ field.id ] : field.default;
    }

    return draft;
};

const same = ( a: unknown, b: unknown ): boolean =>
    JSON.stringify( a ?? null ) === JSON.stringify( b ?? null );

/**
 * The changed values, keyed as the settings route takes them; read-only fields are never sent.
 *
 * @param {SchemaPlatform} platform Platform schema.
 * @param {Object}         draft    Draft.
 * @param {Object}         saved    Saved values.
 */
export const changedValues = (
    platform: SchemaPlatform,
    draft: PlatformValues,
    saved: PlatformValues
): PlatformValues => {
    const body: PlatformValues = {};

    for ( const field of editableFields( platform ) ) {
        if (
            ! field.readonly &&
            ! field.disabled &&
            ! same( draft[ field.id ], saved[ field.id ] )
        ) {
            body[ field.id ] = draft[ field.id ];
        }
    }

    return body;
};

/**
 * A typed ID as the server stores it: ID characters only, in the field's case (Sanitizer::id()).
 *
 * @param {SchemaElement} field Field.
 * @param {string}        raw   Typed value.
 */
export const cleanId = ( field: SchemaElement, raw: string ): string => {
    const value = raw.trim().replace( /[^A-Za-z0-9_\-/.:]/g, '' );

    if ( field.case === 'upper' ) {
        return value.toUpperCase();
    }

    return field.case === 'lower' ? value.toLowerCase() : value;
};

export type IdCheck = 'empty' | 'valid' | 'invalid' | 'short';

/**
 * The format check of an ID field as typed; format only, never a lookup.
 *
 * @param {SchemaElement} field Field.
 * @param {string}        raw   Typed value.
 */
export const checkId = ( field: SchemaElement, raw: string ): IdCheck => {
    const value = cleanId( field, raw );

    if ( ! value ) {
        return 'empty';
    }

    const pattern = text( field.pattern );

    if ( pattern ) {
        try {
            if ( ! new RegExp( pattern ).test( value ) ) {
                return 'invalid';
            }
        } catch {
            // A pattern JavaScript can't read is left to the server.
        }
    }

    // Ads conversion labels are usually 10 or more characters; shorter often means a partial copy.
    if ( /_label$/.test( field.id ) && value.length < 10 ) {
        return 'short';
    }

    return 'valid';
};

/**
 * The events field's options.
 *
 * @param {SchemaPlatform} platform Platform schema.
 */
export const eventOptions = ( platform: SchemaPlatform ): EventOption[] => {
    const field = platform.schema.find(
        ( element ) => element.type === 'field' && element.id === 'events'
    );
    const options = Array.isArray( field?.options ) ? field.options : [];

    return options.map( ( option: Record< string, unknown > ) => ( {
        value: text( option.value ),
        label: text( option.label ) || text( option.value ),
        description: text( option.description ),
        recommended: option.recommended === true,
        pro: option.pro === true,
        locked: option.locked === true,
        always: option.always === true,
        needs: text( option.needs ),
    } ) );
};

const asList = ( value: unknown ): string[] =>
    Array.isArray( value )
        ? value.filter( ( item ): item is string => typeof item === 'string' )
        : [];

/**
 * Whether an event's row waits for its own event ID.
 *
 * @param {EventOption} event  Event.
 * @param {Object}      values Draft values.
 */
export const missingId = (
    event: EventOption,
    values: PlatformValues
): boolean => !! event.needs && text( values[ event.needs ] ).trim() === '';

/**
 * Whether an event is sent with these values.
 *
 * @param {EventOption} event  Event.
 * @param {Object}      values Draft values.
 */
export const isEventOn = (
    event: EventOption,
    values: PlatformValues
): boolean => {
    if ( event.locked ) {
        return false;
    }

    if ( event.always ) {
        return true;
    }

    return (
        ! missingId( event, values ) &&
        asList( values.events ).includes( event.value )
    );
};

/**
 * The preset the switched events match; derived, never stored. Locked and always-on rows don't count.
 *
 * @param {EventOption[]} events Events.
 * @param {Object}        values Draft values.
 */
export const presetOf = (
    events: EventOption[],
    values: PlatformValues
): Preset => {
    const picked = asList( values.events );
    const free = events.filter( ( event ) => ! event.locked && ! event.always );
    const matches = ( on: ( event: EventOption ) => boolean ) =>
        free.every(
            ( event ) => picked.includes( event.value ) === on( event )
        );

    if ( matches( ( event ) => event.recommended ) ) {
        return 'recommended';
    }

    return matches( () => true ) ? 'all' : 'custom';
};

/**
 * The events list after picking a preset; locked events keep their stored state.
 *
 * @param {EventOption[]} events Events.
 * @param {unknown}       stored The events value.
 * @param {Preset}        preset Recommended or All.
 */
export const applyPreset = (
    events: EventOption[],
    stored: unknown,
    preset: Preset
): string[] => {
    const current = asList( stored );

    return events
        .filter( ( event ) => {
            if ( event.locked ) {
                return current.includes( event.value );
            }

            return event.always || preset === 'all' || event.recommended;
        } )
        .map( ( event ) => event.value );
};

/**
 * The events list with one event switched.
 *
 * @param {EventOption[]} events Events.
 * @param {unknown}       stored The events value.
 * @param {string}        event  Event ID.
 * @param {boolean}       on     On or off.
 */
export const switchEvent = (
    events: EventOption[],
    stored: unknown,
    event: string,
    on: boolean
): string[] => {
    const current = asList( stored ).filter( ( item ) => item !== event );
    const next = on ? [ ...current, event ] : current;

    // In the schema's order, like the server saves it.
    return events
        .map( ( option ) => option.value )
        .filter( ( value ) => next.includes( value ) );
};

/**
 * Whether the field holds a valid ID by its format.
 *
 * @param {SchemaPlatform} platform Platform schema.
 * @param {string}         id       Field ID.
 * @param {Object}         values   Values.
 */
const hasValidId = (
    platform: SchemaPlatform,
    id: string,
    values: PlatformValues
): boolean => {
    const field = platform.schema.find( ( element ) => element.id === id );

    return !! field && checkId( field, text( values[ id ] ) ) === 'valid';
};

/**
 * Events 2.x sent that need a new event ID (X's re-setup), switched on and still without a valid ID; locked ones are left out.
 *
 * @param {SchemaPlatform} platform Platform schema.
 * @param {Object}         values   Draft or saved values.
 * @param {unknown}        flagged  The saved needs_resetup list.
 */
export const resetupEvents = (
    platform: SchemaPlatform,
    values: PlatformValues,
    flagged: unknown
): EventOption[] => {
    const events = asList( flagged );
    const on = asList( values.events );

    return eventOptions( platform ).filter(
        ( event ) =>
            events.includes( event.value ) &&
            on.includes( event.value ) &&
            ! event.locked &&
            !! event.needs &&
            ! hasValidId( platform, event.needs, values )
    );
};

/**
 * Events to switch on because an event ID that was empty when saved just became valid (typing a tw- ID turns the row on).
 *
 * @param {SchemaPlatform} platform Platform schema.
 * @param {string}         field    Changed field.
 * @param {Object}         saved    Saved values.
 * @param {Object}         before   Draft before the change.
 * @param {Object}         after    Draft after it.
 */
export const eventsTurnedOn = (
    platform: SchemaPlatform,
    field: string,
    saved: PlatformValues,
    before: PlatformValues,
    after: PlatformValues
): string[] => {
    const schema = platform.schema.find( ( element ) => element.id === field );

    if (
        ! schema ||
        checkId( schema, text( saved[ field ] ) ) !== 'empty' ||
        checkId( schema, text( before[ field ] ) ) === 'valid' ||
        checkId( schema, text( after[ field ] ) ) !== 'valid'
    ) {
        return [];
    }

    return eventOptions( platform )
        .filter(
            ( event ) =>
                event.needs === field &&
                ! event.locked &&
                ! asList( after.events ).includes( event.value )
        )
        .map( ( event ) => event.value );
};

/**
 * Whether a platform needs the merchant: X events waiting for a new event ID, or Google's split Ads ID to check.
 *
 * @param {SchemaPlatform} platform Platform schema.
 * @param {Object}         values   Saved values.
 */
export const needsAttention = (
    platform: SchemaPlatform,
    values: PlatformValues
): boolean =>
    resetupEvents( platform, values, values.needs_resetup ).length > 0 ||
    !! values.ads_id_split;

/**
 * Whether any Advanced setting that marks a platform as customised differs from its default.
 *
 * @param {SchemaPlatform} platform Platform schema.
 * @param {Object}         values   Platform values.
 */
export const isCustomised = (
    platform: SchemaPlatform,
    values: PlatformValues
): boolean =>
    platform.schema.some(
        ( field ) =>
            field.type === 'field' &&
            CUSTOMISED_FIELDS.includes( field.id ) &&
            field.section_id === `${ platform.id }_advanced` &&
            applies( field, values ) &&
            field.id in values &&
            ! same( values[ field.id ], field.default ?? '' )
    );

/**
 * "Skip these roles" site roles from its schema field.
 *
 * @param {SchemaElement} field Field.
 */
export const roleOptions = ( field: SchemaElement ): RoleOption[] =>
    ( Array.isArray( field.roles ) ? field.roles : [] )
        .map( ( role: Record< string, unknown > ) => ( {
            value: text( role.value ),
            label: text( role.label ) || text( role.value ),
        } ) )
        .filter( ( role ) => role.value !== '' );

// The roles behind each "Skip these roles" preset (Events\SkipRoles::PRESETS).
export const SKIP_PRESETS: Record< string, string[] > = {
    'admin-shop': [ 'administrator', 'shop_manager' ],
    admin: [ 'administrator' ],
    none: [],
};

/**
 * The roles a "Skip these roles" value skips.
 *
 * @param {unknown} value Preset or role slugs.
 */
export const skippedRoles = ( value: unknown ): string[] =>
    typeof value === 'string' ? SKIP_PRESETS[ value ] ?? [] : asList( value );

/**
 * A time like "2 min ago".
 *
 * @param {number} at  Unix time.
 * @param {number} now Unix time now.
 */
export const timeAgo = ( at: number, now: number ): string => {
    const seconds = Math.max( 0, now - at );

    if ( seconds < 60 ) {
        return __( 'Just now', 'woocommerce-conversion-tracking' );
    }

    if ( seconds < 3600 ) {
        const minutes = Math.floor( seconds / 60 );

        return sprintf(
            /* translators: %d: minutes */
            __( '%d min ago', 'woocommerce-conversion-tracking' ),
            minutes
        );
    }

    if ( seconds < 86400 ) {
        const hours = Math.floor( seconds / 3600 );

        return sprintf(
            /* translators: %d: hours */
            __( '%d h ago', 'woocommerce-conversion-tracking' ),
            hours
        );
    }

    if ( seconds < 172800 ) {
        return __( 'Yesterday', 'woocommerce-conversion-tracking' );
    }

    const days = Math.floor( seconds / 86400 );

    return sprintf(
        /* translators: %d: days */
        _n(
            '%d day ago',
            '%d days ago',
            days,
            'woocommerce-conversion-tracking'
        ),
        days
    );
};

/**
 * The field a Doctor link (?field=) points at, read from the route's query.
 *
 * @param {string} query Route query.
 */
export const focusField = ( query: string ): string =>
    ( new URLSearchParams( query ).get( 'field' ) ?? '' ).replace(
        /[^a-z0-9_]/gi,
        ''
    );

/**
 * The section a link (?section=) points at, like cookie-choices.
 *
 * @param {string} query Route query.
 */
export const focusSection = ( query: string ): string =>
    ( new URLSearchParams( query ).get( 'section' ) ?? '' ).replace(
        /[^a-z0-9_-]/gi,
        ''
    );

/**
 * DOM ID of a field's control on a platform page.
 *
 * @param {string} platform Platform ID.
 * @param {string} field    Field ID.
 */
export const controlId = ( platform: string, field: string ): string =>
    `wcct-${ platform }-${ field }`;
