/**
 * External dependencies
 */
import type { ReactNode } from 'react';

/**
 * WordPress dependencies
 */
import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useMemo,
    useRef,
    useState,
} from '@wordpress/element';

/**
 * Internal dependencies
 */
import { errorMessage, fetchAppData } from './api';
import type { AppData, SettingsValues } from './types';

export type AppDataState = {
    data: AppData | null;
    error: string;
    // True until the first response; pages show a full-page skeleton.
    loading: boolean;
    // True while cached data is re-fetched; cards show their own skeletons.
    refreshing: boolean;
    refresh: () => void;
    // Swap in the settings a save returned, so every page sees them without a refetch.
    replaceSettings: ( settings: SettingsValues ) => void;
};

const AppDataContext = createContext< AppDataState | null >( null );

/**
 * Loads the settings and schema once and shares them with every page.
 *
 * @param {Object}    props          Component props.
 * @param {ReactNode} props.children Children.
 */
export const AppDataProvider = ( { children }: { children: ReactNode } ) => {
    const [ data, setData ] = useState< AppData | null >( null );
    const [ error, setError ] = useState( '' );
    const [ busy, setBusy ] = useState( true );
    const inFlight = useRef( false );
    const mounted = useRef( true );

    const load = useCallback( () => {
        if ( inFlight.current ) {
            return;
        }

        inFlight.current = true;
        setBusy( true );

        fetchAppData()
            .then( ( next ) => {
                if ( mounted.current ) {
                    setData( next );
                    setError( '' );
                }
            } )
            .catch( ( reason: unknown ) => {
                if ( mounted.current ) {
                    setError( errorMessage( reason ) );
                }
            } )
            .finally( () => {
                inFlight.current = false;

                if ( mounted.current ) {
                    setBusy( false );
                }
            } );
    }, [] );

    const replaceSettings = useCallback( ( settings: SettingsValues ) => {
        setData( ( prev ) =>
            prev
                ? {
                      ...prev,
                      settings: {
                          ...settings,
                          platforms: settings?.platforms ?? {},
                      },
                  }
                : prev
        );
    }, [] );

    useEffect( () => {
        mounted.current = true;
        load();

        return () => {
            mounted.current = false;
        };
    }, [ load ] );

    const value = useMemo(
        () => ( {
            data,
            error,
            loading: busy && ! data,
            refreshing: busy && !! data,
            refresh: load,
            replaceSettings,
        } ),
        [ data, error, busy, load, replaceSettings ]
    );

    return (
        <AppDataContext.Provider value={ value }>
            { children }
        </AppDataContext.Provider>
    );
};

/**
 * The shared settings and schema.
 */
export const useAppData = (): AppDataState => {
    const state = useContext( AppDataContext );

    if ( ! state ) {
        throw new Error( 'useAppData() needs an AppDataProvider.' );
    }

    return state;
};
