#!/usr/bin/env node
/**
 * Replace the PLUGIN_SINCE placeholder with the release version
 *
 * Usage: npm run version
 *
 * Write `@since PLUGIN_SINCE` on new code, and this script turns it into the
 * version from package.json when the release is built.
 */

const fs = require( 'fs' );
const path = require( 'path' );

const ROOT = path.resolve( __dirname, '..' );
const PLACEHOLDER = /PLUGIN_SINCE/g;
const PATHS = [ 'conversion-tracking.php', 'includes', 'assets/js', 'assets/less' ];
const EXTENSIONS = [ '.php', '.js', '.less' ];

const { version } = require( path.join( ROOT, 'package.json' ) );

/**
 * Files under a path, relative to the plugin root
 */
function collect( relPath, files = [] ) {
    const absPath = path.join( ROOT, relPath );

    if ( ! fs.existsSync( absPath ) ) {
        return files;
    }

    if ( fs.statSync( absPath ).isDirectory() ) {
        for ( const entry of fs.readdirSync( absPath ) ) {
            collect( path.join( relPath, entry ), files );
        }
    } else if ( EXTENSIONS.includes( path.extname( relPath ) ) && ! relPath.endsWith( '.min.js' ) ) {
        files.push( relPath );
    }

    return files;
}

let changed = 0;

for ( const file of PATHS.flatMap( ( relPath ) => collect( relPath ) ) ) {
    const absPath = path.join( ROOT, file );
    const content = fs.readFileSync( absPath, 'utf8' );

    if ( PLACEHOLDER.test( content ) ) {
        fs.writeFileSync( absPath, content.replace( PLACEHOLDER, version ) );
        console.log( `Replaced PLUGIN_SINCE with ${ version } in ${ file }` );
        changed++;
    }

    PLACEHOLDER.lastIndex = 0;
}

console.log( changed ? `Updated ${ changed } file(s).` : 'No PLUGIN_SINCE placeholders found.' );
