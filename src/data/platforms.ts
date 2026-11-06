/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type {
    AppData,
    PlatformValues,
    SchemaElement,
    SchemaPlatform,
} from './types';

export type PlatformCardInfo = {
    // Settings platform ID, also the #/platforms/:id route.
    id: string;
    name: string;
    meta: string;
    logo: string;
    // Shown on the welcome screen's "Platforms you can connect".
    core: boolean;
    // Pro platform that isn't registered, so Free shows it locked.
    locked: boolean;
    connected: boolean;
    paused: boolean;
    connectLabel: string;
};

type PlatformMeta = {
    name: string;
    meta: string;
    logo: string;
    core?: boolean;
    pro?: boolean;
    connectLabel?: string;
};

/**
 * Display names, subtitles and logos, in dashboard order.
 */
const platformMeta = (): Record< string, PlatformMeta > => ( {
    facebook: {
        name: __( 'Meta', 'woocommerce-conversion-tracking' ),
        meta: __(
            'Facebook and Instagram ads',
            'woocommerce-conversion-tracking'
        ),
        logo: 'meta.svg',
        core: true,
    },
    google: {
        name: __( 'Google', 'woocommerce-conversion-tracking' ),
        meta: __(
            'Analytics, Ads, or both',
            'woocommerce-conversion-tracking'
        ),
        logo: 'google-analytics.svg',
        core: true,
    },
    twitter: {
        name: __( 'X', 'woocommerce-conversion-tracking' ),
        meta: __( 'formerly Twitter', 'woocommerce-conversion-tracking' ),
        logo: 'x.svg',
        core: true,
    },
    bing_ads: {
        name: __( 'Microsoft Advertising', 'woocommerce-conversion-tracking' ),
        meta: __( 'UET tag', 'woocommerce-conversion-tracking' ),
        logo: 'microsoft.svg',
        pro: true,
    },
    custom: {
        name: __( 'Custom script', 'woocommerce-conversion-tracking' ),
        meta: __( 'Any other tag', 'woocommerce-conversion-tracking' ),
        logo: 'custom.svg',
        connectLabel: __( 'Add a script', 'woocommerce-conversion-tracking' ),
    },
} );

/**
 * A platform logo's URL, or an empty string.
 *
 * @param {string} file File in assets/images/platforms/.
 */
export const platformLogoUrl = ( file: string ): string =>
    file ? `${ window.wcctAdmin?.logosUrl ?? '' }${ file }` : '';

const filled = ( value: unknown ): boolean =>
    typeof value === 'string' && value.trim() !== '';

/**
 * Whether a field applies, given the other values (schema `dependencies`).
 *
 * @param {SchemaElement} field  Field.
 * @param {Object}        values Platform values.
 */
const applies = ( field: SchemaElement, values: PlatformValues ): boolean =>
    ( field.dependencies ?? [] ).every(
        ( dependency ) =>
            ! Array.isArray( dependency.value ) ||
            dependency.value.includes( values[ dependency.key ] )
    );

/**
 * Whether a platform has what it needs to send: an ID, or an enabled custom script.
 *
 * @param {SchemaPlatform} platform Platform schema.
 * @param {Object}         values   Platform values.
 */
export const isConnected = (
    platform: SchemaPlatform,
    values: PlatformValues
): boolean =>
    platform.schema.some( ( field ) => {
        if ( field.type !== 'field' || ! applies( field, values ) ) {
            return false;
        }

        if ( field.format === 'id' ) {
            return filled( values[ field.id ] );
        }

        // Custom script placements: an `<placement>_enabled` switch with its `<placement>_script`.
        if ( field.variant === 'switch' && field.id.endsWith( '_enabled' ) ) {
            return (
                values[ field.id ] === true &&
                filled( values[ field.id.replace( /_enabled$/, '_script' ) ] )
            );
        }

        return false;
    } );

/**
 * A long ID shortened for a card, like 1234…456.
 *
 * @param {string} id ID.
 */
export const shortId = ( id: string ): string =>
    id.length > 9 ? `${ id.slice( 0, 4 ) }…${ id.slice( -3 ) }` : id;

/**
 * The card subtitle once a platform is connected.
 *
 * @param {string} id     Platform ID.
 * @param {Object} values Platform values.
 * @param {string} meta   Default subtitle.
 */
const connectedMeta = (
    id: string,
    values: PlatformValues,
    meta: string
): string => {
    if ( id === 'facebook' && filled( values.pixel_id ) ) {
        return sprintf(
            /* translators: %s: shortened Meta Pixel ID */
            __( 'Pixel %s', 'woocommerce-conversion-tracking' ),
            shortId( String( values.pixel_id ) )
        );
    }

    if ( id === 'google' ) {
        switch ( values.mode ) {
            case 'analytics':
                return __( 'Analytics', 'woocommerce-conversion-tracking' );
            case 'ads':
                return __( 'Ads', 'woocommerce-conversion-tracking' );
            case 'both':
                return __(
                    'Analytics and Ads',
                    'woocommerce-conversion-tracking'
                );
        }
    }

    return meta;
};

/**
 * Every platform the dashboard shows: registered ones, plus Pro ones Free shows locked.
 *
 * @param {AppData} data Settings and schema.
 */
export const platformCards = ( data: AppData ): PlatformCardInfo[] => {
    const known = platformMeta();
    const registered = new Map(
        data.schema.platforms.map( ( platform ) => [ platform.id, platform ] )
    );
    const ids = [
        ...Object.keys( known ).filter(
            ( id ) => registered.has( id ) || known[ id ].pro
        ),
        ...[ ...registered.keys() ].filter( ( id ) => ! known[ id ] ),
    ];

    return ids.map( ( id ) => {
        const platform = registered.get( id );
        const meta = known[ id ];
        const values = data.settings.platforms[ id ] ?? {};
        const connected = platform ? isConnected( platform, values ) : false;
        const defaultMeta = meta?.meta ?? '';

        return {
            id,
            name: meta?.name ?? platform?.label ?? id,
            meta: connected
                ? connectedMeta( id, values, defaultMeta )
                : defaultMeta,
            logo: meta?.logo ?? '',
            core: !! meta?.core,
            locked: ! platform,
            connected,
            paused: connected && values.paused === true,
            connectLabel:
                meta?.connectLabel ??
                __( 'Connect', 'woocommerce-conversion-tracking' ),
        };
    } );
};

/**
 * Whether a #/platforms/:id route names a platform the app knows.
 *
 * @param {AppData} data Settings and schema.
 * @param {string}  id   Platform ID.
 */
export const isKnownPlatform = ( data: AppData, id: string ): boolean =>
    platformCards( data ).some( ( card ) => card.id === id );
