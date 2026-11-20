const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const DependencyExtractionWebpackPlugin = require( '@wordpress/dependency-extraction-webpack-plugin' );
const {
    requestToExternal,
    requestToHandle,
} = require( './webpack-dependency-mapping' );

module.exports = {
    ...defaultConfig,
    entry: {
        // Admin app.
        index: './src/index.tsx',
        // Shared CT components, exposed as `window.wcct.components` for Pro (`@wcct/components`).
        components: {
            import: './src/components/index.ts',
            library: {
                name: [ 'wcct', 'components' ],
                type: 'window',
            },
        },
        // One shared copy of `@wedevs/plugin-ui`, exposed as `window.wcct.pluginUI`.
        'plugin-ui': {
            import: './src/externals/plugin-ui.js',
            library: {
                name: [ 'wcct', 'pluginUI' ],
                type: 'window',
            },
        },
    },
    output: {
        ...defaultConfig.output,
        path: path.resolve( __dirname, 'dist' ),
        filename: '[name].js',
        clean: true,
        devtoolNamespace: 'wcct',
    },
    resolve: {
        ...defaultConfig.resolve,
        extensions: [ '.json', '.js', '.jsx', '.ts', '.tsx' ],
        alias: {
            ...defaultConfig.resolve?.alias,
            '@src': path.resolve( __dirname, 'src' ),
        },
    },
    plugins: [
        ...defaultConfig.plugins.filter(
            ( plugin ) =>
                plugin.constructor.name !== 'DependencyExtractionWebpackPlugin'
        ),
        new DependencyExtractionWebpackPlugin( {
            requestToExternal,
            requestToHandle,
        } ),
    ],
};
