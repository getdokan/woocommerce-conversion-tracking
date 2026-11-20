/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { NAMESPACE } from './api';
import type { SettingsValues } from './types';

/**
 * Pause or resume tracking on one platform; it takes effect at once and purges the page caches.
 *
 * @param {string}  platform Platform ID.
 * @param {boolean} paused   Pause or resume.
 * @return {Promise<SettingsValues>} Every platform's values after the change.
 */
export const savePaused = (
    platform: string,
    paused: boolean
): Promise< SettingsValues > =>
    apiFetch< SettingsValues >( {
        path: `${ NAMESPACE }/settings/pause`,
        method: 'POST',
        data: { platform, paused },
    } );

/**
 * The confirmation text for a platform, as the prototype words it.
 *
 * @param {string} id   Platform ID.
 * @param {string} name Display name.
 */
export const pauseDialogText = ( id: string, name: string ): string => {
    switch ( id ) {
        case 'facebook':
            return __(
                "Nothing is sent to Meta until you resume. Your Pixel ID and event choices stay saved. Your ads keep running, but Meta won't see new sales from this store while it's paused.",
                'woocommerce-conversion-tracking'
            );
        case 'google':
            return __(
                "Nothing is sent to Analytics or Ads until you resume. Your IDs stay saved. Your campaigns keep running, but they won't see new sales from this store while it's paused.",
                'woocommerce-conversion-tracking'
            );
        case 'twitter':
            return __(
                "Nothing is sent to X until you resume. Your Pixel ID and event IDs stay saved. Your ads keep running, but X won't see new sales from this store while it's paused.",
                'woocommerce-conversion-tracking'
            );
        case 'bing_ads':
            return __(
                "Nothing is sent to Microsoft until you resume. Your UET tag ID stays saved. Your ads keep running, but Microsoft won't see new sales from this store while it's paused.",
                'woocommerce-conversion-tracking'
            );
        default:
            return sprintf(
                /* translators: %s: platform name, e.g. Custom script */
                __(
                    'Nothing is sent to %s until you resume. Your settings stay saved.',
                    'woocommerce-conversion-tracking'
                ),
                name
            );
    }
};

/**
 * Short name used in "Nothing is sent to …" lines: Microsoft for Microsoft Advertising.
 *
 * @param {string} id   Platform ID.
 * @param {string} name Display name.
 */
export const pauseTarget = ( id: string, name: string ): string =>
    id === 'bing_ads'
        ? __( 'Microsoft', 'woocommerce-conversion-tracking' )
        : name;
