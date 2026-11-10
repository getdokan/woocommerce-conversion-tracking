/**
 * External dependencies
 */
import {
    Button,
    Notice,
    NoticeTitle,
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
    Spinner,
    toast,
} from '@wedevs/plugin-ui';
import { PlatformLogo, StatusPill } from '@wcct/components';
import { CircleHelp } from 'lucide-react';

/**
 * WordPress dependencies
 */
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { useAppData } from '../../../data/AppData';
import {
    errorMessage,
    saveSettings,
    type SettingsUpdate,
} from '../../../data/api';
import {
    PARAMETERS_VARIANT,
    PIXELS_VARIANT,
    asParameters,
    asPixels,
    parameterProblems,
    pixelProblems,
} from '../../../data/metaPro';
import { isPro, upgradeUrl } from '../../../data/plan';
import {
    applies,
    changedValues,
    checkId,
    controlId,
    draftFrom,
    editableFields,
    eventOptions,
    eventsTurnedOn,
    focusField,
    focusSection,
    needsAttention,
    resetupEvents,
    sectionFields,
    switchEvent,
    type LastPurchase,
} from '../../../data/platformForm';
import {
    platformLogoUrl,
    type PlatformCardInfo,
} from '../../../data/platforms';
import type {
    PlatformValues,
    SchemaElement,
    SchemaPlatform,
} from '../../../data/types';
import { NewTabNote } from '../../../shell/NewTabNote';
import { useUnsavedChanges } from '../../../shell/useUnsavedChanges';
import { PauseTracking } from '../PauseTracking';
import {
    AdvancedSettings,
    DeveloperBlock,
    MetaProPreview,
    showsMetaPreview,
} from './AdvancedSettings';
import { EventsTable } from './EventsTable';
import { RadioField, SelectField, SkipRolesField, SwitchRow } from './Fields';
import { HelpContent } from './HelpColumn';
import { IdField } from './IdField';
import { CustomParameters, ExtraPixels } from './MetaPro';
import { GoogleSplitNotice, XResetupNotice } from './Notices';

type Draft = { fields: PlatformValues; consent: boolean };

// The Respect cookie choices section, the target of #/platforms?section=cookie-choices.
const COOKIE_CHOICES = 'cookie-choices';

type ApiError = {
    code?: string;
    data?: { errors?: Record< string, unknown >; fields?: unknown };
};

const text = ( value: unknown ): string =>
    typeof value === 'string' ? value : '';

const DOCS_TILES: Record< string, string > = {
    facebook: 'meta',
    google: 'google',
    twitter: 'x',
    bing_ads: 'microsoft',
};

/**
 * The line under a platform's name.
 *
 * @param {string} id Platform ID.
 */
const intro = ( id: string ): string => {
    switch ( id ) {
        case 'facebook':
            return __(
                "Sends your store's events to the Meta Pixel for Facebook and Instagram ads.",
                'woocommerce-conversion-tracking'
            );
        case 'google':
            return __(
                'Analytics shows you what happens; Ads counts conversions for your campaigns.',
                'woocommerce-conversion-tracking'
            );
        case 'twitter':
            return __(
                "Sends your store's events to the X Pixel for X ads.",
                'woocommerce-conversion-tracking'
            );
        case 'bing_ads':
            return __(
                "Sends your store's events to the UET tag for Bing and Microsoft Start ads.",
                'woocommerce-conversion-tracking'
            );
        default:
            return '';
    }
};

/**
 * What the Advanced settings hold, beside its title.
 *
 * @param {string} id Platform ID.
 */
const advancedSubtitle = ( id: string ): string => {
    switch ( id ) {
        case 'facebook':
            return __(
                'Order value, product IDs, who to track, extra pixels',
                'woocommerce-conversion-tracking'
            );
        case 'google':
            return __(
                'Order value, Google tag, who to track, developer details',
                'woocommerce-conversion-tracking'
            );
        case 'twitter':
            return __(
                'Order value, product IDs, who to track, developer details',
                'woocommerce-conversion-tracking'
            );
        case 'bing_ads':
            return __(
                'Order value, who to track, developer details',
                'woocommerce-conversion-tracking'
            );
        default:
            return __(
                'Order value, product IDs, who to track',
                'woocommerce-conversion-tracking'
            );
    }
};

/**
 * The per-field messages of a 400 (data.errors), by field ID; others under ''.
 *
 * @param {unknown} reason   What apiFetch rejected with.
 * @param {string}  platform Platform ID.
 */
const responseErrors = (
    reason: unknown,
    platform: string
): Record< string, string > => {
    const errors = ( ( reason ?? {} ) as ApiError ).data?.errors;
    const out: Record< string, string > = {};
    const prefix = `platforms.${ platform }.`;

    if ( errors && typeof errors === 'object' ) {
        for ( const [ key, message ] of Object.entries( errors ) ) {
            if ( typeof message !== 'string' ) {
                continue;
            }

            out[ key.startsWith( prefix ) ? key.slice( prefix.length ) : key ] =
                message;
        }
    }

    return out;
};

/**
 * The status pill of a platform page.
 *
 * @param {Object}           props              Component props.
 * @param {PlatformCardInfo} props.card         Platform.
 * @param {LastPurchase}     props.lastPurchase Last checked-in purchase.
 * @param {boolean}          props.attention    X events need new IDs, or Google's split Ads ID is unchecked.
 */
const Status = ( {
    card,
    lastPurchase,
    attention,
}: {
    card: PlatformCardInfo;
    lastPurchase: LastPurchase;
    attention: boolean;
} ) => {
    if ( ! card.connected ) {
        return (
            <StatusPill tone="off">
                { __( 'Not set up', 'woocommerce-conversion-tracking' ) }
            </StatusPill>
        );
    }

    if ( card.paused ) {
        return (
            <StatusPill tone="off">
                { __( 'Paused', 'woocommerce-conversion-tracking' ) }
            </StatusPill>
        );
    }

    if ( attention ) {
        return (
            <StatusPill tone="warn">
                { __( 'Needs attention', 'woocommerce-conversion-tracking' ) }
            </StatusPill>
        );
    }

    return lastPurchase ? (
        <StatusPill tone="ok">
            { __( 'Working', 'woocommerce-conversion-tracking' ) }
        </StatusPill>
    ) : (
        <StatusPill tone="off">
            { __(
                'Waiting for first event',
                'woocommerce-conversion-tracking'
            ) }
        </StatusPill>
    );
};

type PlatformDetailProps = {
    card: PlatformCardInfo;
    platform: SchemaPlatform;
    // Route query; ?field=<field> focuses that field (Doctor links).
    query: string;
};

/**
 * A platform's page: IDs, events, Respect cookie choices, Advanced settings, Save and Pause tracking.
 *
 * @param {PlatformDetailProps} props Component props.
 */
export const PlatformDetail = ( {
    card,
    platform,
    query,
}: PlatformDetailProps ) => {
    const { data, replaceSettings } = useAppData();
    const id = platform.id;
    const values = useMemo(
        () => data?.settings.platforms[ id ] ?? {},
        [ data, id ]
    );
    const savedConsent = data?.settings.consent_mode === true;
    const consentField = data?.schema.global.find(
        ( element ) => element.id === 'consent_mode'
    );
    const incoming = useMemo< Draft >(
        () => ( {
            fields: draftFrom( platform, values ),
            consent: savedConsent,
        } ),
        [ platform, values, savedConsent ]
    );
    const [ saved, setSaved ] = useState( incoming );
    const [ draft, setDraft ] = useState( incoming );
    const [ errors, setErrors ] = useState< Record< string, string > >( {} );
    const [ saveError, setSaveError ] = useState( '' );
    const [ pending, setPending ] = useState( false );
    const [ saving, setSaving ] = useState( false );
    const [ note, setNote ] = useState( '' );
    const [ advancedOpen, setAdvancedOpen ] = useState( false );
    // A save stopped by Meta Pro's editors: their empty fields show as errors too.
    const [ attempted, setAttempted ] = useState( false );
    const [ helpOpen, setHelpOpen ] = useState( false );
    const mounted = useRef( true );
    // Event ID fields that already switched their row on since the last save.
    const autoOn = useRef( new Set< string >() );

    const fields = editableFields( platform );
    const readOnlyAll =
        fields.length > 0 &&
        fields.every( ( field ) => field.readonly === true );
    const consentReadOnly = consentField?.readonly === true || readOnlyAll;
    const changes = changedValues( platform, draft.fields, saved.fields );
    const consentChanged = draft.consent !== saved.consent && ! consentReadOnly;
    const dirty = Object.keys( changes ).length > 0 || consentChanged;
    const dirtyRef = useRef( dirty );
    const lastPurchase = ( values.last_purchase ?? null ) as LastPurchase;
    const target = focusField( query );
    const section = focusSection( query );

    dirtyRef.current = dirty;

    useUnsavedChanges( dirty );

    useEffect( () => {
        mounted.current = true;

        return () => {
            mounted.current = false;
        };
    }, [] );

    // Fresh values from a refetch, a pause or another page replace the form, unless it has unsaved changes.
    const incomingKey = JSON.stringify( incoming );

    useEffect( () => {
        if ( ! dirtyRef.current ) {
            setSaved( incoming );
            setDraft( incoming );
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps -- keyed by content, not identity.
    }, [ incomingKey ] );

    // ?section=cookie-choices (the dashboard's links) scrolls to that section and focuses its heading.
    useEffect( () => {
        if ( section !== COOKIE_CHOICES ) {
            return;
        }

        const timer = window.setTimeout( () => {
            const heading = document.getElementById(
                `${ COOKIE_CHOICES }-title`
            );

            heading?.scrollIntoView( { block: 'center' } );
            heading?.focus();
        }, 50 );

        return () => window.clearTimeout( timer );
    }, [ id, section ] );

    // Opens the field's section if it is in Advanced settings, then focuses the field once it renders.
    const focusControl = ( field: string ): number => {
        const element = platform.schema.find( ( item ) => item.id === field );

        if ( element?.section_id === `${ id }_advanced` ) {
            setAdvancedOpen( true );
        }

        return window.setTimeout( () => {
            const control = document.getElementById( controlId( id, field ) );

            control?.scrollIntoView( { block: 'center' } );
            control?.focus();
        }, 50 );
    };

    // A Doctor link (?field=) focuses its field once, after the shell focuses the heading; a refetch doesn't move focus again.
    useEffect( () => {
        if ( ! target ) {
            return;
        }

        const timer = focusControl( target );

        return () => window.clearTimeout( timer );
        // eslint-disable-next-line react-hooks/exhaustive-deps -- once per platform and link, not per refetch.
    }, [ id, target ] );

    const setField = ( field: string, value: unknown ) => {
        setDraft( ( prev ) => {
            const next = { ...prev.fields, [ field ]: value };
            // Once per field until the next save, so a row switched off by hand stays off while its ID is edited.
            const turnOn = autoOn.current.has( field )
                ? []
                : eventsTurnedOn(
                      platform,
                      field,
                      saved.fields,
                      prev.fields,
                      next
                  );

            if ( turnOn.length ) {
                autoOn.current.add( field );
            }

            // A valid event ID turns its row on (X).
            for ( const event of turnOn ) {
                next.events = switchEvent(
                    eventOptions( platform ),
                    next.events,
                    event,
                    true
                );
            }

            return { ...prev, fields: next };
        } );
        setErrors( ( prev ) => ( { ...prev, [ field ]: '' } ) );
        setNote( '' );
    };

    const save = () => {
        setSaveError( '' );
        setPending( false );

        // Meta Pro: rows the server would refuse, by their first control.
        const blocked = fields.flatMap( ( field ) => {
            if ( ! ( field.id in changes ) ) {
                return [];
            }

            const base = controlId( id, field.id );

            if ( field.variant === PIXELS_VARIANT ) {
                const problems = pixelProblems(
                    field,
                    asPixels( draft.fields[ field.id ] ),
                    text( draft.fields.pixel_id )
                );

                return Object.keys( problems )
                    .filter( ( index ) => problems[ Number( index ) ].blocks )
                    .map( ( index ) => `${ base }-${ index }-pixel_id` );
            }

            if ( field.variant === PARAMETERS_VARIANT ) {
                const problems = parameterProblems(
                    field,
                    asParameters( draft.fields[ field.id ] )
                );

                return Object.keys( problems ).map( ( index ) => {
                    const problem = problems[ Number( index ) ];

                    if ( problem.name ) {
                        return `${ base }-${ index }-name`;
                    }

                    return problem.event
                        ? `${ base }-${ index }-event`
                        : `${ base }-${ index }-token`;
                } );
            }

            return [];
        } );

        if ( blocked.length ) {
            setAttempted( true );
            setAdvancedOpen( true );
            window.setTimeout(
                () => document.getElementById( blocked[ 0 ] )?.focus(),
                0
            );

            return;
        }

        const invalid = fields.filter(
            ( field ) =>
                field.format === 'id' &&
                field.id in changes &&
                applies( field, draft.fields ) &&
                checkId( field, text( draft.fields[ field.id ] ) ) === 'invalid'
        );

        if ( invalid.length ) {
            setErrors(
                Object.fromEntries(
                    invalid.map( ( field ) => [
                        field.id,
                        __(
                            'Fix this ID before saving, or clear it.',
                            'woocommerce-conversion-tracking'
                        ),
                    ] )
                )
            );
            focusControl( invalid[ 0 ].id );

            return;
        }

        const body: SettingsUpdate = {};

        if ( Object.keys( changes ).length ) {
            body.platforms = { [ id ]: changes };
        }

        if ( consentChanged ) {
            body.consent_mode = draft.consent;
        }

        const sent = draft;

        setSaving( true );

        saveSettings( body )
            .then( ( next ) => {
                if ( ! mounted.current ) {
                    return;
                }

                const fresh: Draft = {
                    fields: draftFrom( platform, next.platforms[ id ] ?? {} ),
                    consent: next.consent_mode === true,
                };

                // Mark it clean first, so the refetch effect adopts the saved values.
                dirtyRef.current = false;
                setSaved( fresh );
                autoOn.current.clear();
                // Edits made while saving stay.
                setDraft( ( prev ) => ( prev === sent ? fresh : prev ) );
                setErrors( {} );
                setAttempted( false );
                replaceSettings( next );

                const message = card.connected
                    ? __( 'Saved.', 'woocommerce-conversion-tracking' )
                    : __(
                          'Connected. We wait for the first event from your store.',
                          'woocommerce-conversion-tracking'
                      );

                setNote( message );
                toast( message );
            } )
            .catch( ( reason: unknown ) => {
                if ( ! mounted.current ) {
                    return;
                }

                const { code } = ( reason ?? {} ) as ApiError;

                if ( code === 'wcct_rest_migration_pending' ) {
                    setPending( true );

                    return;
                }

                const fieldErrors = responseErrors( reason, id );
                const first = Object.keys( fieldErrors ).find(
                    ( key ) => key !== ''
                );

                setErrors( fieldErrors );
                setSaveError( errorMessage( reason ) );

                // The first field the server refused, opened and focused like an invalid ID.
                if ( first ) {
                    focusControl( first );
                }
            } )
            .finally( () => mounted.current && setSaving( false ) );
    };

    if ( ! data ) {
        return null;
    }

    const docsUrl =
        window.wcctAdmin?.docs.tiles.find(
            ( tile ) => tile.id === DOCS_TILES[ id ]
        )?.url ?? '';
    const events = eventOptions( platform );
    const visible = ( field: SchemaElement ) => applies( field, draft.fields );
    const connection = sectionFields( platform, 'connection' ).filter(
        visible
    );
    const advanced = sectionFields( platform, 'advanced' ).filter( visible );
    const pixelsField = advanced.find(
        ( field ) => field.variant === PIXELS_VARIANT
    );
    const parametersField = advanced.find(
        ( field ) => field.variant === PARAMETERS_VARIANT
    );
    const consentNotice = text( consentField?.notice );
    const resetup = resetupEvents(
        platform,
        draft.fields,
        values.needs_resetup
    );
    const split = values.ads_id_split as Record< string, unknown > | null;

    // Renders one schema field with the control for its variant.
    const renderField = ( field: SchemaElement ) => {
        const value = draft.fields[ field.id ];
        const readOnly = field.readonly === true;
        const error = errors[ field.id ];

        if ( field.format === 'id' ) {
            return (
                <IdField
                    key={ field.id }
                    platform={ id }
                    field={ field }
                    value={ text( value ) }
                    connected={ card.connected }
                    error={ error }
                    readOnly={ readOnly }
                    onChange={ ( next ) => setField( field.id, next ) }
                />
            );
        }

        if ( field.variant === 'radio_capsule' ) {
            return (
                <RadioField
                    key={ field.id }
                    platform={ id }
                    field={ field }
                    readOnly={ readOnly }
                    value={ text( value ) }
                    onChange={ ( next ) => setField( field.id, next ) }
                />
            );
        }

        if ( field.id === 'skip_roles' ) {
            return (
                <SkipRolesField
                    key={ field.id }
                    platform={ id }
                    field={ field }
                    readOnly={ readOnly }
                    error={ error }
                    value={ value }
                    onChange={ ( next ) => setField( field.id, next ) }
                />
            );
        }

        if ( field.variant === 'select' ) {
            const keptNote = text( field.kept_note );

            return (
                <SelectField
                    key={ field.id }
                    platform={ id }
                    field={ field }
                    readOnly={ readOnly }
                    error={ error }
                    value={ text( value ) }
                    onChange={ ( next ) => setField( field.id, next ) }
                >
                    { field.id === 'value_mode' &&
                        keptNote &&
                        value === 'all' && (
                            <p
                                className="m-0 max-w-140 text-[13px] text-muted-foreground"
                                data-testid={ `${ controlId(
                                    id,
                                    field.id
                                ) }-kept` }
                            >
                                { keptNote }
                            </p>
                        ) }
                    { field.id === 'product_id_mode' &&
                        id === 'facebook' &&
                        isPro() && (
                            <a
                                href="#/catalog"
                                className="self-start text-[13px] text-primary underline underline-offset-3"
                            >
                                { __(
                                    'Check the ID your catalog feed uses',
                                    'woocommerce-conversion-tracking'
                                ) }
                            </a>
                        ) }
                </SelectField>
            );
        }

        if ( field.variant === 'switch' ) {
            const locked = field.pro === true && field.disabled === true;

            return (
                <SwitchRow
                    key={ field.id }
                    id={ controlId( id, field.id ) }
                    title={ text( field.label ) || field.id }
                    description={ text( field.description ) }
                    checked={ value === true && ! locked }
                    disabled={ readOnly || locked }
                    locked={ locked }
                    onChange={ ( next ) => setField( field.id, next ) }
                />
            );
        }

        return null;
    };

    let footNote = note;

    if ( saving ) {
        footNote = __( 'Saving…', 'woocommerce-conversion-tracking' );
    } else if ( dirty ) {
        footNote = __(
            'You have unsaved changes.',
            'woocommerce-conversion-tracking'
        );
    } else if ( ! card.connected && ! note ) {
        footNote = __(
            'Not connected yet. After you connect, we wait for the first event from your store.',
            'woocommerce-conversion-tracking'
        );
    }

    let saveLabel: string = card.connected
        ? __( 'Saved', 'woocommerce-conversion-tracking' )
        : __( 'Connect', 'woocommerce-conversion-tracking' );

    if ( saving ) {
        saveLabel = __( 'Saving…', 'woocommerce-conversion-tracking' );
    } else if ( dirty && card.connected ) {
        saveLabel = __( 'Save changes', 'woocommerce-conversion-tracking' );
    }

    return (
        <section
            aria-labelledby="wcct-platform-title"
            className="flex flex-col"
            data-testid="wcct-platform-status"
            data-platform={ id }
        >
            <div className="flex items-start gap-3.5 border-b border-border px-6 py-5">
                <PlatformLogo src={ platformLogoUrl( card.logo ) } />
                <div className="min-w-0 flex-1">
                    <h2
                        id="wcct-platform-title"
                        className="m-0 p-0 text-base leading-snug font-semibold text-foreground"
                    >
                        { card.name }
                        { id === 'twitter' && ' ' }
                        { id === 'twitter' && (
                            <span className="text-[13px] font-normal text-muted-foreground">
                                { sprintf(
                                    /* translators: %s: the platform's former name */
                                    __(
                                        '(formerly %s)',
                                        'woocommerce-conversion-tracking'
                                    ),
                                    'Twitter'
                                ) }
                            </span>
                        ) }
                    </h2>
                    <p className="m-0 mt-0.5 text-sm text-muted-foreground">
                        { intro( id ) }{ ' ' }
                        { docsUrl && (
                            <a
                                href={ docsUrl }
                                target="_blank"
                                rel="noreferrer"
                                className="font-medium text-primary underline underline-offset-3"
                            >
                                { __(
                                    'Learn more',
                                    'woocommerce-conversion-tracking'
                                ) }
                                <NewTabNote />
                            </a>
                        ) }
                    </p>
                </div>
                <span data-testid="wcct-platform-pill">
                    <Status
                        card={ card }
                        lastPurchase={ lastPurchase }
                        attention={ needsAttention( platform, values ) }
                    />
                </span>
                <Button
                    variant="outline"
                    size="sm"
                    className="min-[1280px]:hidden"
                    aria-expanded={ helpOpen }
                    onClick={ () => setHelpOpen( true ) }
                    data-testid="wcct-help-drawer-open"
                >
                    <CircleHelp className="size-4" aria-hidden="true" />
                    { __( 'Help', 'woocommerce-conversion-tracking' ) }
                </Button>
            </div>

            <div className="grid grid-cols-1 min-[1280px]:grid-cols-[minmax(0,1fr)_320px]">
                <div className="flex min-w-0 flex-col gap-6 p-6 min-[1280px]:border-r min-[1280px]:border-border">
                    { readOnlyAll && (
                        <Notice
                            variant="warning"
                            role="status"
                            data-testid="wcct-platform-read-only"
                        >
                            <p className="m-0">
                                { __(
                                    'You can see these settings but not change them. Ask an administrator if something needs to change.',
                                    'woocommerce-conversion-tracking'
                                ) }
                            </p>
                        </Notice>
                    ) }

                    { resetup.length > 0 && ! readOnlyAll && (
                        <XResetupNotice platform={ id } events={ resetup } />
                    ) }

                    { split && id === 'google' && (
                        <GoogleSplitNotice saved={ text( split.saved ) } />
                    ) }

                    { connection
                        .filter( ( field ) => field.variant !== 'switch' )
                        .map( renderField ) }

                    { events.length > 0 && (
                        <EventsTable
                            platform={ id }
                            events={ events }
                            values={ draft.fields }
                            lastPurchase={ lastPurchase }
                            connected={ card.connected }
                            readOnly={ readOnlyAll }
                            onChange={ ( next ) => setField( 'events', next ) }
                        />
                    ) }

                    { consentField && (
                        <section
                            id={ COOKIE_CHOICES }
                            aria-labelledby={ `${ COOKIE_CHOICES }-title` }
                            className="flex flex-col gap-3"
                            data-testid="wcct-cookie-choices"
                        >
                            <h3
                                id={ `${ COOKIE_CHOICES }-title` }
                                tabIndex={ -1 }
                                className="m-0 text-sm font-semibold text-foreground focus:outline-none"
                            >
                                { __(
                                    'Cookie choices',
                                    'woocommerce-conversion-tracking'
                                ) }
                            </h3>
                            <SwitchRow
                                id={ controlId( id, 'consent_mode' ) }
                                title={ text( consentField.label ) }
                                description={ text( consentField.description ) }
                                checked={ draft.consent }
                                disabled={ consentReadOnly }
                                onChange={ ( consent ) => {
                                    setDraft( ( prev ) => ( {
                                        ...prev,
                                        consent,
                                    } ) );
                                    setNote( '' );
                                } }
                            >
                                <p className="m-0 mt-1 text-[13px] text-muted-foreground">
                                    { __(
                                        'One setting for every platform: it changes here and on each platform page.',
                                        'woocommerce-conversion-tracking'
                                    ) }
                                </p>
                                { consentNotice && (
                                    <p
                                        className="m-0 mt-1 text-[13px] text-info-foreground"
                                        data-testid="wcct-consent-handled-by"
                                    >
                                        { consentNotice }
                                    </p>
                                ) }
                                { errors.consent_mode && (
                                    <p className="m-0 mt-1 text-[13px] text-destructive">
                                        { errors.consent_mode }
                                    </p>
                                ) }
                            </SwitchRow>
                        </section>
                    ) }

                    { connection
                        .filter( ( field ) => field.variant === 'switch' )
                        .map( renderField ) }

                    { id === 'google' &&
                        ! isPro() &&
                        ! platform.schema.some(
                            ( element ) => element.id === 'enhanced_conversions'
                        ) && (
                            <div
                                className="flex items-center justify-between gap-3 rounded-lg border border-dashed border-input bg-muted/50 px-4 py-3 text-[13px]"
                                data-testid="wcct-google-pro-preview"
                            >
                                <span>
                                    { __(
                                        'Enhanced conversions (hashed customer details so Ads matches more sales) are Pro.',
                                        'woocommerce-conversion-tracking'
                                    ) }
                                </span>
                                <a
                                    href={ upgradeUrl() }
                                    target="_blank"
                                    rel="noreferrer"
                                    className="shrink-0 font-medium text-primary underline underline-offset-3"
                                >
                                    { __(
                                        'Compare plans',
                                        'woocommerce-conversion-tracking'
                                    ) }
                                    <NewTabNote />
                                </a>
                            </div>
                        ) }

                    <AdvancedSettings
                        platform={ platform }
                        open={ advancedOpen }
                        onToggle={ setAdvancedOpen }
                        subtitle={ advancedSubtitle( id ) }
                    >
                        { advanced.map( renderField ) }
                        { parametersField && (
                            <CustomParameters
                                platform={ id }
                                field={ parametersField }
                                parameters={ asParameters(
                                    draft.fields[ parametersField.id ]
                                ) }
                                events={ events }
                                readOnly={ parametersField.readonly === true }
                                attempted={ attempted }
                                error={ errors[ parametersField.id ] }
                                onChange={ ( next ) =>
                                    setField( parametersField.id, next )
                                }
                            />
                        ) }
                        { pixelsField && (
                            <ExtraPixels
                                platform={ id }
                                field={ pixelsField }
                                pixels={ asPixels(
                                    draft.fields[ pixelsField.id ]
                                ) }
                                main={ text( draft.fields.pixel_id ) }
                                events={ events }
                                readOnly={ pixelsField.readonly === true }
                                attempted={ attempted }
                                error={ errors[ pixelsField.id ] }
                                onChange={ ( next ) =>
                                    setField( pixelsField.id, next )
                                }
                            />
                        ) }
                        { showsMetaPreview( id ) && <MetaProPreview /> }
                        <DeveloperBlock platform={ platform } />
                    </AdvancedSettings>
                </div>

                <aside
                    aria-label={ __(
                        'Help',
                        'woocommerce-conversion-tracking'
                    ) }
                    className="hidden flex-col gap-5 bg-muted/50 p-6 min-[1280px]:flex"
                    data-testid="wcct-platform-help"
                >
                    <HelpContent id={ id } />
                </aside>
            </div>

            <Sheet open={ helpOpen } onOpenChange={ setHelpOpen }>
                <SheetContent
                    side="right"
                    className="w-[min(360px,100vw)] overflow-y-auto"
                    data-testid="wcct-help-drawer"
                >
                    <SheetHeader>
                        <SheetTitle className="m-0 p-0 text-base font-semibold">
                            { __( 'Help', 'woocommerce-conversion-tracking' ) }
                        </SheetTitle>
                    </SheetHeader>
                    <div className="flex flex-col gap-5 px-4 pb-6">
                        <HelpContent id={ id } />
                    </div>
                </SheetContent>
            </Sheet>

            { pending && (
                <div className="px-6 pb-4">
                    <Notice
                        variant="warning"
                        role="alert"
                        data-testid="wcct-platform-migration-pending"
                    >
                        <NoticeTitle>
                            { __(
                                "Your changes weren't saved yet.",
                                'woocommerce-conversion-tracking'
                            ) }
                        </NoticeTitle>
                        <p className="m-0">
                            { __(
                                'Your settings are still being updated to 3.0. Your changes are kept on this page; try again in a moment.',
                                'woocommerce-conversion-tracking'
                            ) }
                        </p>
                        <div>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={ save }
                                disabled={ saving }
                            >
                                { __(
                                    'Try again',
                                    'woocommerce-conversion-tracking'
                                ) }
                            </Button>
                        </div>
                    </Notice>
                </div>
            ) }

            { saveError && (
                <div className="px-6 pb-4">
                    <Notice
                        variant="destructive"
                        role="alert"
                        data-testid="wcct-platform-save-error"
                    >
                        <NoticeTitle>
                            { __(
                                "Your changes weren't saved.",
                                'woocommerce-conversion-tracking'
                            ) }
                        </NoticeTitle>
                        <p className="m-0">{ saveError }</p>
                    </Notice>
                </div>
            ) }

            <div className="flex flex-wrap items-center gap-3 border-t border-border px-6 py-4">
                { card.connected && ! readOnlyAll && (
                    <PauseTracking
                        id={ id }
                        name={ card.name }
                        paused={ card.paused }
                    />
                ) }
                <span
                    className="text-[13px] text-muted-foreground"
                    role="status"
                    data-testid="wcct-platform-note"
                >
                    { footNote }
                </span>
                { ! readOnlyAll && ( ! card.paused || dirty ) && (
                    <Button
                        className="ml-auto"
                        onClick={ save }
                        disabled={ ! dirty || saving }
                        data-testid="wcct-platform-save"
                    >
                        { saving && <Spinner aria-hidden="true" /> }
                        { saveLabel }
                    </Button>
                ) }
            </div>
        </section>
    );
};
