/**
 * External dependencies
 */
import { Button, Card, Notice, NoticeTitle } from '@wedevs/plugin-ui';
import { Page, PlatformLogo } from '@wcct/components';

/**
 * WordPress dependencies
 */
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { errorMessage } from '../../data/api';
import {
    fetchCatalog,
    saveCatalog,
    type CatalogData,
    type CatalogExclusions,
    type CatalogResponse,
    type CatalogUpdate,
    type ProductIdMode,
} from '../../data/catalog';
import { CardSkeleton } from '../../shell/PageSkeleton';
import { useUnsavedChanges } from '../../shell/useUnsavedChanges';
import { pageInfo } from '../titles';
import { Exclusions } from './Exclusions';
import {
    FeedLogin,
    validateLogin,
    type LoginDraft,
    type LoginErrors,
} from './FeedLogin';
import { FeedStatus } from './FeedStatus';
import { HelpColumn } from './HelpColumn';
import { ProductIds } from './ProductIds';
import { UpgradeCard } from './UpgradeCard';

// While a build runs: every 5 s for the first polls, then every 30 s, up to a cap.
const POLL_MS = 5000;
const SLOW_POLL_MS = 30000;
const FAST_POLLS = 6;
const MAX_POLLS = 40;

// Polls with the build's row count unchanged before the page calls the build stuck.
const STALL_POLLS = 6;

type Draft = {
    exclusions: CatalogExclusions;
    productIdMode: ProductIdMode;
    login: LoginDraft;
};

type ApiError = {
    code?: string;
    message?: string;
    data?: { errors?: Record< string, unknown > };
};

/**
 * The per-field messages of a 400 (data.errors), for the save notice.
 *
 * @param {unknown} reason What apiFetch rejected with.
 */
const fieldErrors = ( reason: unknown ): string[] => {
    const errors = ( ( reason ?? {} ) as ApiError ).data?.errors;

    return errors && typeof errors === 'object'
        ? Object.values( errors ).filter(
              ( message ): message is string => typeof message === 'string'
          )
        : [];
};

const draftFrom = ( data: CatalogData ): Draft => ( {
    exclusions: {
        types: [ ...data.exclusions.types ],
        categories: [ ...data.exclusions.categories ],
    },
    productIdMode: data.product_id_mode,
    login: {
        enabled: data.login.enabled,
        username: data.login.username,
        password: '',
    },
} );

const sameList = ( a: Array< string | number >, b: Array< string | number > ) =>
    a.length === b.length && a.every( ( item ) => b.includes( item ) );

const exclusionsChanged = ( draft: Draft, saved: Draft ) =>
    ! sameList( draft.exclusions.types, saved.exclusions.types ) ||
    ! sameList( draft.exclusions.categories, saved.exclusions.categories );

const loginChanged = ( draft: Draft, saved: Draft ) =>
    draft.login.enabled !== saved.login.enabled ||
    draft.login.username.trim() !== saved.login.username ||
    draft.login.password !== '';

/**
 * The Meta destination tab; built for more destinations later.
 */
const DestinationTabs = () => (
    <nav
        aria-label={ __(
            'Catalog destinations',
            'woocommerce-conversion-tracking'
        ) }
        className="border-b border-border px-6"
    >
        <a
            href="#/catalog"
            aria-current="page"
            className="-mb-px inline-flex items-center gap-2 border-b-2 border-primary py-3 text-sm font-medium text-primary no-underline"
        >
            <PlatformLogo
                src={ `${ window.wcctAdmin?.logosUrl ?? '' }meta.svg` }
                className="size-5 rounded border-0 bg-transparent [&>img]:size-4"
            />
            { __( 'Meta', 'woocommerce-conversion-tracking' ) }
        </a>
    </nav>
);

/**
 * The Pro catalog: feed status, product IDs, exclusions and the optional login.
 *
 * @param {Object}      props         Component props.
 * @param {CatalogData} props.initial First response.
 */
const CatalogForm = ( { initial }: { initial: CatalogData } ) => {
    const [ data, setData ] = useState( initial );
    const [ saved, setSaved ] = useState( () => draftFrom( initial ) );
    const [ draft, setDraft ] = useState( () => draftFrom( initial ) );
    const [ loginErrors, setLoginErrors ] = useState< LoginErrors >( {} );
    const [ saveErrors, setSaveErrors ] = useState< string[] >( [] );
    const [ saving, setSaving ] = useState( false );
    const [ rebuilding, setRebuilding ] = useState( false );
    const [ rebuildError, setRebuildError ] = useState( '' );
    const [ stalled, setStalled ] = useState( false );
    const [ pollTick, setPollTick ] = useState( 0 );
    const [ note, setNote ] = useState( '' );
    const [ announce, setAnnounce ] = useState( '' );
    const mounted = useRef( true );
    const polls = useRef( { count: 0, unchanged: 0, rows: -1 } );

    const canEditLogin = data.can_edit_login;
    const loginDirty = canEditLogin && loginChanged( draft, saved );
    const dirty =
        exclusionsChanged( draft, saved ) ||
        draft.productIdMode !== saved.productIdMode ||
        loginDirty;

    useUnsavedChanges( dirty );

    useEffect( () => {
        mounted.current = true;

        return () => {
            mounted.current = false;
        };
    }, [] );

    // Re-read the status while a build runs, slower after a while; the form draft is left alone.
    useEffect( () => {
        if ( ! data.building ) {
            polls.current = { count: 0, unchanged: 0, rows: -1 };
            setStalled( false );

            return;
        }

        if ( polls.current.count >= MAX_POLLS ) {
            setStalled( true );

            return;
        }

        const delay = polls.current.count < FAST_POLLS ? POLL_MS : SLOW_POLL_MS;
        const timer = window.setTimeout( () => {
            polls.current.count++;

            fetchCatalog()
                .then( ( next ) => {
                    if ( ! mounted.current || next.locked ) {
                        return;
                    }

                    const state = polls.current;

                    state.unchanged =
                        next.building && next.build_rows === state.rows
                            ? state.unchanged + 1
                            : 0;
                    state.rows = next.build_rows;

                    // A build that never moves usually means scheduled tasks don't run.
                    if ( state.unchanged >= STALL_POLLS ) {
                        setStalled( true );
                    }

                    setData( next );

                    if ( ! next.building ) {
                        setAnnounce(
                            next.error
                                ? __(
                                      'The feed build failed.',
                                      'woocommerce-conversion-tracking'
                                  )
                                : __(
                                      'Your feed is ready.',
                                      'woocommerce-conversion-tracking'
                                  )
                        );
                    }
                } )
                // A failed poll keeps the last status and tries again.
                .catch(
                    () => mounted.current && setPollTick( ( tick ) => tick + 1 )
                );
        }, delay );

        return () => window.clearTimeout( timer );
    }, [ data, pollTick ] );

    const update = ( next: Partial< Draft > ) => {
        setDraft( ( prev ) => ( { ...prev, ...next } ) );
        setNote( '' );
    };

    const rebuild = useCallback( () => {
        setRebuilding( true );
        setRebuildError( '' );
        polls.current = { count: 0, unchanged: 0, rows: -1 };
        setStalled( false );

        saveCatalog( { rebuild: true } )
            .then( ( next ) => {
                if ( ! mounted.current || next.locked ) {
                    return;
                }

                setData( next );

                if ( next.rebuild_queued === false ) {
                    setRebuildError(
                        __(
                            "The build couldn't be queued. Check that scheduled actions run under WooCommerce > Status > Scheduled Actions.",
                            'woocommerce-conversion-tracking'
                        )
                    );
                } else {
                    setAnnounce(
                        __(
                            'The feed is being rebuilt.',
                            'woocommerce-conversion-tracking'
                        )
                    );
                }
            } )
            .catch( ( reason: unknown ) => {
                if ( mounted.current ) {
                    setRebuildError( errorMessage( reason ) );
                }
            } )
            .finally( () => mounted.current && setRebuilding( false ) );
    }, [] );

    const save = () => {
        const errors = loginDirty
            ? validateLogin( draft.login, data.login )
            : {};

        setSaveErrors( [] );
        setLoginErrors( errors );

        if ( errors.username || errors.password ) {
            document
                .getElementById(
                    errors.username ? 'wcct-feed-user' : 'wcct-feed-pass'
                )
                ?.focus();

            return;
        }

        const body: CatalogUpdate = {};

        if ( exclusionsChanged( draft, saved ) ) {
            // Only what the store still has, so a type or category removed meanwhile can't fail the save.
            body.exclusions = {
                types: draft.exclusions.types.filter(
                    ( slug ) => slug in data.options.types
                ),
                categories: draft.exclusions.categories.filter( ( id ) =>
                    data.options.categories.some( ( term ) => term.id === id )
                ),
            };
        }

        if ( draft.productIdMode !== saved.productIdMode ) {
            body.product_id_mode = draft.productIdMode;
        }

        if ( loginDirty ) {
            body.login = {
                enabled: draft.login.enabled,
                username: draft.login.username.trim(),
                ...( draft.login.password
                    ? { password: draft.login.password }
                    : {} ),
            };
        }

        setSaving( true );

        saveCatalog( body )
            .then( ( next ) => {
                if ( ! mounted.current || next.locked ) {
                    return;
                }

                const fresh = draftFrom( next );

                setData( next );
                setSaved( fresh );
                setDraft( fresh );
                // Only feed changes restart the build, and only a queued build is promised.
                setNote(
                    ( body.exclusions || body.product_id_mode ) && next.building
                        ? __(
                              'Saved. The feed is being rebuilt with your changes.',
                              'woocommerce-conversion-tracking'
                          )
                        : __( 'Saved.', 'woocommerce-conversion-tracking' )
                );
            } )
            .catch( ( reason: unknown ) => {
                if ( ! mounted.current ) {
                    return;
                }

                const { code } = ( reason ?? {} ) as ApiError;
                const message = errorMessage( reason );

                if ( code === 'wcct_feed_login_username' ) {
                    setLoginErrors( { username: message } );
                    document.getElementById( 'wcct-feed-user' )?.focus();
                } else if ( code === 'wcct_feed_login_password' ) {
                    setLoginErrors( { password: message } );
                    document.getElementById( 'wcct-feed-pass' )?.focus();
                } else if ( code === 'wcct_feed_login_incomplete' ) {
                    setLoginErrors( { general: message } );
                } else {
                    setSaveErrors( [ message, ...fieldErrors( reason ) ] );
                }
            } )
            .finally( () => mounted.current && setSaving( false ) );
    };

    const footNote = () => {
        if ( saving ) {
            return __( 'Saving…', 'woocommerce-conversion-tracking' );
        }

        if ( dirty ) {
            return __(
                'You have unsaved changes.',
                'woocommerce-conversion-tracking'
            );
        }

        return note;
    };

    let saveLabel: string = __( 'Saved', 'woocommerce-conversion-tracking' );

    if ( saving ) {
        saveLabel = __( 'Saving…', 'woocommerce-conversion-tracking' );
    } else if ( dirty ) {
        saveLabel = __( 'Save changes', 'woocommerce-conversion-tracking' );
    }

    const [ saveError, ...saveDetails ] = saveErrors;

    return (
        <>
            <div className="grid grid-cols-[minmax(0,1fr)_320px] max-[1100px]:grid-cols-1">
                <div className="flex min-w-0 flex-col gap-8 p-6">
                    <FeedStatus
                        data={ data }
                        stalled={ stalled }
                        rebuilding={ rebuilding }
                        rebuildError={ rebuildError }
                        onRebuild={ rebuild }
                    >
                        <FeedLogin
                            saved={ data.login }
                            canEdit={ canEditLogin }
                            draft={ draft.login }
                            errors={ loginErrors }
                            onChange={ ( next ) => {
                                update( {
                                    login: { ...draft.login, ...next },
                                } );
                                setLoginErrors( {} );
                            } }
                        />
                    </FeedStatus>
                    <ProductIds
                        feedMode={ draft.productIdMode }
                        pixelMode={ data.pixel_product_id_mode }
                        onChange={ ( productIdMode ) =>
                            update( { productIdMode } )
                        }
                    />
                    <Exclusions
                        value={ draft.exclusions }
                        types={ data.options.types }
                        categories={ data.options.categories }
                        onChange={ ( exclusions ) => update( { exclusions } ) }
                    />
                </div>
                <HelpColumn />
            </div>
            { saveError && (
                <div className="px-6 pb-4">
                    <Notice
                        variant="destructive"
                        role="alert"
                        data-testid="wcct-catalog-save-error"
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
            <div className="flex items-center justify-between gap-4 border-t border-border px-6 py-4">
                <span
                    className="text-[13px] text-muted-foreground"
                    role="status"
                    data-testid="wcct-catalog-note"
                >
                    { footNote() }
                </span>
                <Button
                    onClick={ save }
                    disabled={ ! dirty || saving }
                    data-testid="wcct-catalog-save"
                >
                    { saveLabel }
                </Button>
            </div>
            <span className="sr-only" role="status">
                { announce }
            </span>
        </>
    );
};

/**
 * The Catalog page: the Pro feed, or the upgrade card on Free.
 */
const Catalog = () => {
    const { title, description } = pageInfo( { name: 'catalog', query: '' } );
    const [ response, setResponse ] = useState< CatalogResponse | null >(
        null
    );
    const [ error, setError ] = useState( '' );
    const [ loading, setLoading ] = useState( true );
    const mounted = useRef( true );

    const load = useCallback( () => {
        setLoading( true );
        setError( '' );

        fetchCatalog()
            .then( ( next ) => mounted.current && setResponse( next ) )
            .catch(
                ( reason: unknown ) =>
                    mounted.current && setError( errorMessage( reason ) )
            )
            .finally( () => mounted.current && setLoading( false ) );
    }, [] );

    useEffect( () => {
        mounted.current = true;
        load();

        return () => {
            mounted.current = false;
        };
    }, [ load ] );

    let body;

    if ( loading ) {
        body = (
            <div className="p-6">
                <CardSkeleton lines={ 6 } />
            </div>
        );
    } else if ( error || ! response ) {
        body = (
            <div className="p-6">
                <Notice
                    variant="destructive"
                    role="alert"
                    data-testid="wcct-load-error"
                >
                    <NoticeTitle>
                        { __(
                            'The catalog settings could not be loaded.',
                            'woocommerce-conversion-tracking'
                        ) }
                    </NoticeTitle>
                    <p className="m-0">{ error }</p>
                    <div>
                        <Button variant="outline" size="sm" onClick={ load }>
                            { __(
                                'Try again',
                                'woocommerce-conversion-tracking'
                            ) }
                        </Button>
                    </div>
                </Notice>
            </div>
        );
    } else if ( response.locked ) {
        body = <UpgradeCard />;
    } else {
        body = <CatalogForm initial={ response } />;
    }

    return (
        <Page title={ title } description={ description }>
            <Card className="gap-0 py-0" data-testid="wcct-catalog">
                <DestinationTabs />
                { body }
            </Card>
        </Page>
    );
};

export default Catalog;
