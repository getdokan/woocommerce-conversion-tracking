/**
 * External dependencies
 */
import { Input, Label, cn } from '@wedevs/plugin-ui';
import { PlatformLogo, StatusPill } from '@wcct/components';
import { CircleCheck, TriangleAlert } from 'lucide-react';

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import {
    checkField,
    isRequired,
    listOf,
    unusualLength,
    type OnboardingField,
    type PanelCheck,
} from '../../data/onboarding';
import { unusualLengthMessage } from '../../data/platformForm';
import { isProOutdated, proUpdateUrl, upgradeUrl } from '../../data/plan';
import type { PlatformCardInfo } from '../../data/platforms';
import { NewTabNote } from '../../shell/NewTabNote';
import { logoUrl } from './PickTiles';

type ConnectPanelProps = {
    platform: PlatformCardInfo;
    fields: OnboardingField[];
    values: Record< string, string >;
    check: PanelCheck;
    events: string[];
    // Shown instead of fields, or under them.
    note?: string;
    onChange: ( field: string, value: string ) => void;
};

/**
 * One picked platform on step 3: its IDs, checked as they're typed, and what it will send.
 *
 * @param {ConnectPanelProps} props Component props.
 */
export const ConnectPanel = ( {
    platform,
    fields,
    values,
    check,
    events,
    note,
    onChange,
}: ConnectPanelProps ) => {
    const titleId = `wcct-connect-${ platform.id }-title`;

    return (
        <section
            className="flex flex-col gap-4 rounded-xl border border-border p-5"
            aria-labelledby={ titleId }
            data-testid={ `wcct-connect-${ platform.id }` }
        >
            <div className="flex items-center gap-3">
                <PlatformLogo src={ logoUrl( platform.logo ) } />
                <h2
                    id={ titleId }
                    className="m-0 text-base font-semibold text-foreground"
                >
                    { platform.name }
                </h2>
                { check.connectable && (
                    <StatusPill
                        tone={ check.ready ? 'ok' : 'off' }
                        className="ml-auto"
                    >
                        { check.ready
                            ? __(
                                  'Format looks right',
                                  'woocommerce-conversion-tracking'
                              )
                            : __(
                                  'Not connected',
                                  'woocommerce-conversion-tracking'
                              ) }
                    </StatusPill>
                ) }
            </div>

            { fields.map( ( field ) => {
                const id = `wcct-connect-${ platform.id }-${ field.id }`;
                const value = values[ field.id ] ?? '';
                const state = checkField( field, value );
                const missing = check.missing.includes( field.id );
                const unusual = unusualLength( field, value );
                let message = '';

                if ( unusual ) {
                    message = unusualLengthMessage();
                } else if ( state === 'valid' ) {
                    message = field.valid;
                } else if ( state === 'invalid' ) {
                    message = field.invalid;
                } else if ( missing ) {
                    message = field.missing;
                }

                const described = [
                    field.hint && `${ id }-hint`,
                    message && `${ id }-check`,
                ]
                    .filter( Boolean )
                    .join( ' ' );

                return (
                    <div key={ field.id } className="flex flex-col gap-1.5">
                        <Label
                            htmlFor={ id }
                            className="text-sm font-medium text-foreground"
                        >
                            { isRequired( field, values )
                                ? field.label
                                : sprintf(
                                      /* translators: %s: field label */
                                      __(
                                          '%s (optional)',
                                          'woocommerce-conversion-tracking'
                                      ),
                                      field.label
                                  ) }
                        </Label>
                        <Input
                            id={ id }
                            value={ value }
                            placeholder={ field.placeholder }
                            autoComplete="off"
                            spellCheck={ false }
                            aria-invalid={ state === 'invalid' || undefined }
                            aria-describedby={ described || undefined }
                            onChange={ ( event ) =>
                                onChange( field.id, event.target.value )
                            }
                            className="max-w-140"
                        />
                        { field.hint && (
                            <p
                                id={ `${ id }-hint` }
                                className="m-0 text-[13px] text-muted-foreground"
                            >
                                { field.hint }
                            </p>
                        ) }
                        { /* Always there, so the result of each keystroke is announced. */ }
                        <p
                            id={ `${ id }-check` }
                            aria-live="polite"
                            className={ cn(
                                'm-0 flex items-start gap-1.5 text-[13px]',
                                state !== 'valid' && 'text-destructive',
                                state === 'valid' &&
                                    ( unusual
                                        ? 'text-tone-warn-fg'
                                        : 'text-tone-ok-fg' )
                            ) }
                        >
                            { message && state === 'valid' && ! unusual && (
                                <CircleCheck
                                    className="mt-0.5 size-3.5 shrink-0"
                                    aria-hidden="true"
                                />
                            ) }
                            { message && ( state !== 'valid' || unusual ) && (
                                <TriangleAlert
                                    className="mt-0.5 size-3.5 shrink-0"
                                    aria-hidden="true"
                                />
                            ) }
                            { message && <span>{ message }</span> }
                        </p>
                    </div>
                );
            } ) }

            { note && (
                <p className="m-0 text-[13px] text-muted-foreground">
                    { note }
                    { platform.locked && isProOutdated() && (
                        <>
                            { ' ' }
                            <a
                                href={ proUpdateUrl() }
                                className="font-medium text-primary underline underline-offset-3"
                            >
                                { __(
                                    'Update Pro',
                                    'woocommerce-conversion-tracking'
                                ) }
                            </a>
                        </>
                    ) }
                    { platform.locked && ! isProOutdated() && (
                        <>
                            { ' ' }
                            <a
                                href={ upgradeUrl() }
                                target="_blank"
                                rel="noreferrer"
                                className="font-medium text-primary underline underline-offset-3"
                            >
                                { __(
                                    'Compare plans',
                                    'woocommerce-conversion-tracking'
                                ) }
                                <NewTabNote />
                            </a>
                        </>
                    ) }
                </p>
            ) }

            { check.connectable && events.length > 0 && (
                <p className="m-0 text-[13px] text-muted-foreground">
                    { sprintf(
                        /* translators: %s: list of event names */
                        __(
                            'Recommended events on: %s.',
                            'woocommerce-conversion-tracking'
                        ),
                        listOf( events )
                    ) }
                </p>
            ) }
        </section>
    );
};
