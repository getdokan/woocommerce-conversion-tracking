#!/usr/bin/env node
/**
 * Remove the build directory
 *
 * Usage: npm run clean
 */

const fs = require( 'fs' );
const path = require( 'path' );

const ROOT = path.resolve( __dirname, '..' );
const BUILD_DIR = path.join( ROOT, 'build' );

fs.rmSync( BUILD_DIR, { recursive: true, force: true } );

console.log( `Removed ${ path.relative( ROOT, BUILD_DIR ) }/` );
