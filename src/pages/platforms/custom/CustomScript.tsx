/**
 * External dependencies
 */
import type { ReactNode } from 'react';
import {
    Button,
    Label,
    Notice,
    NoticeTitle,
    Switch,
    Textarea,
    cn,
} from '@wedevs/plugin-ui';
import { PlatformLogo, StatusPill } from '@wcct/components';

/**
 * WordPress dependencies
 */
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { useAppData } from '../../../data/AppData';
import { errorMessage, saveSettings } from '../../../data/api';
import { isConnected, platformLogoUrl } from '../../../data/platforms';
import type { PlatformValues, SchemaPlatform } from '../../../data/types';
import { NewTabNote } from '../../../shell/NewTabNote';
import { useUnsavedChanges } from '../../../shell/useUnsavedChanges';
import {
    CUSTOMER_PLACEHOLDERS,
    ORDER_ALIASES,
    ORDER_PLACEHOLDERS,
    PLACEMENTS,
    insertAt,
    placeholderHints,
    type Placement,
} from './placeholders';
import { HelpColumn } from './HelpColumn';

const PLATFORM = 'custom';

// plugin-ui's Textarea takes no ref, so the code boxes are found by ID.
const editorOf = ( placement: Placement ) =>
    document.getElementById(
        `wcct-custom-${ placement }-code`
    ) as HTMLTextAreaElement | null;

type PlacementDraft = { enabled: boolean; script: string };

type Draft = Record< Placement, PlacementDraft > & { customerDetails: boolean };

type FieldErrors = Partial< Record< Placement, string > >;

type ApiError = {
    code?: string;
    data?: { errors?: Record< string, unknown > };
};

/**
 * Title, description and code box label of each placement.
 */
const placementText = (): Record<
    Placement,
    { title: string; description: string; label: string }
> => ( {
    thankyou: {
        title: __( 'Thank-you page', 'woocommerce-conversion-tracking' ),
        description: __(
            'After a paid order. Order and customer placeholders fill in here.',
            'woocommerce-conversion-tracking'
        ),
        label: __(
            'Script for thank-you page',
            'woocommerce-conversion-tracking'
        ),
    },
    header: {
        title: __(
            'Every page, in the header',
            'woocommerce-conversion-tracking'
        ),
        description: __(
            'Base tags and site-wide pixels. Order placeholders stay empty here.',
            'woocommerce-conversion-tracking'
        ),
        label: __(
            'Script for every page, in the header',
            'woocommerce-conversion-tracking'
        ),
    },
    signup: {
        title: __( 'Sign-up', 'woocommerce-conversion-tracking' ),
        description: __(
            'After a shopper creates an account.',
            'woocommerce-conversion-tracking'
        ),
        label: __( 'Script for sign-up', 'woocommerce-conversion-tracking' ),
    },
} );

const text = ( value: unknown ) => ( typeof value === 'string' ? value : '' );

// Browsers post textarea line breaks as LF; the stored script may have CRLF from the classic page.
const sameScript = ( a: string, b: string ) =>
    a.replace( /\r\n?/g, '\n' ) === b.replace( /\r\n?/g, '\n' );

const draftFrom = ( values: PlatformValues ): Draft => ( {
    thankyou: {
        enabled: values.thankyou_enabled === true,
        script: text( values.thankyou_script ),
    },
    header: {
        enabled: values.header_enabled === true,
        script: text( values.header_script ),
    },
    signup: {
        enabled: values.signup_enabled === true,
        script: text( values.signup_script ),
    },
    customerDetails: values.customer_details === true,
} );

/**
 * The changed values, keyed as the settings route takes them.
 *
 * @param {Draft} draft Draft.
 * @param {Draft} saved Saved values.
 */
const changes = ( draft: Draft, saved: Draft ): PlatformValues => {
    const body: PlatformValues = {};

    for ( const placement of PLACEMENTS ) {
        if ( draft[ placement ].enabled !== saved[ placement ].enabled ) {
            body[ `${ placement }_enabled` ] = draft[ placement ].enabled;
        }

        if (
            ! sameScript( draft[ placement ].script, saved[ placement ].script )
        ) {
            body[ `${ placement }_script` ] = draft[ placement ].script;
        }
    }

    if ( draft.customerDetails !== saved.customerDetails ) {
        body.customer_details = draft.customerDetails;
    }

    return body;
};

/**
 * Whether the schema sends the Custom script read-only: the user can't use unfiltered_html.
 *
 * @param {SchemaPlatform|undefined} platform Custom platform schema.
 */
const isReadOnly = ( platform?: SchemaPlatform ) =>
    !! platform?.schema.some(
        ( element ) => element.type === 'field' && element.readonly === true
    );

/**
 * The per-field messages of a 400 (data.errors).
 *
 * @param {unknown} reason What apiFetch rejected with.
 */
const responseErrors = ( reason: unknown ): Record< string, string > => {
    const errors = ( ( reason ?? {} ) as ApiError ).data?.errors;
    const out: Record< string, string > = {};

    if ( errors && typeof errors === 'object' ) {
        for ( const [ key, message ] of Object.entries( errors ) ) {
            if ( typeof message === 'string' ) {
                out[ key ] = message;
            }
        }
    }

    return out;
};

/**
 * A row of placeholder buttons; each puts its placeholder in the open script.
 *
 * @param {Object}   props          Component props.
 * @param {string[]} props.items    Placeholders.
 * @param {boolean}  props.disabled No script to put them in.
 * @param {Function} props.onInsert Insert handler.
 */
const PlaceholderList = ( {
    items,
    disabled,
    onInsert,
}: {
    items: string[];
    disabled: boolean;
    onInsert: ( placeholder: string ) => void;
} ) => (
    <ul className="m-0 flex list-none flex-wrap gap-2 p-0">
        { items.map( ( item ) => (
            <li key={ item } className="m-0">
                <button
                    type="button"
                    disabled={ disabled }
                    onClick={ () => onInsert( item ) }
                    aria-label={ sprintf(
                        /* translators: %s: placeholder, like {order_total}. */
                        __( 'Insert %s', 'woocommerce-conversion-tracking' ),
                        item
                    ) }
                    className="cursor-pointer rounded-md border border-border bg-muted px-2 py-1 font-mono text-[13px] text-foreground hover:border-primary focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none disabled:cursor-default disabled:hover:border-border"
                >
                    { item }
                </button>
            </li>
        ) ) }
    </ul>
);

/**
 * A small grey tag beside a heading.
 *
 * @param {Object}    props          Component props.
 * @param {ReactNode} props.children Text.
 */
const Tag = ( { children }: { children: ReactNode } ) => (
    <span className="rounded-full bg-muted px-2 py-0.5 text-xs font-normal text-muted-foreground">
        { children }
    </span>
);

/**
 * The Custom script page: three placements, the placeholders and the customer details switch.
 */
const CustomScript = () => {
    const { data, replaceSettings } = useAppData();
    const platform = data?.schema.platforms.find(
        ( item ) => item.id === PLATFORM
    );
    const values = useMemo(
        () => data?.settings.platforms[ PLATFORM ] ?? {},
        [ data ]
    );
    const incoming = useMemo( () => draftFrom( values ), [ values ] );
    const readOnly = isReadOnly( platform );
    const [ saved, setSaved ] = useState( incoming );
    const [ draft, setDraft ] = useState( incoming );
    const [ errors, setErrors ] = useState< FieldErrors >( {} );
    const [ saveErrors, setSaveErrors ] = useState< string[] >( [] );
    const [ pending, setPending ] = useState( false );
    const [ saving, setSaving ] = useState( false );
    const [ note, setNote ] = useState( '' );
    const [ target, setTarget ] = useState< Placement | null >( null );
    const focused = useRef( new Set< Placement >() );
    const caret = useRef< { placement: Placement; at: number } | null >( null );
    const mounted = useRef( true );

    const body = changes( draft, saved );
    const dirty = ! readOnly && Object.keys( body ).length > 0;
    const dirtyRef = useRef( dirty );

    dirtyRef.current = dirty;

    useUnsavedChanges( dirty );

    useEffect( () => {
        mounted.current = true;

        return () => {
            mounted.current = false;
        };
    }, [] );

    // Fresh values from a refetch replace the form, unless it has unsaved changes.
    const incomingKey = JSON.stringify( incoming );

    useEffect( () => {
        if ( ! dirtyRef.current ) {
            setSaved( incoming );
            setDraft( incoming );
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps -- keyed by content, not identity.
    }, [ incomingKey ] );

    // Put the caret after an inserted placeholder.
    useEffect( () => {
        const next = caret.current;

        if ( ! next ) {
            return;
        }

        caret.current = null;

        const editor = editorOf( next.placement );

        editor?.focus();
        editor?.setSelectionRange( next.at, next.at );
    }, [ draft ] );

    const update = ( next: Partial< Draft > ) => {
        setDraft( ( prev ) => ( { ...prev, ...next } ) );
        setNote( '' );
    };

    const updatePlacement = (
        placement: Placement,
        next: Partial< PlacementDraft >
    ) => {
        update( { [ placement ]: { ...draft[ placement ], ...next } } );
        setErrors( ( prev ) => ( { ...prev, [ placement ]: undefined } ) );
    };

    // Placeholders go to the script last focused, else the first one switched on.
    const insertInto: Placement | null =
        target && draft[ target ].enabled
            ? target
            : PLACEMENTS.find( ( placement ) => draft[ placement ].enabled ) ??
              null;

    const insert = ( placeholder: string ) => {
        if ( ! insertInto ) {
            return;
        }

        const editor = editorOf( insertInto );
        const script = draft[ insertInto ].script;
        // A script never focused gets it at the end.
        const known = editor && focused.current.has( insertInto );
        const start = known ? editor.selectionStart : script.length;
        const end = known ? editor.selectionEnd : script.length;
        const result = insertAt( script, start, end, placeholder );

        caret.current = { placement: insertInto, at: result.caret };
        updatePlacement( insertInto, { script: result.value } );
    };

    const save = () => {
        const missing: FieldErrors = {};

        for ( const placement of PLACEMENTS ) {
            if (
                draft[ placement ].enabled &&
                draft[ placement ].script.trim() === ''
            ) {
                missing[ placement ] = __(
                    'Paste a script, or turn this placement off.',
                    'woocommerce-conversion-tracking'
                );
            }
        }

        setErrors( missing );
        setSaveErrors( [] );
        setPending( false );

        const first = PLACEMENTS.find( ( placement ) => missing[ placement ] );

        if ( first ) {
            editorOf( first )?.focus();

            return;
        }

        const sent = draft;

        setSaving( true );

        saveSettings( { platforms: { [ PLATFORM ]: body } } )
            .then( ( next ) => {
                if ( ! mounted.current ) {
                    return;
                }

                const fresh = draftFrom( next.platforms[ PLATFORM ] ?? {} );

                // Mark it clean first, so the refetch effect adopts the saved values.
                dirtyRef.current = false;
                setSaved( fresh );
                // Edits made while saving stay.
                setDraft( ( prev ) => ( prev === sent ? fresh : prev ) );
                replaceSettings( next );
                setNote( __( 'Saved.', 'woocommerce-conversion-tracking' ) );
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

                const fields = responseErrors( reason );
                const byPlacement: FieldErrors = {};
                const other: string[] = [];

                for ( const [ key, message ] of Object.entries( fields ) ) {
                    const match = key.match(
                        /^platforms\.custom\.(thankyou|header|signup)_/
                    );

                    if ( match ) {
                        byPlacement[ match[ 1 ] as Placement ] = message;
                    } else {
                        other.push( message );
                    }
                }

                setErrors( byPlacement );
                setSaveErrors( [ errorMessage( reason ), ...other ] );
            } )
            .finally( () => mounted.current && setSaving( false ) );
    };

    if ( ! data || ! platform ) {
        return null;
    }

    const labels = placementText();
    // One string with the settings schema (Settings\Schema::custom()).
    const customerDetailsHelp = String(
        platform.schema.find( ( element ) => element.id === 'customer_details' )
            ?.description ?? ''
    );
    const connected = isConnected( platform, values );
    const docsUrl =
        window.wcctAdmin?.docs.tiles.find( ( tile ) => tile.id === 'custom' )
            ?.url ?? '';
    const nothingSaved = PLACEMENTS.every(
        ( placement ) => saved[ placement ].script.trim() === ''
    );

    let footNote = note;

    if ( saving ) {
        footNote = __( 'Saving…', 'woocommerce-conversion-tracking' );
    } else if ( dirty ) {
        footNote = __(
            'You have unsaved changes.',
            'woocommerce-conversion-tracking'
        );
    } else if ( ! note && nothingSaved ) {
        footNote = __( 'Nothing saved yet', 'woocommerce-conversion-tracking' );
    }

    let saveLabel: string = __( 'Saved', 'woocommerce-conversion-tracking' );

    if ( saving ) {
        saveLabel = __( 'Saving…', 'woocommerce-conversion-tracking' );
    } else if ( dirty ) {
        saveLabel = __( 'Save changes', 'woocommerce-conversion-tracking' );
    }

    const [ saveError, ...saveDetails ] = saveErrors;

    return (
        <section
            aria-labelledby="wcct-platform-title"
            data-testid="wcct-custom-script"
        >
            <div className="flex items-start gap-3.5 border-b border-border px-6 py-5">
                <PlatformLogo src={ platformLogoUrl( 'custom.svg' ) } />
                <div className="min-w-0 flex-1">
                    <h2
                        id="wcct-platform-title"
                        className="m-0 p-0 text-base leading-snug font-semibold text-foreground"
                    >
                        { __(
                            'Custom script',
                            'woocommerce-conversion-tracking'
                        ) }
                    </h2>
                    <p className="m-0 mt-0.5 text-sm text-muted-foreground">
                        { __(
                            "Paste a tag for any platform we don't support yet. It runs where you choose.",
                            'woocommerce-conversion-tracking'
                        ) }{ ' ' }
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
                <StatusPill tone={ connected ? 'ok' : 'off' }>
                    { connected
                        ? __( 'On', 'woocommerce-conversion-tracking' )
                        : __(
                              'Not set up',
                              'woocommerce-conversion-tracking'
                          ) }
                </StatusPill>
            </div>

            <div className="grid grid-cols-[minmax(0,1fr)_320px] max-[1100px]:grid-cols-1">
                <div className="flex min-w-0 flex-col gap-8 p-6">
                    { readOnly ? (
                        <Notice
                            variant="warning"
                            role="status"
                            data-testid="wcct-custom-read-only"
                        >
                            <NoticeTitle>
                                { __(
                                    'You can see these scripts but not change them.',
                                    'woocommerce-conversion-tracking'
                                ) }
                            </NoticeTitle>
                            <p className="m-0">
                                { __(
                                    "Only administrators can add or change custom scripts, because a script runs in every visitor's browser. Ask an administrator if a script needs to change.",
                                    'woocommerce-conversion-tracking'
                                ) }
                            </p>
                        </Notice>
                    ) : (
                        <Notice variant="info" role="note">
                            <p className="m-0">
                                { __(
                                    "Only administrators can add or change custom scripts, because a script runs in every visitor's browser. Other roles you give access to see this page read-only.",
                                    'woocommerce-conversion-tracking'
                                ) }
                            </p>
                        </Notice>
                    ) }

                    <section
                        aria-labelledby="wcct-custom-placements"
                        className="flex flex-col gap-3"
                    >
                        <h3
                            id="wcct-custom-placements"
                            className="m-0 text-sm font-semibold text-foreground"
                        >
                            { __(
                                'Where it runs',
                                'woocommerce-conversion-tracking'
                            ) }
                        </h3>
                        { PLACEMENTS.map( ( placement ) => {
                            const { title, description, label } =
                                labels[ placement ];
                            const value = draft[ placement ];
                            const id = `wcct-custom-${ placement }`;
                            const hints = placeholderHints(
                                value.script,
                                placement,
                                draft.customerDetails
                            );
                            const error = errors[ placement ];
                            // Read-only users still see a stored script that is switched off.
                            const open =
                                value.enabled ||
                                ( readOnly && value.script.trim() !== '' );
                            const describedBy = [
                                error && `${ id }-error`,
                                hints.length > 0 && `${ id }-hints`,
                            ]
                                .filter( Boolean )
                                .join( ' ' );

                            return (
                                <div
                                    key={ placement }
                                    className="rounded-lg border border-border"
                                    data-testid={ `${ id }-row` }
                                >
                                    <div className="flex items-center gap-3.5 px-4 py-3.5">
                                        <Switch
                                            id={ id }
                                            checked={ value.enabled }
                                            disabled={ readOnly }
                                            onCheckedChange={ ( enabled ) =>
                                                updatePlacement( placement, {
                                                    enabled,
                                                } )
                                            }
                                            aria-labelledby={ `${ id }-title` }
                                            aria-describedby={ `${ id }-desc` }
                                        />
                                        <div className="min-w-0 flex-1">
                                            <div
                                                id={ `${ id }-title` }
                                                className="text-sm font-medium text-foreground"
                                            >
                                                { title }
                                            </div>
                                            <div
                                                id={ `${ id }-desc` }
                                                className="text-[13px] text-muted-foreground"
                                            >
                                                { description }
                                            </div>
                                        </div>
                                        <StatusPill
                                            tone={
                                                value.enabled ? 'ok' : 'off'
                                            }
                                        >
                                            { value.enabled
                                                ? __(
                                                      'On',
                                                      'woocommerce-conversion-tracking'
                                                  )
                                                : __(
                                                      'Off',
                                                      'woocommerce-conversion-tracking'
                                                  ) }
                                        </StatusPill>
                                    </div>
                                    { open && (
                                        <div className="flex flex-col gap-1.5 border-t border-border px-4 py-3.5">
                                            <Label htmlFor={ `${ id }-code` }>
                                                { label }
                                            </Label>
                                            <Textarea
                                                id={ `${ id }-code` }
                                                value={ value.script }
                                                readOnly={ readOnly }
                                                placeholder={ __(
                                                    'Paste your tag here',
                                                    'woocommerce-conversion-tracking'
                                                ) }
                                                onChange={ ( event ) =>
                                                    updatePlacement(
                                                        placement,
                                                        {
                                                            script: event.target
                                                                .value,
                                                        }
                                                    )
                                                }
                                                onFocus={ () => {
                                                    focused.current.add(
                                                        placement
                                                    );
                                                    setTarget( placement );
                                                } }
                                                aria-invalid={
                                                    error ? true : undefined
                                                }
                                                aria-describedby={
                                                    describedBy || undefined
                                                }
                                                spellCheck={ false }
                                                autoCapitalize="off"
                                                autoComplete="off"
                                                autoCorrect="off"
                                                dir="ltr"
                                                className={ cn(
                                                    'max-h-[480px] min-h-[120px] font-mono text-[13px] leading-relaxed',
                                                    readOnly &&
                                                        'bg-muted text-foreground'
                                                ) }
                                            />
                                            { error && (
                                                <p
                                                    id={ `${ id }-error` }
                                                    className="m-0 text-[13px] text-destructive"
                                                >
                                                    { error }
                                                </p>
                                            ) }
                                            { hints.length > 0 && (
                                                <ul
                                                    id={ `${ id }-hints` }
                                                    className="m-0 list-none p-0 text-[13px] text-tone-warn-fg"
                                                    data-testid={ `${ id }-hints` }
                                                >
                                                    { hints.map( ( hint ) => (
                                                        <li
                                                            key={ hint }
                                                            className="m-0"
                                                        >
                                                            { hint }
                                                        </li>
                                                    ) ) }
                                                </ul>
                                            ) }
                                        </div>
                                    ) }
                                </div>
                            );
                        } ) }
                    </section>

                    <section
                        aria-labelledby="wcct-custom-placeholders"
                        className="flex flex-col gap-3"
                        data-testid="wcct-custom-placeholders"
                    >
                        <h3
                            id="wcct-custom-placeholders"
                            className="m-0 text-sm font-semibold text-foreground"
                        >
                            { __(
                                'Placeholders',
                                'woocommerce-conversion-tracking'
                            ) }
                        </h3>
                        <p className="m-0 text-[13px] text-muted-foreground">
                            { __(
                                'Type these into a script and we fill in the real value when it runs.',
                                'woocommerce-conversion-tracking'
                            ) }{ ' ' }
                            { ! readOnly &&
                                ( insertInto
                                    ? sprintf(
                                          /* translators: %s: placement, like Thank-you page. */
                                          __(
                                              'Select one to put it in the "%s" script at the cursor.',
                                              'woocommerce-conversion-tracking'
                                          ),
                                          labels[ insertInto ].title
                                      )
                                    : __(
                                          'Turn on a placement to put them in its script.',
                                          'woocommerce-conversion-tracking'
                                      ) ) }
                        </p>

                        <div className="flex flex-col gap-2">
                            <div className="flex flex-wrap items-center gap-2 text-sm font-medium text-foreground">
                                { __(
                                    'Order',
                                    'woocommerce-conversion-tracking'
                                ) }
                                <Tag>
                                    { __(
                                        'Thank-you page only',
                                        'woocommerce-conversion-tracking'
                                    ) }
                                </Tag>
                            </div>
                            <PlaceholderList
                                items={ ORDER_PLACEHOLDERS }
                                disabled={ readOnly || ! insertInto }
                                onInsert={ insert }
                            />
                            <p className="m-0 text-[13px] text-muted-foreground">
                                { sprintf(
                                    /* translators: 1: {order_id}, 2: {order_number}, 3: {used_coupons}. */
                                    __(
                                        'Scripts from earlier versions keep working: %1$s is the same as %2$s, and %3$s gives the coupon codes, separated by commas.',
                                        'woocommerce-conversion-tracking'
                                    ),
                                    ORDER_ALIASES[ 0 ],
                                    ORDER_PLACEHOLDERS[ 0 ],
                                    ORDER_ALIASES[ 1 ]
                                ) }
                            </p>
                        </div>

                        <div className="flex flex-col gap-2">
                            <div className="flex flex-wrap items-center gap-2 text-sm font-medium text-foreground">
                                { __(
                                    'Customer',
                                    'woocommerce-conversion-tracking'
                                ) }
                                <Tag>
                                    { __(
                                        'Personal data',
                                        'woocommerce-conversion-tracking'
                                    ) }
                                </Tag>
                                <Tag>
                                    { __(
                                        'Thank-you page only, with customer details on',
                                        'woocommerce-conversion-tracking'
                                    ) }
                                </Tag>
                            </div>
                            <PlaceholderList
                                items={ CUSTOMER_PLACEHOLDERS }
                                disabled={ readOnly || ! insertInto }
                                onInsert={ insert }
                            />
                        </div>

                        <div
                            className="flex items-center gap-4 rounded-lg border border-border px-4 py-3.5"
                            id="customer-details"
                        >
                            <div className="min-w-0 flex-1">
                                <div
                                    id="wcct-custom-customer-details-title"
                                    className="text-sm font-medium text-foreground"
                                >
                                    { __(
                                        'Include customer details in scripts',
                                        'woocommerce-conversion-tracking'
                                    ) }
                                </div>
                                <div
                                    id="wcct-custom-customer-details-desc"
                                    className="text-[13px] text-muted-foreground"
                                >
                                    { customerDetailsHelp }
                                </div>
                            </div>
                            <Switch
                                id="wcct-custom-customer-details"
                                checked={ draft.customerDetails }
                                disabled={ readOnly }
                                onCheckedChange={ ( customerDetails ) =>
                                    update( { customerDetails } )
                                }
                                aria-labelledby="wcct-custom-customer-details-title"
                                aria-describedby="wcct-custom-customer-details-desc"
                            />
                        </div>
                        <Notice variant="info" role="note">
                            <p className="m-0">
                                { __(
                                    'Customer placeholders fill in only while this is on, only for a verified order on the thank-you page, and, with Respect cookie choices on, only after the shopper allows marketing cookies. Everywhere else they stay empty, so personal data never lands in a page by accident.',
                                    'woocommerce-conversion-tracking'
                                ) }
                            </p>
                        </Notice>
                    </section>
                </div>
                <HelpColumn />
            </div>

            { pending && (
                <div className="px-6 pb-4">
                    <Notice
                        variant="warning"
                        role="alert"
                        data-testid="wcct-custom-migration-pending"
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
                        data-testid="wcct-custom-save-error"
                    >
                        <NoticeTitle>
                            { __(
                                "Your changes weren't saved.",
                                'woocommerce-conversion-tracking'
                            ) }
                        </NoticeTitle>
                        <p className="m-0">{ saveError }</p>
                        { saveDetails.length > 0 && (
                            <ul className="m-0 list-disc pl-5">
                                { saveDetails.map( ( detail ) => (
                                    <li key={ detail } className="m-0">
                                        { detail }
                                    </li>
                                ) ) }
                            </ul>
                        ) }
                    </Notice>
                </div>
            ) }

            { ! readOnly && (
                <div className="flex items-center justify-between gap-4 border-t border-border px-6 py-4">
                    <span
                        className="text-[13px] text-muted-foreground"
                        role="status"
                        data-testid="wcct-custom-note"
                    >
                        { footNote }
                    </span>
                    <Button
                        onClick={ save }
                        disabled={ ! dirty || saving }
                        data-testid="wcct-custom-save"
                    >
                        { saveLabel }
                    </Button>
                </div>
            ) }
        </section>
    );
};

export default CustomScript;
