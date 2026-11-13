/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { checkId, cleanId } from './platformForm';
import type { SchemaElement } from './types';

// Meta Pro's editors (Pro's Settings\Schema): extra pixels and custom parameters.
export const PIXELS_VARIANT = 'wcct_pixels';
export const PARAMETERS_VARIANT = 'wcct_custom_parameters';

export type Pixel = { pixel_id: string; events: string[] };

export type Parameter = { event: string; name: string; token: string };

// Server fallbacks when the schema leaves them out.
const MAX_PIXELS = 10;
const MAX_PARAMETERS = 5;
const ORDER_TOKENS = [ '{order_coupon}', '{customer_type}' ];

// Meta's custom_data keys, as Settings\Schema::sanitize_parameters() takes them.
const NAME_PATTERN = /^[A-Za-z_][A-Za-z0-9_]{0,49}$/;

const text = ( value: unknown ): string =>
    typeof value === 'string' ? value : '';

const list = ( value: unknown ): string[] =>
    Array.isArray( value )
        ? value.filter( ( item ): item is string => typeof item === 'string' )
        : [];

const record = ( value: unknown ): Record< string, unknown > =>
    value && typeof value === 'object'
        ? ( value as Record< string, unknown > )
        : {};

/**
 * The extra pixels in a value.
 *
 * @param {unknown} value Stored or draft value.
 */
export const asPixels = ( value: unknown ): Pixel[] =>
    ( Array.isArray( value ) ? value : [] ).map( ( item ) => ( {
        pixel_id: text( record( item ).pixel_id ),
        events: list( record( item ).events ),
    } ) );

/**
 * The custom parameters in a value.
 *
 * @param {unknown} value Stored or draft value.
 */
export const asParameters = ( value: unknown ): Parameter[] =>
    ( Array.isArray( value ) ? value : [] ).map( ( item ) => ( {
        event: text( record( item ).event ),
        name: text( record( item ).name ),
        token: text( record( item ).token ),
    } ) );

/**
 * Most extra pixels.
 *
 * @param {SchemaElement} field The pixels field.
 */
export const maxPixels = ( field: SchemaElement ): number =>
    Number( field.max ) > 0 ? Number( field.max ) : MAX_PIXELS;

/**
 * Most custom parameters per event.
 *
 * @param {SchemaElement} field The custom parameters field.
 */
export const maxParameters = ( field: SchemaElement ): number =>
    Number( field.max ) > 0 ? Number( field.max ) : MAX_PARAMETERS;

/**
 * The tokens a parameter can send.
 *
 * @param {SchemaElement} field The custom parameters field.
 */
export const tokensOf = ( field: SchemaElement ): string[] =>
    list( field.tokens );

/**
 * Tokens filled only with an order, so only Purchase sends them.
 *
 * @param {SchemaElement} field The custom parameters field.
 */
export const orderTokens = ( field: SchemaElement ): string[] =>
    Array.isArray( field.order_tokens )
        ? list( field.order_tokens )
        : ORDER_TOKENS;

/**
 * An extra pixel's ID field, for the format check of the main Pixel ID.
 *
 * @param {SchemaElement} field The pixels field.
 */
export const pixelIdField = ( field: SchemaElement ): SchemaElement => ( {
    id: 'pixel_id',
    type: 'field',
    variant: 'text',
    format: 'id',
    pattern: text( field.pixel_pattern ),
    typical_length: field.typical_length,
} );

export type PixelProblem = { message: string; blocks: boolean };

/**
 * What's wrong with each extra pixel, by index: a missing or malformed ID blocks the save; a repeated one is a warning, as it's sent once.
 *
 * @param {SchemaElement} field  The pixels field.
 * @param {Pixel[]}       pixels Extra pixels.
 * @param {string}        main   The main Pixel ID.
 */
export const pixelProblems = (
    field: SchemaElement,
    pixels: Pixel[],
    main: string
): Record< number, PixelProblem > => {
    const idField = pixelIdField( field );
    const mainId = cleanId( idField, main );
    const extras: string[] = [];
    const problems: Record< number, PixelProblem > = {};

    pixels.forEach( ( pixel, index ) => {
        const id = cleanId( idField, pixel.pixel_id );
        const check = checkId( idField, pixel.pixel_id );

        if ( check === 'empty' ) {
            problems[ index ] = {
                message: __(
                    'Add the Pixel ID, or remove this pixel.',
                    'woocommerce-conversion-tracking'
                ),
                blocks: true,
            };
        } else if ( check === 'invalid' ) {
            problems[ index ] = {
                message: __(
                    'Pixel IDs are numbers only.',
                    'woocommerce-conversion-tracking'
                ),
                blocks: true,
            };
        } else if ( extras.includes( id ) ) {
            // The server keeps the first of two extra pixels with one ID.
            problems[ index ] = {
                message: __(
                    'This pixel is already on the page. This one is removed when you save.',
                    'woocommerce-conversion-tracking'
                ),
                blocks: false,
            };
        } else if ( id === mainId ) {
            problems[ index ] = {
                message: __(
                    'This pixel is already on the page. It gets each event once.',
                    'woocommerce-conversion-tracking'
                ),
                blocks: false,
            };
        }

        extras.push( id );
    } );

    return problems;
};

/**
 * How many parameters go with an event.
 *
 * @param {Parameter[]} parameters Parameters.
 * @param {string}      event      Event.
 */
export const countFor = ( parameters: Parameter[], event: string ): number =>
    parameters.filter( ( parameter ) => parameter.event === event ).length;

/**
 * The event a new parameter goes with: Purchase while it has room, else the first event that has.
 *
 * @param {Parameter[]} parameters Parameters.
 * @param {string[]}    events     Events, in order.
 * @param {number}      max        Most per event.
 */
export const nextEvent = (
    parameters: Parameter[],
    events: string[],
    max: number
): string | null => {
    const open = events.filter(
        ( event ) => countFor( parameters, event ) < max
    );

    if ( open.includes( 'purchase' ) ) {
        return 'purchase';
    }

    return open[ 0 ] ?? null;
};

export type ParameterProblems = {
    name?: string;
    event?: string;
    token?: string;
};

/**
 * What's wrong with each parameter, by index; each one blocks the save, as the server would refuse it.
 *
 * @param {SchemaElement} field      The custom parameters field.
 * @param {Parameter[]}   parameters Parameters.
 */
export const parameterProblems = (
    field: SchemaElement,
    parameters: Parameter[]
): Record< number, ParameterProblems > => {
    const max = maxParameters( field );
    const events = list( field.allowed_events );
    const tokens = tokensOf( field );
    const counted: Record< string, number > = {};
    const problems: Record< number, ParameterProblems > = {};

    parameters.forEach( ( parameter, index ) => {
        const found: ParameterProblems = {};

        if ( ! NAME_PATTERN.test( parameter.name.trim() ) ) {
            found.name = parameter.name.trim()
                ? __(
                      'Field names use letters, numbers and underscores, and start with a letter or underscore.',
                      'woocommerce-conversion-tracking'
                  )
                : __(
                      'Add a field name, like content_category.',
                      'woocommerce-conversion-tracking'
                  );
        }

        counted[ parameter.event ] = ( counted[ parameter.event ] ?? 0 ) + 1;

        if ( ! events.includes( parameter.event ) ) {
            found.event = __(
                'Not one of the events.',
                'woocommerce-conversion-tracking'
            );
        } else if ( counted[ parameter.event ] > max ) {
            found.event = sprintf(
                /* translators: %d: most parameters allowed per event */
                __(
                    'Add up to %d custom parameters per event.',
                    'woocommerce-conversion-tracking'
                ),
                max
            );
        }

        if ( ! tokens.includes( parameter.token ) ) {
            found.token = __(
                'Pick one of the listed values.',
                'woocommerce-conversion-tracking'
            );
        }

        if ( Object.keys( found ).length ) {
            problems[ index ] = found;
        }
    } );

    return problems;
};
