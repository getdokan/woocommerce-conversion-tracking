/**
 * Externals for the shared CT bundles on `window.wcct`, also used by Pro's webpack config.
 *
 * @see https://developer.wordpress.org/block-editor/reference-guides/packages/packages-dependency-extraction-webpack-plugin/
 */

/**
 * Bare specifier => global and script handle.
 *
 * @type {Object.<string, {external: string[], handle: string}>}
 */
const SHARED_EXTERNALS = {
    '@wedevs/plugin-ui': {
        external: [ 'wcct', 'pluginUI' ],
        handle: 'wcct-plugin-ui',
    },
    '@wcct/components': {
        external: [ 'wcct', 'components' ],
        handle: 'wcct-components',
    },
};

/**
 * Global for a request, or undefined to use the default mapping.
 *
 * @param {string} request Request string.
 *
 * @return {string[]|undefined} External name.
 */
const requestToExternal = ( request ) => {
    // Bare specifiers only; src/externals/plugin-ui.js relies on that.
    if ( SHARED_EXTERNALS[ request ] ) {
        return SHARED_EXTERNALS[ request ].external;
    }
};

/**
 * Script handle for a request, or undefined to use the default mapping.
 *
 * @param {string} request Request string.
 *
 * @return {string|undefined} Script handle.
 */
const requestToHandle = ( request ) => {
    if ( SHARED_EXTERNALS[ request ] ) {
        return SHARED_EXTERNALS[ request ].handle;
    }
};

module.exports = {
    SHARED_EXTERNALS,
    requestToExternal,
    requestToHandle,
};
