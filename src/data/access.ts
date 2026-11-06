/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { NAMESPACE } from './api';

export type AccessRole = {
    id: string;
    name: string;
    granted: boolean;
    // Has access through manage_options; can't be changed.
    always: boolean;
    // Usually belongs to people outside the business.
    outside: boolean;
    // A Dokan vendor role: no wp-admin, but REST access once granted.
    dokan_vendor: boolean;
    // Had access before the 3.0 update.
    carried?: boolean;
};

export type AccessData = {
    // Free: the section shows the locked preview.
    locked: boolean;
    can_edit?: boolean;
    // Upgraded from 2.x.
    upgrade?: boolean;
    roles?: AccessRole[];
    dokan_vendor_granted?: boolean;
};

const PATH = `${ NAMESPACE }/access`;

/**
 * Who can manage tracking.
 */
export const fetchAccess = (): Promise< AccessData > =>
    apiFetch< AccessData >( { path: PATH } );

/**
 * Replace the granted roles (administrators only).
 *
 * @param {string[]} roles Every role that gets access.
 */
export const saveAccess = ( roles: string[] ): Promise< AccessData > =>
    apiFetch< AccessData >( { path: PATH, method: 'POST', data: { roles } } );

/**
 * Roles in the order the section lists them: always-on roles, Shop manager, then the site's order.
 *
 * @param {AccessRole[]} roles Roles from the route.
 */
export const sortRoles = ( roles: AccessRole[] ): AccessRole[] => {
    const rank = ( role: AccessRole ) => {
        if ( role.always ) {
            return 0;
        }

        return role.id === 'shop_manager' ? 1 : 2;
    };

    return roles
        .map( ( role, index ) => ( { role, index } ) )
        .sort(
            ( a, b ) => rank( a.role ) - rank( b.role ) || a.index - b.index
        )
        .map( ( { role } ) => role );
};
