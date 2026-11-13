/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { __, _x, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { NAMESPACE } from './api';
import {
    formatLooksRight,
    outsideTypicalLength,
    withPrefix,
} from './platformForm';
import { platformCards } from './platforms';
import type {
    AppData,
    EuAnswer,
    OnboardingState,
    PlatformValues,
    SchemaElement,
    SchemaPlatform,
    SettingsValues,
} from './types';

export const STEP_COUNT = 6;

// Steps in order; the wizard and the top bar number them from 1.
export const stepNames = (): string[] => [
    __( 'Welcome', 'woocommerce-conversion-tracking' ),
    __( 'Platforms', 'woocommerce-conversion-tracking' ),
    __( 'Connect', 'woocommerce-conversion-tracking' ),
    __( 'Europe', 'woocommerce-conversion-tracking' ),
    __( 'Verify', 'woocommerce-conversion-tracking' ),
    __( 'Done', 'woocommerce-conversion-tracking' ),
];

// Tiles marked Popular, and picked on a store's first visit.
export const POPULAR = [ 'facebook', 'google' ];

/**
 * The step a #/onboarding?step=N query asks for, or 0.
 *
 * @param {string} query Route query.
 */
export const queryStep = ( query: string ): number => {
    const step = Number( new URLSearchParams( query ).get( 'step' ) );

    return Number.isInteger( step ) && step >= 1 && step <= STEP_COUNT
        ? step
        : 0;
};

/**
 * Whether ?rerun=1 opens the wizard on a draft: once setup is finished, or on a store that never ran it but already tracks.
 *
 * @param {AppData} data Settings, schema and progress.
 */
export const canRerun = ( data: AppData ): boolean =>
    data.onboarding.status === 'done' ||
    ( data.onboarding.status === 'new' &&
        platformCards( data ).some( ( card ) => card.connected ) );

/**
 * The wizard's address for a step; a re-run keeps ?rerun=1.
 *
 * @param {number}  step  Step, from 1.
 * @param {boolean} draft A re-run.
 */
export const stepHash = ( step: number, draft: boolean ): string =>
    draft
        ? `#/onboarding?rerun=1&step=${ step }`
        : `#/onboarding?step=${ step }`;

export type OnboardingField = {
    id: string;
    label: string;
    placeholder: string;
    pattern: string;
    letterCase: '' | 'upper' | 'lower';
    // Added in front when the value only fits with it (AW-).
    prefix: string;
    // [ min, max ]; outside it is a soft warning only.
    typicalLength: number[];
    required: boolean;
    // Required once this other field is filled (Google's label with the Ads ID).
    with: string;
    hint: string;
    valid: string;
    invalid: string;
    // Shown while it is required and still empty.
    missing: string;
};

type OnboardingMeta = {
    required?: boolean;
    with?: string;
    hint?: string;
    valid?: string;
    invalid?: string;
    missing?: string;
};

const text = ( value: unknown ): string =>
    typeof value === 'string' ? value : '';

const meta = ( element: SchemaElement ): OnboardingMeta | null =>
    element.onboarding && typeof element.onboarding === 'object'
        ? ( element.onboarding as OnboardingMeta )
        : null;

/**
 * The ID fields a platform asks for in the wizard: those its schema marks with `onboarding`.
 *
 * @param {SchemaPlatform} platform Platform schema.
 */
export const onboardingFields = (
    platform: SchemaPlatform
): OnboardingField[] =>
    platform.schema
        .filter(
            ( element ) =>
                element.type === 'field' &&
                element.format === 'id' &&
                ! element.disabled &&
                !! meta( element )
        )
        .sort(
            ( a, b ) => Number( a.priority ?? 0 ) - Number( b.priority ?? 0 )
        )
        .map( ( element ) => {
            const info = meta( element ) as OnboardingMeta;

            return {
                id: element.id,
                label: text( element.label ) || element.id,
                placeholder: text( element.placeholder ),
                pattern: text( element.pattern ),
                letterCase:
                    element.case === 'upper' || element.case === 'lower'
                        ? element.case
                        : '',
                prefix: text( element.prefix ),
                typicalLength: Array.isArray( element.typical_length )
                    ? element.typical_length.map( Number )
                    : [],
                required: info.required === true,
                with: text( info.with ),
                hint: text( info.hint ),
                valid:
                    text( info.valid ) ||
                    formatLooksRight( platform.label || platform.id ),
                invalid:
                    text( info.invalid ) ||
                    text( element.description ) ||
                    __(
                        "This doesn't match the ID format.",
                        'woocommerce-conversion-tracking'
                    ),
                missing: text( info.missing ),
            };
        } );

/**
 * A typed ID as the server stores it: ID characters only, in the field's case, with its prefix when missing (Sanitizer::id()).
 *
 * @param {OnboardingField} field Field.
 * @param {string}          raw   Typed value.
 */
export const cleanId = ( field: OnboardingField, raw: string ): string => {
    let value = raw.trim().replace( /[^A-Za-z0-9_\-/.:]/g, '' );

    if ( field.letterCase === 'upper' ) {
        value = value.toUpperCase();
    } else if ( field.letterCase === 'lower' ) {
        value = value.toLowerCase();
    }

    return withPrefix( field.prefix, field.pattern, value );
};

export type FieldCheck = 'empty' | 'valid' | 'invalid';

/**
 * Whether a typed ID matches the field's format, the same check the Platforms pages and the server run.
 *
 * @param {OnboardingField} field Field.
 * @param {string}          raw   Typed value.
 */
export const checkField = (
    field: OnboardingField,
    raw: string
): FieldCheck => {
    const value = cleanId( field, raw );

    if ( ! value ) {
        return 'empty';
    }

    if ( ! field.pattern ) {
        return 'valid';
    }

    try {
        return new RegExp( field.pattern ).test( value ) ? 'valid' : 'invalid';
    } catch {
        // A pattern JavaScript can't read is left to the server.
        return 'valid';
    }
};

/**
 * Whether a typed ID in the right format is outside its typical length; it still saves.
 *
 * @param {OnboardingField} field Field.
 * @param {string}          raw   Typed value.
 */
export const unusualLength = ( field: OnboardingField, raw: string ): boolean =>
    checkField( field, raw ) === 'valid' &&
    outsideTypicalLength( field.typicalLength, cleanId( field, raw ) );

export type PanelCheck = {
    // Has fields to fill in the wizard.
    connectable: boolean;
    // Every required field valid, nothing invalid.
    ready: boolean;
    // A filled field doesn't match its format.
    invalid: boolean;
    // Fields required by another filled field but still empty.
    missing: string[];
};

/**
 * Whether a field must be filled: always, or once the field it goes with is.
 *
 * @param {OnboardingField} field  Field.
 * @param {Object}          values Typed values by field ID.
 */
export const isRequired = (
    field: OnboardingField,
    values: Record< string, string >
): boolean =>
    field.required ||
    ( !! field.with && ( values[ field.with ] ?? '' ).trim() !== '' );

/**
 * Where a platform's connect panel stands.
 *
 * @param {OnboardingField[]} fields Its wizard fields.
 * @param {Object}            values Typed values by field ID.
 */
export const checkPanel = (
    fields: OnboardingField[],
    values: Record< string, string >
): PanelCheck => {
    const checks = fields.map( ( field ) => ( {
        field,
        check: checkField( field, values[ field.id ] ?? '' ),
    } ) );
    const invalid = checks.some( ( { check } ) => check === 'invalid' );
    const filled = checks.some( ( { check } ) => check === 'valid' );

    return {
        connectable: fields.length > 0,
        ready:
            filled &&
            ! invalid &&
            checks.every(
                ( { field, check } ) =>
                    ! isRequired( field, values ) || check === 'valid'
            ),
        invalid,
        missing: checks
            .filter(
                ( { field, check } ) =>
                    !! field.with &&
                    check === 'empty' &&
                    isRequired( field, values )
            )
            .map( ( { field } ) => field.id ),
    };
};

/**
 * The values a panel saves, empty ones clearing the field; Google adds Ads once its ID and label are both there.
 *
 * @param {string}            id     Platform ID.
 * @param {OnboardingField[]} fields Its wizard fields.
 * @param {Object}            values Typed values by field ID.
 */
export const panelValues = (
    id: string,
    fields: OnboardingField[],
    values: Record< string, string >
): PlatformValues => {
    const saved: PlatformValues = {};

    fields.forEach( ( field ) => {
        saved[ field.id ] = cleanId( field, values[ field.id ] ?? '' );
    } );

    if ( id === 'google' ) {
        saved.mode =
            saved.ads_id && saved.purchase_label ? 'both' : 'analytics';
    }

    return saved;
};

/**
 * Whether a panel's IDs differ from what is saved.
 *
 * @param {OnboardingField[]} fields Its wizard fields.
 * @param {Object}            typed  Typed values by field ID.
 * @param {Object}            saved  Saved values by field ID.
 */
export const panelChanged = (
    fields: OnboardingField[],
    typed: Record< string, string >,
    saved: Record< string, string >
): boolean =>
    fields.some(
        ( field ) =>
            cleanId( field, typed[ field.id ] ?? '' ) !==
            cleanId( field, saved[ field.id ] ?? '' )
    );

/**
 * A list for a sentence, joined with the translated separator.
 *
 * @param {string[]} items Items.
 */
export const listOf = ( items: string[] ): string =>
    items.reduce(
        ( list: string, item ) =>
            list === ''
                ? item
                : sprintf(
                      /* translators: 1: the list so far, 2: the next item; joins a list like "View product, Add to cart" */
                      _x(
                          '%1$s, %2$s',
                          'list of items',
                          'woocommerce-conversion-tracking'
                      ),
                      list,
                      item
                  ),
        ''
    );

/**
 * Names of the events a platform sends once connected: recommended, unlocked and needing no extra ID.
 *
 * @param {SchemaPlatform} platform Platform schema.
 */
export const recommendedEvents = ( platform: SchemaPlatform ): string[] => {
    const events = platform.schema.find(
        ( element ) => element.type === 'field' && element.id === 'events'
    );
    const options = Array.isArray( events?.options ) ? events.options : [];

    return options
        .filter(
            ( option ) =>
                option &&
                option.recommended &&
                ! option.locked &&
                ! option.needs
        )
        .map( ( option ) => text( option.label ) )
        .filter( Boolean );
};

// Progress saves run one after another, so a later Skip can't land before an earlier step.
let progressQueue: Promise< unknown > = Promise.resolve();

/**
 * Save wizard progress, after any save still running; the server keeps the furthest step.
 *
 * @param {Object}   data           Progress.
 * @param {string=}  data.action    save, skip or finish.
 * @param {number=}  data.step      Step reached.
 * @param {string[]} data.platforms Picked platform IDs.
 * @param {string=}  data.eu        Europe answer.
 */
export const saveProgress = ( data: {
    action?: 'save' | 'skip' | 'finish';
    step?: number;
    platforms?: string[];
    eu?: EuAnswer;
} ): Promise< OnboardingState > => {
    const request = progressQueue.then( () =>
        apiFetch< OnboardingState >( {
            path: `${ NAMESPACE }/onboarding`,
            method: 'POST',
            data,
        } )
    );

    progressQueue = request.catch( () => undefined );

    return request;
};

/**
 * Save settings through the settings route; only the keys posted change.
 *
 * @param {Object} data Global values and { platforms: id => values }.
 */
export const saveSettings = (
    data: Partial< SettingsValues >
): Promise< SettingsValues > =>
    apiFetch< SettingsValues >( {
        path: `${ NAMESPACE }/settings`,
        method: 'POST',
        data,
    } );

/**
 * The saved settings now, to spot a draft gone stale.
 */
export const fetchSettings = (): Promise< SettingsValues > =>
    apiFetch< SettingsValues >( { path: `${ NAMESPACE }/settings` } );
