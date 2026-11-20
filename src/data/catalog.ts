/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { decodeEntities } from '@wordpress/html-entities';

/**
 * Internal dependencies
 */
import { NAMESPACE } from './api';

export type ProductIdMode = 'id' | 'sku';

export type CatalogLogin = {
    enabled: boolean;
    username: string;
    password_set: boolean;
    password_overridden: boolean;
    // Stored but not decryptable (the salts changed); the feed stays closed until it is re-entered.
    password_unreadable: boolean;
};

export type CatalogCategory = {
    id: number;
    name: string;
    parent: number;
};

export type CatalogExclusions = {
    types: string[];
    categories: number[];
};

export type CatalogData = {
    locked: false;
    url: string;
    generated_at: number | null;
    age_hours: number | null;
    rows: number;
    stale: boolean;
    building: boolean;
    build_rows: number;
    error: string;
    login: CatalogLogin;
    can_edit_login: boolean;
    exclusions: CatalogExclusions;
    product_id_mode: ProductIdMode;
    pixel_product_id_mode: ProductIdMode;
    options: {
        types: Record< string, string >;
        categories: CatalogCategory[];
    };
    rebuild_queued?: boolean;
};

export type CatalogResponse = { locked: true } | CatalogData;

export type CatalogUpdate = {
    exclusions?: Partial< CatalogExclusions >;
    login?: { enabled?: boolean; username?: string; password?: string };
    product_id_mode?: ProductIdMode;
    rebuild?: boolean;
};

// Feed states, in the order the status card checks them.
export type FeedState = 'error' | 'missing' | 'building' | 'stale' | 'ready';

type Raw = Record< string, unknown >;

const isObject = ( value: unknown ): value is Raw =>
    !! value && typeof value === 'object' && ! Array.isArray( value );

const toInt = ( value: unknown ): number | null =>
    typeof value === 'number' && Number.isFinite( value )
        ? Math.max( 0, Math.floor( value ) )
        : null;

const toMode = ( value: unknown ): ProductIdMode =>
    value === 'sku' ? 'sku' : 'id';

/**
 * The catalog response with every field present and typed, so bad data never breaks the page.
 *
 * @param {unknown} raw Response body.
 */
export const normalizeCatalog = ( raw: unknown ): CatalogResponse => {
    const data = isObject( raw ) ? raw : {};

    if ( data.locked !== false ) {
        return { locked: true };
    }

    const login = isObject( data.login ) ? data.login : {};
    const exclusions = isObject( data.exclusions ) ? data.exclusions : {};
    const options = isObject( data.options ) ? data.options : {};
    // PHP sends an empty slug => label map as [].
    const types = isObject( options.types ) ? options.types : {};
    const categories = Array.isArray( options.categories )
        ? options.categories
        : [];

    return {
        locked: false,
        url: typeof data.url === 'string' ? data.url : '',
        generated_at: toInt( data.generated_at ) || null,
        age_hours: toInt( data.age_hours ),
        rows: toInt( data.rows ) ?? 0,
        stale: data.stale === true,
        building: data.building === true,
        build_rows: toInt( data.build_rows ) ?? 0,
        error: typeof data.error === 'string' ? data.error : '',
        login: {
            enabled: login.enabled === true,
            username: typeof login.username === 'string' ? login.username : '',
            password_set: login.password_set === true,
            password_overridden: login.password_overridden === true,
            password_unreadable: login.password_unreadable === true,
        },
        can_edit_login: data.can_edit_login === true,
        exclusions: {
            types: Array.isArray( exclusions.types )
                ? exclusions.types.filter(
                      ( type ): type is string => typeof type === 'string'
                  )
                : [],
            categories: Array.isArray( exclusions.categories )
                ? exclusions.categories
                      .map( toInt )
                      .filter( ( id ): id is number => !! id )
                : [],
        },
        product_id_mode: toMode( data.product_id_mode ),
        pixel_product_id_mode: toMode( data.pixel_product_id_mode ),
        options: {
            types: Object.fromEntries(
                Object.entries( types ).map( ( [ slug, label ] ) => [
                    slug,
                    typeof label === 'string' ? decodeEntities( label ) : slug,
                ] )
            ),
            categories: categories.filter( isObject ).map( ( term ) => ( {
                id: toInt( term.id ) ?? 0,
                // Term names come HTML-encoded ("Hats &amp; Caps").
                name:
                    typeof term.name === 'string'
                        ? decodeEntities( term.name )
                        : '',
                parent: toInt( term.parent ) ?? 0,
            } ) ),
        },
        ...( typeof data.rebuild_queued === 'boolean'
            ? { rebuild_queued: data.rebuild_queued }
            : {} ),
    };
};

/**
 * Feed status and settings, or { locked: true } on Free.
 */
export const fetchCatalog = async (): Promise< CatalogResponse > =>
    normalizeCatalog( await apiFetch( { path: `${ NAMESPACE }/catalog` } ) );

/**
 * Save catalog settings or queue a rebuild; resolves with the new state.
 *
 * @param {CatalogUpdate} update Changed fields only.
 */
export const saveCatalog = async (
    update: CatalogUpdate
): Promise< CatalogResponse > =>
    normalizeCatalog(
        await apiFetch( {
            path: `${ NAMESPACE }/catalog`,
            method: 'POST',
            data: update,
        } )
    );

/**
 * Which state the status card shows.
 *
 * @param {CatalogData} data Catalog data.
 */
export const feedState = ( data: CatalogData ): FeedState => {
    if ( data.error && ! data.building ) {
        return 'error';
    }

    if ( ! data.generated_at ) {
        return data.building ? 'building' : 'missing';
    }

    return data.stale ? 'stale' : 'ready';
};

/**
 * Categories grouped by parent ID; 0 holds the top level.
 *
 * @param {CatalogCategory[]} categories Categories.
 */
export const childrenByParent = (
    categories: CatalogCategory[]
): Map< number, CatalogCategory[] > => {
    const ids = new Set( categories.map( ( term ) => term.id ) );
    const map = new Map< number, CatalogCategory[] >();

    for ( const term of categories ) {
        // An orphan (parent missing or itself) shows at the top level.
        const parent =
            term.parent && term.parent !== term.id && ids.has( term.parent )
                ? term.parent
                : 0;

        map.set( parent, [ ...( map.get( parent ) ?? [] ), term ] );
    }

    return map;
};

/**
 * A category's ancestors' names, top first, for search results.
 *
 * @param {CatalogCategory}   term       Category.
 * @param {CatalogCategory[]} categories All categories.
 */
export const ancestorNames = (
    term: CatalogCategory,
    categories: CatalogCategory[]
): string[] => {
    const byId = new Map( categories.map( ( item ) => [ item.id, item ] ) );
    const names: string[] = [];
    const seen = new Set< number >( [ term.id ] );
    let parent = byId.get( term.parent );

    // `seen` stops a loop in bad data.
    while ( parent && ! seen.has( parent.id ) ) {
        names.unshift( parent.name );
        seen.add( parent.id );
        parent = byId.get( parent.parent );
    }

    return names;
};

/**
 * A number in the admin's language.
 *
 * @param {number} value Number.
 */
export const formatCount = ( value: number ): string => {
    try {
        return new Intl.NumberFormat(
            document.documentElement.lang || undefined
        ).format( value );
    } catch {
        return String( value );
    }
};
