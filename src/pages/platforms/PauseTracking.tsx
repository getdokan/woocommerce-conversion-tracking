/**
 * External dependencies
 */
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    Button,
    Notice,
    NoticeTitle,
    toast,
} from '@wedevs/plugin-ui';
import { Pause } from 'lucide-react';

/**
 * WordPress dependencies
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { useAppData } from '../../data/AppData';
import { errorMessage } from '../../data/api';
import { pauseDialogText, pauseTarget, savePaused } from '../../data/pause';
import type { SettingsValues } from '../../data/types';

export type PauseTrackingProps = {
    // Settings platform ID.
    id: string;
    // Display name, e.g. Meta.
    name: string;
    paused: boolean;
    // Receives the settings the change returned; replaces the shared settings by default.
    onChange?: ( settings: SettingsValues ) => void;
};

/**
 * "Pause tracking" with its confirmation, or the paused note with "Resume tracking", for a platform page footer.
 *
 * @param {PauseTrackingProps} props Component props.
 */
export const PauseTracking = ( {
    id,
    name,
    paused,
    onChange,
}: PauseTrackingProps ) => {
    const { replaceSettings } = useAppData();
    const [ asking, setAsking ] = useState( false );
    const [ saving, setSaving ] = useState( false );
    const [ error, setError ] = useState( '' );
    const [ note, setNote ] = useState( '' );
    const pauseButton = useRef< HTMLButtonElement >( null );
    const resumeButton = useRef< HTMLButtonElement >( null );
    // Move focus to the control that replaced the one just used.
    const refocus = useRef( false );
    const target = pauseTarget( id, name );

    useEffect( () => {
        if ( refocus.current ) {
            refocus.current = false;
            ( paused ? resumeButton : pauseButton ).current?.focus();
        }
    }, [ paused ] );

    const change = ( next: boolean ) => {
        setSaving( true );
        setError( '' );
        setNote( '' );

        savePaused( id, next )
            .then( ( settings ) => {
                refocus.current = true;
                ( onChange ?? replaceSettings )( settings );
                setAsking( false );

                const message = next
                    ? sprintf(
                          /* translators: %s: platform name, e.g. Meta */
                          __(
                              'Tracking paused on %s. Your settings are kept.',
                              'woocommerce-conversion-tracking'
                          ),
                          name
                      )
                    : sprintf(
                          /* translators: %s: platform name, e.g. Meta */
                          __(
                              'Tracking resumed on %s.',
                              'woocommerce-conversion-tracking'
                          ),
                          name
                      );

                setNote( message );
                toast( message );
            } )
            .catch( ( reason: unknown ) => {
                setAsking( false );
                setError( errorMessage( reason ) );
            } )
            .finally( () => setSaving( false ) );
    };

    return (
        <div
            className="flex min-w-0 flex-1 flex-wrap items-center gap-3"
            data-testid={ `wcct-pause-${ id }` }
        >
            { error && (
                <Notice variant="destructive" role="alert" className="w-full">
                    <NoticeTitle>
                        { paused
                            ? __(
                                  'Tracking was not resumed.',
                                  'woocommerce-conversion-tracking'
                              )
                            : __(
                                  'Tracking was not paused.',
                                  'woocommerce-conversion-tracking'
                              ) }
                    </NoticeTitle>
                    <p className="m-0">{ error }</p>
                </Notice>
            ) }
            { paused ? (
                <>
                    <span className="inline-flex items-center gap-1.5 text-[13px] text-muted-foreground">
                        <Pause className="size-3.5" aria-hidden="true" />
                        { sprintf(
                            /* translators: %s: platform name, e.g. Meta */
                            __(
                                'Tracking paused. Nothing is sent to %s until you resume.',
                                'woocommerce-conversion-tracking'
                            ),
                            target
                        ) }
                    </span>
                    <Button
                        ref={ resumeButton }
                        className="ml-auto"
                        disabled={ saving }
                        onClick={ () => change( false ) }
                    >
                        { saving
                            ? __(
                                  'Resuming…',
                                  'woocommerce-conversion-tracking'
                              )
                            : __(
                                  'Resume tracking',
                                  'woocommerce-conversion-tracking'
                              ) }
                    </Button>
                </>
            ) : (
                <Button
                    ref={ pauseButton }
                    variant="link"
                    className="h-auto px-0 text-destructive hover:text-destructive"
                    onClick={ () => setAsking( true ) }
                >
                    { __(
                        'Pause tracking',
                        'woocommerce-conversion-tracking'
                    ) }
                </Button>
            ) }
            <span
                className="sr-only"
                role="status"
                data-testid={ `wcct-pause-${ id }-status` }
            >
                { note }
            </span>
            <AlertDialog
                open={ asking }
                onOpenChange={ ( open ) => ! saving && setAsking( open ) }
            >
                <AlertDialogContent
                    data-testid="wcct-pause-dialog"
                    finalFocus={ () =>
                        resumeButton.current ?? pauseButton.current
                    }
                >
                    <AlertDialogHeader>
                        <AlertDialogTitle className="m-0 p-0 text-lg font-semibold">
                            { sprintf(
                                /* translators: %s: platform name, e.g. Meta */
                                __(
                                    'Pause tracking on %s?',
                                    'woocommerce-conversion-tracking'
                                ),
                                name
                            ) }
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            { pauseDialogText( id, name ) }
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel
                            variant="secondary"
                            disabled={ saving }
                        >
                            { __(
                                'Keep tracking',
                                'woocommerce-conversion-tracking'
                            ) }
                        </AlertDialogCancel>
                        <AlertDialogAction
                            variant="destructive"
                            disabled={ saving }
                            onClick={ () => change( true ) }
                        >
                            { saving
                                ? __(
                                      'Pausing…',
                                      'woocommerce-conversion-tracking'
                                  )
                                : __(
                                      'Pause tracking',
                                      'woocommerce-conversion-tracking'
                                  ) }
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </div>
    );
};
