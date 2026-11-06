/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Screen reader note for links that open a new tab.
 */
export const NewTabNote = () => (
    <span className="sr-only">
        { __( '(opens in a new tab)', 'woocommerce-conversion-tracking' ) }
    </span>
);
