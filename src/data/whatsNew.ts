/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { NAMESPACE } from './api';

export type AttentionItem = {
    // Migration flag ID, for example google_ads_id_split.
    id: string;
    platform: string;
    fields: string[];
    title: string;
    text: string;
    action: string;
    // App route, #/platforms/<id>?field=<field>.
    url: string;
    // The value may already be right: offers "Looks right".
    confirmable: boolean;
};

export type PausedItem = {
    // Pause entry: a platform, or ga4 / adwords alone.
    id: string;
    platform: string;
    label: string;
    url: string;
};

export type WhatsNewNote = {
    id: string;
    title: string;
    // May wrap one link in <link></link>.
    text: string;
    url: string;
};

export type WhatsNewData = {
    state: 'done' | 'attention';
    attention: AttentionItem[];
    paused: PausedItem[];
    notes: WhatsNewNote[];
    // Extra Meta pixels 2.x Pro kept, for the old-Pro banner.
    extra_pixels: number;
    // "Respect cookie choices" is on.
    consent: boolean;
    // The previous settings can be downloaded.
    backup: boolean;
    // "Done, show me the dashboard" was pressed: no dashboard banner.
    dismissed: boolean;
    // The one-time redirect is used up.
    seen: boolean;
};

const PATH = `${ NAMESPACE }/whats-new`;

/**
 * Whether this store upgraded from 2.x, so What's new exists.
 */
export const hasWhatsNew = (): boolean => !! window.wcctAdmin?.whatsNew;

/**
 * The page's data.
 */
export const fetchWhatsNew = (): Promise< WhatsNewData > =>
    apiFetch< WhatsNewData >( { path: PATH } );

/**
 * The page was opened: the one-time redirect is no longer needed.
 */
export const markWhatsNewSeen = (): Promise< WhatsNewData > =>
    apiFetch< WhatsNewData >( { path: `${ PATH }/seen`, method: 'POST' } );

/**
 * "Looks right": close an item whose value needs no change.
 *
 * @param {string} id Flag ID.
 */
export const confirmWhatsNewItem = ( id: string ): Promise< WhatsNewData > =>
    apiFetch< WhatsNewData >( {
        path: `${ PATH }/confirm`,
        method: 'POST',
        data: { id },
    } );

/**
 * "Done, show me the dashboard": hide the dashboard banner.
 */
export const dismissWhatsNew = (): Promise< WhatsNewData > =>
    apiFetch< WhatsNewData >( { path: `${ PATH }/dismiss`, method: 'POST' } );

/**
 * Download the settings from before 3.0 (wcct/v1/backup) as a JSON file; resolves with the file name.
 */
export const downloadBackup = async (): Promise< string > => {
    const response = await apiFetch< Response, false >( {
        path: `${ NAMESPACE }/backup`,
        parse: false,
    } ).catch( async ( error: unknown ) => {
        // Unparsed, a failed request rejects with the raw response; its body holds the message.
        throw error instanceof Response
            ? await error.json().catch( () => ( {} ) )
            : error;
    } );
    const disposition = response.headers.get( 'Content-Disposition' ) ?? '';
    const name =
        /filename="([^"]+)"/.exec( disposition )?.[ 1 ] ??
        'conversion-tracking-settings-before-3.0.json';
    const url = URL.createObjectURL( await response.blob() );
    const link = document.createElement( 'a' );

    link.href = url;
    link.download = name;
    document.body.appendChild( link );
    link.click();
    link.remove();
    // Revoked after the click has handed the file to the browser.
    window.setTimeout( () => URL.revokeObjectURL( url ), 1000 );

    return name;
};
