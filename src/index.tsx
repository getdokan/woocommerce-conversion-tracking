/**
 * WordPress dependencies
 */
import domReady from '@wordpress/dom-ready';
import { createRoot } from '@wordpress/element';

/**
 * Internal dependencies
 */
import App from './App';
import './admin-page.css';

domReady( () => {
    const root = document.getElementById( 'wcct-app-root' );

    if ( ! root ) {
        return;
    }

    createRoot( root ).render( <App /> );
} );
