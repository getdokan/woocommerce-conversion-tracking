/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type {
    AppData,
    PlatformValues,
    SettingsSchema,
    SettingsValues,
} from './types';

export const NAMESPACE = '/wcct/v1';

type ApiError = {
    code?: string;
    message?: string;
};

/**
 * A message to show for a failed request.
 *
 * @param {unknown} error What apiFetch rejected with.
 */
export const errorMessage = ( error: unknown ): string => {
    const { code, message } = ( error ?? {} ) as ApiError;

    if ( code === 'wcct_rest_migration_pending' ) {
        return __(
            'Your settings are still being updated to 3.0. Try again in a moment.',
            'woocommerce-conversion-tracking'
        );
    }

    if ( code === 'fetch_error' || ! message ) {
        return __(
            'Could not reach your site. Check your connection and try again.',
            'woocommerce-conversion-tracking'
        );
    }

    return message;
};

/**
 * Settings and schema, fetched together.
 */
export const fetchAppData = async (): Promise< AppData > => {
    const [ settings, schema ] = await Promise.all( [
        apiFetch< SettingsValues >( { path: `${ NAMESPACE }/settings` } ),
        apiFetch< SettingsSchema >( {
            path: `${ NAMESPACE }/settings/schema`,
        } ),
    ] );

    return {
        settings: {
            ...settings,
            platforms: settings?.platforms ?? {},
        },
        schema: {
            global: schema?.global ?? [],
            platforms: schema?.platforms ?? [],
        },
    };
};

export type SettingsUpdate = {
    platforms?: Record< string, PlatformValues >;
    [ key: string ]: unknown;
};

/**
 * Save changed settings; resolves with every value after the save.
 *
 * @param {SettingsUpdate} update Changed values only.
 */
export const saveSettings = async (
    update: SettingsUpdate
): Promise< SettingsValues > => {
    const settings = await apiFetch< SettingsValues >( {
        path: `${ NAMESPACE }/settings`,
        method: 'POST',
        data: update,
    } );

    return { ...settings, platforms: settings?.platforms ?? {} };
};
