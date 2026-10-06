#!/usr/bin/env node
/**
 * Build the release zip
 *
 * Usage: npm run zip [-- --skip-composer]
 *
 * 1. Checks that the plugin header, readme.txt stable tag and package.json
 *    have the same version.
 * 2. Installs composer packages without dev dependencies (Mozart output in
 *    dependencies/ is kept), unless --skip-composer is passed.
 * 3. Zips the plugin into build/woocommerce-conversion-tracking-v<version>.zip,
 *    leaving out everything listed in .distignore. .distignore is also used
 *    by the WordPress.org deploy, so both builds ship the same files.
 * 4. Restores the dev composer packages, also when the build fails.
 */

const fs = require( 'fs' );
const path = require( 'path' );
const { execSync } = require( 'child_process' );
const archiver = require( 'archiver' );

const ROOT = path.resolve( __dirname, '..' );
const SLUG = 'woocommerce-conversion-tracking';
const MAIN_FILE = 'conversion-tracking.php';
const BUILD_DIR = path.join( ROOT, 'build' );
const SKIP_COMPOSER = process.argv.includes( '--skip-composer' );

/**
 * Read a version with a regex from a file in the plugin root
 */
function readVersion( file, regex ) {
    const match = fs.readFileSync( path.join( ROOT, file ), 'utf8' ).match( regex );

    return match ? match[ 1 ].trim() : null;
}

/**
 * Plugin version, after checking every place it is written
 */
function getVersion() {
    const versions = {
        [ MAIN_FILE ]: readVersion( MAIN_FILE, /^\s*\*?\s*Version:\s*(.+)$/m ),
        'readme.txt': readVersion( 'readme.txt', /^Stable tag:\s*(.+)$/m ),
        'package.json': require( path.join( ROOT, 'package.json' ) ).version,
    };

    const unique = [ ...new Set( Object.values( versions ) ) ];

    if ( unique.length !== 1 || ! unique[ 0 ] ) {
        const list = Object.entries( versions ).map( ( [ file, version ] ) => `  ${ file }: ${ version }` ).join( '\n' );

        throw new Error( `Versions do not match:\n${ list }` );
    }

    return unique[ 0 ];
}

/**
 * Turn .distignore lines into matchers, with rsync --exclude-from rules
 *
 * A pattern without a slash matches a file or folder with that name at any
 * depth. A pattern with a slash matches the path from the plugin root. `*`
 * matches within one path segment, `**` across segments.
 */
function readDistIgnore() {
    const file = path.join( ROOT, '.distignore' );

    if ( ! fs.existsSync( file ) ) {
        throw new Error( '.distignore not found' );
    }

    return fs.readFileSync( file, 'utf8' )
        .split( /\r?\n/ )
        .map( ( line ) => line.trim() )
        .filter( ( line ) => line && ! line.startsWith( '#' ) )
        .map( ( line ) => {
            const pattern = line.replace( /\/+$/, '' );
            const anchored = pattern.includes( '/' );
            const source = pattern
                .replace( /^\//, '' )
                .replace( /[.+^${}()|[\]\\]/g, '\\$&' )
                .replace( /\*\*/g, '\u0000' )
                .replace( /\*/g, '[^/]*' )
                .replace( /\?/g, '[^/]' )
                .replace( /\u0000/g, '.*' );

            return { anchored, regex: new RegExp( `^${ source }$` ) };
        } );
}

/**
 * Whether a path relative to the plugin root is excluded
 */
function isIgnored( relPath, rules ) {
    const name = path.posix.basename( relPath );

    return rules.some( ( rule ) => ( rule.anchored ? rule.regex.test( relPath ) : rule.regex.test( name ) ) );
}

/**
 * Files to ship, relative to the plugin root, with forward slashes
 */
function collectFiles( rules, dir = '', files = [] ) {
    for ( const entry of fs.readdirSync( path.join( ROOT, dir ), { withFileTypes: true } ) ) {
        const relPath = dir ? `${ dir }/${ entry.name }` : entry.name;

        if ( isIgnored( relPath, rules ) || entry.isSymbolicLink() ) {
            continue;
        }

        if ( entry.isDirectory() ) {
            collectFiles( rules, relPath, files );
        } else if ( entry.isFile() ) {
            files.push( relPath );
        }
    }

    return files;
}

function composer( args ) {
    console.log( `> composer ${ args }` );
    execSync( `composer ${ args } --no-interaction`, { cwd: ROOT, stdio: 'inherit' } );
}

function createZip( files, zipPath ) {
    return new Promise( ( resolve, reject ) => {
        const output = fs.createWriteStream( zipPath );
        const archive = archiver( 'zip', { zlib: { level: 9 } } );

        output.on( 'close', () => resolve( archive.pointer() ) );
        archive.on( 'warning', reject );
        archive.on( 'error', reject );
        archive.pipe( output );

        for ( const file of files ) {
            archive.file( path.join( ROOT, file ), { name: `${ SLUG }/${ file }` } );
        }

        archive.finalize();
    } );
}

async function main() {
    const version = getVersion();
    const rules = readDistIgnore();
    const zipPath = path.join( BUILD_DIR, `${ SLUG }-v${ version }.zip` );

    console.log( `Building ${ SLUG } ${ version }` );

    try {
        if ( ! SKIP_COMPOSER ) {
            composer( 'install --no-dev --optimize-autoloader' );
        }

        if ( ! fs.existsSync( path.join( ROOT, 'dependencies' ) ) || ! fs.existsSync( path.join( ROOT, 'vendor', 'autoload.php' ) ) ) {
            throw new Error( 'dependencies/ or vendor/autoload.php is missing. Run "composer install" first, it generates dependencies/ with Mozart.' );
        }

        const installed = path.join( ROOT, 'vendor', 'composer', 'installed.json' );

        if ( fs.existsSync( installed ) && ( JSON.parse( fs.readFileSync( installed, 'utf8' ) )[ 'dev-package-names' ] || [] ).length ) {
            throw new Error( 'vendor/ has dev packages. Run "composer install --no-dev -o" first, or build without --skip-composer.' );
        }

        const files = collectFiles( rules );

        fs.rmSync( BUILD_DIR, { recursive: true, force: true } );
        fs.mkdirSync( BUILD_DIR, { recursive: true } );

        const bytes = await createZip( files, zipPath );

        console.log( `Created ${ path.relative( ROOT, zipPath ) } (${ files.length } files, ${ ( bytes / 1024 ).toFixed( 0 ) } KB)` );
    } finally {
        if ( ! SKIP_COMPOSER ) {
            composer( 'install' );
        }
    }
}

main().catch( ( error ) => {
    console.error( `\nBuild failed: ${ error.message }` );
    process.exit( 1 );
} );
