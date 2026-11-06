/**
 * External dependencies
 */
import {
    Button,
    Notice,
    NoticeTitle,
    RadioCard,
    RadioGroup,
} from '@wedevs/plugin-ui';
import { Check, CircleCheck } from 'lucide-react';

/**
 * WordPress dependencies
 */
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { useAppData } from '../../data/AppData';
import { errorMessage } from '../../data/api';
import {
    POPULAR,
    STEP_COUNT,
    checkPanel,
    onboardingFields,
    panelChanged,
    panelValues,
    queryStep,
    recommendedEvents,
    saveProgress,
    saveSettings,
} from '../../data/onboarding';
import { isPro, isProOutdated } from '../../data/plan';
import { platformCards, type PlatformCardInfo } from '../../data/platforms';
import { replaceHash } from '../../router';
import { useUnsavedChanges } from '../../shell/useUnsavedChanges';
import type {
    AppData,
    EuAnswer,
    PlatformValues,
    SchemaPlatform,
} from '../../data/types';
import { ConnectPanel } from './ConnectPanel';
import { PickTiles } from './PickTiles';
import { StepLayout } from './StepLayout';
import { VerifyStep } from './VerifyStep';

type Values = Record< string, Record< string, string > >;

// The tile subtitles from the prototype.
const tileMeta = (): Record< string, string > => ( {
    facebook: __(
        'Facebook and Instagram ads',
        'woocommerce-conversion-tracking'
    ),
    google: __( 'Analytics, Ads, or both', 'woocommerce-conversion-tracking' ),
    twitter: __( 'formerly Twitter', 'woocommerce-conversion-tracking' ),
    bing_ads: __(
        'Bing and Microsoft Start ads',
        'woocommerce-conversion-tracking'
    ),
    custom: __( 'Paste any other tag', 'woocommerce-conversion-tracking' ),
} );

/**
 * Note on a connect panel: why a platform waits for later, or what to add later.
 *
 * @param {PlatformCardInfo} platform    Platform.
 * @param {boolean}          connectable Has wizard fields.
 */
const panelNote = (
    platform: PlatformCardInfo,
    connectable: boolean
): string => {
    // A Pro older than 3.0 is paused, so its platforms stay locked until it's updated.
    if ( platform.locked && isProOutdated() ) {
        return __(
            'Microsoft Advertising needs Pro 3.0. Update Pro, then connect it on the Platforms page.',
            'woocommerce-conversion-tracking'
        );
    }

    if ( platform.locked ) {
        return __(
            "Microsoft Advertising is part of Pro, so we'll leave it out of setup. It stays in your Platforms list.",
            'woocommerce-conversion-tracking'
        );
    }

    if ( platform.id === 'twitter' && isPro() ) {
        return __(
            'Add to cart and Start checkout each need their own event ID too. Add them later in Platforms, X.',
            'woocommerce-conversion-tracking'
        );
    }

    if ( connectable ) {
        return '';
    }

    return platform.id === 'custom'
        ? __(
              "Add your script on the Platforms page after setup. You'll pick where it runs there.",
              'woocommerce-conversion-tracking'
          )
        : __(
              'Set it up on its Platforms page after setup.',
              'woocommerce-conversion-tracking'
          );
};

/**
 * The typed IDs to start from: what is saved for each platform's wizard fields.
 *
 * @param {AppData} data Settings and schema.
 */
const savedValues = ( data: AppData ): Values => {
    const values: Values = {};

    data.schema.platforms.forEach( ( platform ) => {
        const saved: PlatformValues =
            data.settings.platforms[ platform.id ] ?? {};

        values[ platform.id ] = {};
        onboardingFields( platform ).forEach( ( field ) => {
            const value = saved[ field.id ];

            values[ platform.id ][ field.id ] =
                typeof value === 'string' ? value : '';
        } );
    } );

    return values;
};

/**
 * Move focus to the step heading, so keyboard and screen reader users follow the step.
 */
const focusStepHeading = () =>
    document
        .querySelector< HTMLElement >(
            '#wcct-app-root [data-wcct-step-heading]'
        )
        ?.focus();

/**
 * The setup wizard on a store that hasn't finished it; progress is kept so "Continue setup" resumes it.
 *
 * @param {Object}  props       Component props.
 * @param {AppData} props.data  Settings, schema and progress.
 * @param {string}  props.query Route query; ?step=N opens a step reached before.
 */
const Wizard = ( { data, query }: { data: AppData; query: string } ) => {
    const registered = useMemo(
        () =>
            new Map< string, SchemaPlatform >(
                data.schema.platforms.map( ( platform ) => [
                    platform.id,
                    platform,
                ] )
            ),
        [ data.schema.platforms ]
    );
    const platforms = useMemo( () => {
        const meta = tileMeta();

        return platformCards( data ).map( ( card ) => ( {
            ...card,
            meta: meta[ card.id ] ?? card.meta,
        } ) );
    }, [ data ] );

    const [ furthest, setFurthest ] = useState( () =>
        Math.max( 1, data.onboarding.step )
    );
    const [ picked, setPicked ] = useState< string[] >( () => {
        if ( data.onboarding.platforms.length ) {
            return data.onboarding.platforms;
        }

        return data.onboarding.status === 'new'
            ? POPULAR.filter( ( id ) => registered.has( id ) )
            : [];
    } );
    const [ values, setValues ] = useState< Values >( () =>
        savedValues( data )
    );
    const [ eu, setEu ] = useState< EuAnswer >( data.onboarding.eu );
    // What the settings route holds, to tell typed but unsaved IDs and answers apart.
    const [ committed, setCommitted ] = useState( () => ( {
        values: savedValues( data ),
        eu: data.onboarding.eu,
    } ) );
    // Skip and finish leave on purpose; nothing to ask.
    const [ leaving, setLeaving ] = useState( false );
    const [ busy, setBusy ] = useState( false );
    const [ failure, setFailure ] = useState< {
        message: string;
        retry: () => void;
    } | null >( null );

    const current = Math.min( queryStep( query ) || furthest, furthest );
    const shown = useRef( current );

    // Only registered platforms are saved; a locked Pro tile stays in the browser.
    const savedPicks = picked.filter( ( id ) => registered.has( id ) );

    const panels = platforms
        .filter( ( platform ) => picked.includes( platform.id ) )
        .map( ( platform ) => {
            const schema = registered.get( platform.id );
            const fields = schema ? onboardingFields( schema ) : [];
            const typed = values[ platform.id ] ?? {};

            return {
                platform,
                fields,
                typed,
                check: checkPanel( fields, typed ),
                events: schema ? recommendedEvents( schema ) : [],
            };
        } );
    const connectable = panels.filter( ( panel ) => panel.check.connectable );
    const ready = connectable.filter( ( panel ) => panel.check.ready );
    const anyInvalid = panels.some( ( panel ) => panel.check.invalid );

    const dirty =
        ! leaving &&
        ( eu !== committed.eu ||
            JSON.stringify( values ) !== JSON.stringify( committed.values ) );

    // Moving between steps keeps the draft; leaving the wizard asks first.
    useUnsavedChanges( dirty, ( hash ) => hash.startsWith( '#/onboarding' ) );

    // Opening the wizard the first time marks it in progress.
    useEffect( () => {
        if ( data.onboarding.status === 'new' ) {
            saveProgress( { step: current, platforms: savedPicks } ).catch(
                () => {}
            );
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps -- once, on open.
    }, [] );

    // The URL always names the step shown, so the top bar and a reload agree with it.
    useEffect( () => {
        if ( queryStep( query ) !== current ) {
            replaceHash( `#/onboarding?step=${ current }` );
        }
    }, [ query, current ] );

    useEffect( () => {
        if ( shown.current !== current ) {
            shown.current = current;
            window.scrollTo( 0, 0 );
            focusStepHeading();
        }
    }, [ current ] );

    const show = ( step: number ) => {
        window.location.hash = `#/onboarding?step=${ step }`;
    };

    const go = ( step: number ) => {
        setFailure( null );
        setFurthest( ( previous ) => Math.max( previous, step ) );
        // Progress is best effort; the next step opens either way.
        saveProgress( { step, platforms: savedPicks, eu } ).catch( () => {} );
        show( step );
    };

    // Saves through the settings route, then moves on; a 409 while the update to 3.0 runs shows a retry.
    const commit = (
        settings: Record< string, unknown >,
        then: () => void
    ) => {
        const attempt = () => {
            setBusy( true );
            setFailure( null );

            saveSettings( settings )
                .then( () => {
                    setCommitted( { values, eu } );
                    then();
                } )
                .catch( ( reason: unknown ) =>
                    setFailure( {
                        message: errorMessage( reason ),
                        retry: attempt,
                    } )
                )
                .finally( () => setBusy( false ) );
        };

        attempt();
    };

    // Every picked panel whose IDs changed and are not invalid; an emptied one clears what was saved.
    const changes = (): Record< string, PlatformValues > => {
        const saved: Record< string, PlatformValues > = {};

        connectable.forEach( ( panel ) => {
            const id = panel.platform.id;

            if (
                ! panel.check.invalid &&
                panelChanged(
                    panel.fields,
                    panel.typed,
                    committed.values[ id ] ?? {}
                )
            ) {
                saved[ id ] = panelValues( id, panel.fields, panel.typed );
            }
        } );

        return saved;
    };

    // Picks are progress, saved as they change, so leaving never loses them.
    const toggle = ( id: string ) => {
        const next = platforms
            .map( ( platform ) => platform.id )
            .filter( ( item ) =>
                item === id ? ! picked.includes( id ) : picked.includes( item )
            );

        setPicked( next );
        saveProgress( {
            platforms: next.filter( ( item ) => registered.has( item ) ),
        } ).catch( () => {} );
    };

    const skip = () => {
        setLeaving( true );
        saveProgress( {
            action: 'skip',
            step: current,
            platforms: savedPicks,
            eu,
        } )
            .catch( () => {} )
            .finally( () => {
                window.location.hash = '#/dashboard';
            } );
    };

    const finish = () =>
        commit(
            {
                platforms: changes(),
                ...( eu ? { consent_mode: eu === 'yes' } : {} ),
            },
            () => {
                setLeaving( true );
                saveProgress( { action: 'finish', platforms: savedPicks, eu } )
                    .catch( () => {} )
                    .finally( () => {
                        window.location.hash = '#/dashboard';
                    } );
            }
        );

    const back = () => show( Math.max( 1, current - 1 ) );

    const error = failure && (
        <Notice
            variant="destructive"
            role="alert"
            data-testid="wcct-onboarding-error"
        >
            <NoticeTitle>
                { __(
                    'Your settings were not saved.',
                    'woocommerce-conversion-tracking'
                ) }
            </NoticeTitle>
            <p className="m-0">{ failure.message }</p>
            <div>
                <Button
                    variant="outline"
                    size="sm"
                    onClick={ failure.retry }
                    disabled={ busy }
                >
                    { __( 'Try again', 'woocommerce-conversion-tracking' ) }
                </Button>
            </div>
        </Notice>
    );

    const continueButton = ( onClick: () => void, disabled = false ) => (
        <Button onClick={ onClick } disabled={ disabled || busy }>
            { __( 'Continue', 'woocommerce-conversion-tracking' ) }
        </Button>
    );

    switch ( current ) {
        case 2:
            return (
                <StepLayout
                    step={ 2 }
                    title={ __(
                        'Where do you run ads?',
                        'woocommerce-conversion-tracking'
                    ) }
                    lead={ __(
                        'Pick the platforms you use today. You can add more later. Nothing is sent until you connect one.',
                        'woocommerce-conversion-tracking'
                    ) }
                    onBack={ back }
                    note={
                        picked.length
                            ? sprintf(
                                  /* translators: %d: number of picked platforms */
                                  _n(
                                      '%d selected.',
                                      '%d selected.',
                                      picked.length,
                                      'woocommerce-conversion-tracking'
                                  ),
                                  picked.length
                              )
                            : __(
                                  'Nothing selected. Pick at least one, or skip for now.',
                                  'woocommerce-conversion-tracking'
                              )
                    }
                    onSkip={ skip }
                    action={ continueButton( () => go( 3 ), ! picked.length ) }
                >
                    <PickTiles
                        platforms={ platforms }
                        picked={ picked }
                        onToggle={ toggle }
                    />
                </StepLayout>
            );

        case 3: {
            let note: string = __(
                'Add a valid ID for at least one platform.',
                'woocommerce-conversion-tracking'
            );

            if ( anyInvalid ) {
                note = __(
                    'Fix the ID marked in red, or clear it.',
                    'woocommerce-conversion-tracking'
                );
            } else if ( ready.length ) {
                note = sprintf(
                    /* translators: 1: platforms with valid IDs, 2: platforms that can be connected here */
                    __(
                        '%1$d of %2$d ready to connect.',
                        'woocommerce-conversion-tracking'
                    ),
                    ready.length,
                    connectable.length
                );
            } else if ( ! connectable.length ) {
                note = '';
            }

            return (
                <StepLayout
                    step={ 3 }
                    title={ __(
                        'Connect your platforms',
                        'woocommerce-conversion-tracking'
                    ) }
                    lead={ __(
                        'Paste each ID. We check the format as you type. The recommended events are on, so most stores need nothing else.',
                        'woocommerce-conversion-tracking'
                    ) }
                    onBack={ back }
                    note={ note }
                    onSkip={ skip }
                    action={ continueButton(
                        () => {
                            const changed = changes();

                            if ( Object.keys( changed ).length ) {
                                commit( { platforms: changed }, () => go( 4 ) );
                            } else {
                                go( 4 );
                            }
                        },
                        anyInvalid ||
                            ( ! ready.length && connectable.length > 0 )
                    ) }
                >
                    { error }
                    { panels.map( ( panel ) => (
                        <ConnectPanel
                            key={ panel.platform.id }
                            platform={ panel.platform }
                            fields={ panel.fields }
                            values={ panel.typed }
                            check={ panel.check }
                            events={ panel.events }
                            note={ panelNote(
                                panel.platform,
                                panel.check.connectable
                            ) }
                            onChange={ ( field, value ) =>
                                setValues( ( previous ) => ( {
                                    ...previous,
                                    [ panel.platform.id ]: {
                                        ...previous[ panel.platform.id ],
                                        [ field ]: value,
                                    },
                                } ) )
                            }
                        />
                    ) ) }
                </StepLayout>
            );
        }

        case 4:
            return (
                <StepLayout
                    step={ 4 }
                    title={ __(
                        'Do you sell to customers in Europe?',
                        'woocommerce-conversion-tracking'
                    ) }
                    lead={ __(
                        'EU law asks you to track visitors only after they agree in a cookie banner.',
                        'woocommerce-conversion-tracking'
                    ) }
                    onBack={ back }
                    onSkip={ skip }
                    action={ continueButton(
                        () =>
                            commit( { consent_mode: eu === 'yes' }, () =>
                                go( 5 )
                            ),
                        ! eu
                    ) }
                >
                    { error }
                    <RadioGroup
                        value={ eu }
                        onValueChange={ ( value ) =>
                            setEu( value as EuAnswer )
                        }
                        aria-label={ __(
                            'Do you sell to customers in Europe?',
                            'woocommerce-conversion-tracking'
                        ) }
                        className="max-w-140"
                    >
                        <RadioCard
                            id="wcct-onboarding-eu-yes"
                            value="yes"
                            label={ __(
                                'Yes',
                                'woocommerce-conversion-tracking'
                            ) }
                            description={ __(
                                'Turn on Respect cookie choices for every platform.',
                                'woocommerce-conversion-tracking'
                            ) }
                        />
                        <RadioCard
                            id="wcct-onboarding-eu-no"
                            value="no"
                            label={ __(
                                'No',
                                'woocommerce-conversion-tracking'
                            ) }
                            description={ __(
                                'Leave it off. You can turn it on later in each platform.',
                                'woocommerce-conversion-tracking'
                            ) }
                        />
                    </RadioGroup>
                    { eu === 'yes' && (
                        <Notice
                            variant="info"
                            className="max-w-140"
                            data-testid="wcct-onboarding-eu-note"
                        >
                            <p className="m-0">
                                <b>
                                    { __(
                                        'Respect cookie choices is on.',
                                        'woocommerce-conversion-tracking'
                                    ) }
                                </b>{ ' ' }
                                { __(
                                    'It works with a consent plugin that supports the WordPress Consent API, such as Complianz, CookieYes or Cookiebot. Without one, your tags wait for a choice that never comes and EU sales go untracked.',
                                    'woocommerce-conversion-tracking'
                                ) }
                            </p>
                        </Notice>
                    ) }
                    { eu === 'no' && (
                        <p
                            className="m-0 max-w-140 text-[13px] text-muted-foreground"
                            data-testid="wcct-onboarding-eu-note"
                        >
                            { __(
                                'Respect cookie choices stays off.',
                                'woocommerce-conversion-tracking'
                            ) }
                        </p>
                    ) }
                </StepLayout>
            );

        case 5:
            return (
                <VerifyStep
                    connected={ ready.map( ( panel ) => panel.platform ) }
                    onBack={ back }
                    onSkip={ skip }
                    onNext={ () => go( 6 ) }
                />
            );

        case 6: {
            const summary: string[] = ready.map( ( panel ) =>
                sprintf(
                    /* translators: 1: platform name, 2: number of events */
                    _n(
                        '%1$s: sending %2$d recommended event',
                        '%1$s: sending %2$d recommended events',
                        panel.events.length,
                        'woocommerce-conversion-tracking'
                    ),
                    panel.platform.name,
                    panel.events.length
                )
            );

            summary.push(
                eu === 'yes'
                    ? __(
                          'Respect cookie choices: on',
                          'woocommerce-conversion-tracking'
                      )
                    : __(
                          'Respect cookie choices: off',
                          'woocommerce-conversion-tracking'
                      ),
                __(
                    'Activity log: recording every order',
                    'woocommerce-conversion-tracking'
                )
            );

            return (
                <StepLayout
                    step={ 6 }
                    icon={
                        <span
                            className="inline-flex size-12 items-center justify-center rounded-full bg-tone-ok-bg text-tone-ok-fg"
                            aria-hidden="true"
                        >
                            <CircleCheck className="size-6" />
                        </span>
                    }
                    title={ __(
                        "You're set up",
                        'woocommerce-conversion-tracking'
                    ) }
                    lead={ __(
                        "Here's what's working now. Every order shows in the Activity log as it's sent.",
                        'woocommerce-conversion-tracking'
                    ) }
                    action={
                        <Button size="lg" onClick={ finish } disabled={ busy }>
                            { __(
                                'Go to dashboard',
                                'woocommerce-conversion-tracking'
                            ) }
                        </Button>
                    }
                >
                    { error }
                    <ul
                        className="m-0 flex list-none flex-col gap-2 p-0 text-sm"
                        data-testid="wcct-onboarding-summary"
                    >
                        { summary.map( ( line ) => (
                            <li
                                key={ line }
                                className="m-0 flex items-start gap-2"
                            >
                                <Check
                                    className="mt-0.5 size-4 shrink-0 text-tone-ok-fg"
                                    aria-hidden="true"
                                />
                                <span>{ line }</span>
                            </li>
                        ) ) }
                    </ul>
                </StepLayout>
            );
        }

        default:
            return (
                <StepLayout
                    step={ 1 }
                    title={ __(
                        'Show your ads which visits turn into sales',
                        'woocommerce-conversion-tracking'
                    ) }
                    lead={ __(
                        "Conversion Tracking sends your store's product views, carts and purchases to Meta, Google and X, so each platform knows which ads led to a sale. Setup takes about 3 minutes.",
                        'woocommerce-conversion-tracking'
                    ) }
                    onSkip={ skip }
                >
                    <ul className="m-0 flex list-none flex-col gap-2 p-0 text-sm">
                        { [
                            __(
                                'Each purchase counted once, failed orders skipped',
                                'woocommerce-conversion-tracking'
                            ),
                            __(
                                "Respects your visitors' cookie choices",
                                'woocommerce-conversion-tracking'
                            ),
                            __(
                                'An Activity log that shows what was sent for each order',
                                'woocommerce-conversion-tracking'
                            ),
                        ].map( ( line ) => (
                            <li
                                key={ line }
                                className="m-0 flex items-start gap-2"
                            >
                                <Check
                                    className="mt-0.5 size-4 shrink-0 text-tone-ok-fg"
                                    aria-hidden="true"
                                />
                                <span>{ line }</span>
                            </li>
                        ) ) }
                    </ul>
                    <div>
                        <Button size="lg" onClick={ () => go( 2 ) }>
                            { __( 'Start', 'woocommerce-conversion-tracking' ) }
                        </Button>
                    </div>
                </StepLayout>
            );
    }
};

/**
 * The setup wizard (#/onboarding). A finished wizard opens again from Settings > Setup (#120).
 *
 * @param {Object} props       Component props.
 * @param {string} props.query Route query.
 */
const Onboarding = ( { query }: { query: string } ) => {
    const { data } = useAppData();

    if ( ! data ) {
        return null;
    }

    if ( data.onboarding.status === 'done' ) {
        return (
            <StepLayout
                step={ STEP_COUNT }
                title={ __(
                    'Setup is finished',
                    'woocommerce-conversion-tracking'
                ) }
                lead={ __(
                    'Change your platforms on the Platforms page.',
                    'woocommerce-conversion-tracking'
                ) }
                action={
                    <Button
                        nativeButton={ false }
                        // eslint-disable-next-line jsx-a11y/anchor-has-content -- the button renders the label inside.
                        render={ <a href="#/dashboard" /> }
                    >
                        { __(
                            'Go to dashboard',
                            'woocommerce-conversion-tracking'
                        ) }
                    </Button>
                }
            />
        );
    }

    return <Wizard data={ data } query={ query } />;
};

export default Onboarding;
