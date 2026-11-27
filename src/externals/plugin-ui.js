/**
 * Shared bundle for `@wedevs/plugin-ui`, exposed as `window.wcct.pluginUI`.
 */

// Relative path: the bare specifier is externalised to this bundle and `exports` hides `./dist/*`.
export * from '../../node_modules/@wedevs/plugin-ui/dist/index.js';
