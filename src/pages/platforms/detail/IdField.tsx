/**
 * External dependencies
 */
import { Input, Label, cn } from '@wedevs/plugin-ui';
import { ProBadge } from '@wcct/components';
import { CircleCheck, TriangleAlert } from 'lucide-react';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import {
    checkId,
    controlId,
    formatLooksRight,
    unusualLength,
    unusualLengthMessage,
} from '../../../data/platformForm';
import type { SchemaElement } from '../../../data/types';
import { CheckItsYours } from './CheckItsYours';

type IdFieldProps = {
    platform: string;
    // Platform name for the hint, like Meta.
    platformName: string;
    field: SchemaElement;
    value: string;
    // Whether the platform is connected; the valid line then skips "Click Connect".
    connected: boolean;
    // Message from a failed save.
    error?: string;
    // Whether the value shown is the saved one; "Check it's yours" waits for a save.
    saved: boolean;
    readOnly: boolean;
    onChange: ( value: string ) => void;
};

const text = ( value: unknown ): string =>
    typeof value === 'string' ? value : '';

/**
 * An ID field with its hint and a format check as you type (format only, no lookup).
 *
 * @param {IdFieldProps} props Component props.
 */
export const IdField = ( {
    platform,
    platformName,
    field,
    value,
    connected,
    error,
    saved,
    readOnly,
    onChange,
}: IdFieldProps ) => {
    const id = controlId( platform, field.id );
    const onboarding = ( field.onboarding ?? {} ) as Record< string, unknown >;
    const state = checkId( field, value );
    const unusual = unusualLength( field, value );
    const locked = field.pro === true && field.disabled === true;
    let message = '';

    if ( error ) {
        message = error;
    } else if ( unusual ) {
        message = unusualLengthMessage();
    } else if ( state === 'valid' ) {
        message = text( onboarding.valid ) || formatLooksRight( platformName );

        if ( ! connected ) {
            message = `${ message } ${ __(
                'Click Connect to start sending.',
                'woocommerce-conversion-tracking'
            ) }`;
        }
    } else if ( state === 'invalid' ) {
        message =
            text( onboarding.invalid ) ||
            __(
                "This doesn't match the ID format.",
                'woocommerce-conversion-tracking'
            );
    } else if ( state === 'short' ) {
        message = __(
            'Looks incomplete. Labels are usually 10 or more letters and numbers. The Help panel shows where to copy it.',
            'woocommerce-conversion-tracking'
        );
    }

    const good = ! error && ! unusual && state === 'valid';
    const description = text( field.description );
    const described = [
        description && `${ id }-hint`,
        message && `${ id }-check`,
    ]
        .filter( Boolean )
        .join( ' ' );

    return (
        <div className="flex flex-col gap-1.5" data-testid={ `${ id }-field` }>
            <Label
                htmlFor={ id }
                className="flex items-center gap-2 text-sm font-medium text-foreground"
            >
                { text( field.label ) || field.id }
                { locked && <ProBadge /> }
            </Label>
            <Input
                id={ id }
                value={ value }
                placeholder={ text( field.placeholder ) }
                autoComplete="off"
                spellCheck={ false }
                readOnly={ readOnly }
                disabled={ locked }
                aria-invalid={ !! error || state === 'invalid' || undefined }
                aria-describedby={ described || undefined }
                onChange={ ( event ) => onChange( event.target.value ) }
                className={ cn( 'max-w-140', readOnly && 'bg-muted' ) }
            />
            { description && (
                <p
                    id={ `${ id }-hint` }
                    className="m-0 text-[13px] text-muted-foreground"
                >
                    { description }
                </p>
            ) }
            { /* Always there, so the result of each keystroke is announced. */ }
            <p
                id={ `${ id }-check` }
                aria-live="polite"
                data-testid={ `${ id }-check` }
                className={ cn(
                    'm-0 flex items-start gap-1.5 text-[13px]',
                    good ? 'text-tone-ok-fg' : 'text-tone-warn-fg',
                    error && 'text-destructive'
                ) }
            >
                { message && good && (
                    <CircleCheck
                        className="mt-0.5 size-3.5 shrink-0"
                        aria-hidden="true"
                    />
                ) }
                { message && ! good && (
                    <TriangleAlert
                        className="mt-0.5 size-3.5 shrink-0"
                        aria-hidden="true"
                    />
                ) }
                { message && <span>{ message }</span> }
            </p>
            { ! error && saved && state === 'valid' && (
                <CheckItsYours
                    platform={ platform }
                    field={ field.id }
                    id={ id }
                />
            ) }
        </div>
    );
};
