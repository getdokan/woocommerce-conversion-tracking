/**
 * External dependencies
 */
import { Button, Notice, NoticeTitle, Switch, toast } from '@wedevs/plugin-ui';
import { Bell } from 'lucide-react';

/**
 * WordPress dependencies
 */
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { useAppData } from '../../data/AppData';
import { errorMessage, saveSettings } from '../../data/api';
import type { SchemaElement } from '../../data/types';
import { useUnsavedChanges } from '../../shell/useUnsavedChanges';
import { SectionCard } from './SectionCard';

// The section the alert switches live in (Alerts\Alerts::SECTION).
const SECTION = 'alerts_dashboard';

type Switches = Record< string, boolean >;

const same = ( a: Switches, b: Switches ) =>
    Object.keys( a ).every( ( id ) => a[ id ] === b[ id ] );

/**
 * Settings > Alerts: a switch per alert type (the wcct_alert_types registry) plus "Doctor found something new"; a switch only hides, data is still recorded.
 */
export const AlertsSection = () => {
    const { data, replaceSettings } = useAppData();
    const fields = useMemo(
        () =>
            ( data?.schema.global ?? [] ).filter(
                ( element: SchemaElement ) =>
                    element.type === 'field' &&
                    element.variant === 'switch' &&
                    element.section_id === SECTION
            ),
        [ data?.schema.global ]
    );
    const saved = useMemo( () => {
        const values: Switches = {};

        fields.forEach( ( field ) => {
            values[ field.id ] = data?.settings[ field.id ] !== false;
        } );

        return values;
    }, [ fields, data?.settings ] );
    const [ draft, setDraft ] = useState< Switches >( saved );
    const [ saving, setSaving ] = useState( false );
    const [ error, setError ] = useState( '' );
    const dirty = ! same( draft, saved );

    useUnsavedChanges( dirty );

    // Settings loaded or saved elsewhere: start from them while nothing is changed here.
    useEffect( () => {
        if ( ! saving ) {
            setDraft( ( current ) =>
                Object.keys( current ).length ? current : saved
            );
        }
    }, [ saved, saving ] );

    const save = () => {
        const changed: Switches = {};

        Object.keys( draft ).forEach( ( id ) => {
            if ( draft[ id ] !== saved[ id ] ) {
                changed[ id ] = draft[ id ];
            }
        } );

        setSaving( true );
        setError( '' );

        saveSettings( changed )
            .then( ( next ) => {
                replaceSettings( next );

                const values: Switches = {};

                fields.forEach( ( field ) => {
                    values[ field.id ] = next[ field.id ] !== false;
                } );

                setDraft( values );
                toast.success(
                    __(
                        'Alert settings saved.',
                        'woocommerce-conversion-tracking'
                    )
                );
            } )
            .catch( ( reason: unknown ) => setError( errorMessage( reason ) ) )
            .finally( () => setSaving( false ) );
    };

    let label: string = __( 'Saved', 'woocommerce-conversion-tracking' );

    if ( saving ) {
        label = __( 'Saving…', 'woocommerce-conversion-tracking' );
    } else if ( dirty ) {
        label = __( 'Save settings', 'woocommerce-conversion-tracking' );
    }

    return (
        <SectionCard
            id="alerts"
            icon={ <Bell /> }
            title={ __( 'Alerts', 'woocommerce-conversion-tracking' ) }
            description={ __(
                "We tell you when we couldn't confirm a sale was sent to a platform. Every alert ends in something you can do.",
                'woocommerce-conversion-tracking'
            ) }
            footer={
                <>
                    <span
                        className="text-[13px] text-muted-foreground"
                        role="status"
                    >
                        { dirty
                            ? __(
                                  'Unsaved changes',
                                  'woocommerce-conversion-tracking'
                              )
                            : '' }
                    </span>
                    <Button
                        className="ml-auto"
                        disabled={ ! dirty || saving || ! data }
                        onClick={ save }
                        data-testid="wcct-alerts-save"
                    >
                        { label }
                    </Button>
                </>
            }
        >
            { error && (
                <div className="px-6 pt-5">
                    <Notice variant="destructive" role="alert">
                        <NoticeTitle>
                            { __(
                                'Alert settings were not saved.',
                                'woocommerce-conversion-tracking'
                            ) }
                        </NoticeTitle>
                        <p className="m-0">{ error }</p>
                    </Notice>
                </div>
            ) }
            <div className="flex flex-col">
                { fields.map( ( field ) => {
                    const id = `wcct-${ field.id.replace( /_/g, '-' ) }`;

                    return (
                        <div
                            key={ field.id }
                            className="flex items-center gap-4 border-b border-border px-6 py-4 last:border-b-0"
                            data-testid={ id }
                        >
                            <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                                <span
                                    id={ `${ id }-title` }
                                    className="text-sm font-semibold text-foreground"
                                >
                                    { String( field.label ?? '' ) }
                                </span>
                                { field.description ? (
                                    <span
                                        id={ `${ id }-desc` }
                                        className="text-[13px] text-muted-foreground"
                                    >
                                        { String( field.description ) }
                                    </span>
                                ) : null }
                            </div>
                            <Switch
                                id={ id }
                                checked={ draft[ field.id ] ?? true }
                                disabled={ saving }
                                onCheckedChange={ ( checked: boolean ) =>
                                    setDraft( ( current ) => ( {
                                        ...current,
                                        [ field.id ]: checked,
                                    } ) )
                                }
                                aria-labelledby={ `${ id }-title` }
                                aria-describedby={
                                    field.description
                                        ? `${ id }-desc`
                                        : undefined
                                }
                            />
                        </div>
                    );
                } ) }
            </div>
        </SectionCard>
    );
};
