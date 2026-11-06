/**
 * Tests for assets/js/consent-mode.js: the WP Consent API to Google mapping and the tag gate. Run with `npm run test:js`.
 */
const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const lib = require( '../../assets/js/consent-mode.js' );

const AD_TYPES = [ 'ad_storage', 'ad_user_data', 'ad_personalization' ];

function adUpdate( value ) {
    const update = {};
    AD_TYPES.forEach( ( type ) => ( update[ type ] = value ) );
    return update;
}

// A window and document with just enough for the runtime.
function fakePage( { cookie = '', readyState = 'loading' } = {} ) {
    const listeners = {};
    const inserted = [];
    const doc = {
        cookie,
        readyState,
        addEventListener( name, fn ) {
            ( listeners[ name ] = listeners[ name ] || [] ).push( fn );
        },
        dispatch( name, detail ) {
            ( listeners[ name ] || [] ).forEach( ( fn ) => fn( { detail } ) );
        },
        held: [],
        ran: [],
        createElement() {
            return {
                attrs: {},
                setAttribute( name, value ) {
                    this.attrs[ name ] = value;
                },
            };
        },
        querySelectorAll() {
            return doc.held.filter( ( el ) => el.getAttribute( 'data-wcct-consent' ) !== null );
        },
        getElementsByTagName() {
            return [ { parentNode: { insertBefore: ( tag ) => inserted.push( tag ) } } ];
        },
    };

    const winListeners = {};
    const win = {
        document: doc,
        dataLayer: [],
        addEventListener( name, fn ) {
            ( winListeners[ name ] = winListeners[ name ] || [] ).push( fn );
        },
        setTimeout( fn ) {
            fn();
        },
    };

    // The window load event; the document is complete by then.
    doc.load = () => {
        doc.readyState = 'complete';
        ( winListeners.load || [] ).forEach( ( fn ) => fn() );
    };

    return { win, doc, inserted };
}

// A <script type="text/plain" data-wcct-consent=...> element as the Custom markup prints it.
function heldScript( doc, attrs, text = '' ) {
    const el = {
        attributes: Object.entries( attrs ).map( ( [ name, value ] ) => ( { name, value } ) ),
        text,
        getAttribute( name ) {
            const found = this.attributes.find( ( a ) => a.name === name );
            return found ? found.value : null;
        },
        removeAttribute( name ) {
            this.attributes = this.attributes.filter( ( a ) => a.name !== name );
        },
        parentNode: {
            replaceChild( tag ) {
                doc.ran.push( tag );
            },
        },
    };

    doc.held.push( el );

    return el;
}

function googleUpdates( win ) {
    return win.dataLayer.filter( ( entry ) => entry[ 0 ] === 'consent' && entry[ 1 ] === 'update' ).map( ( entry ) => entry[ 2 ] );
}

test( 'change event: statistics maps to analytics_storage, marketing to the three ad types', () => {
    assert.deepEqual( lib.googleUpdateForChange( { statistics: 'allow' } ), { analytics_storage: 'granted' } );
    assert.deepEqual( lib.googleUpdateForChange( { marketing: 'allow' } ), adUpdate( 'granted' ) );
    assert.deepEqual( lib.googleUpdateForChange( { marketing: 'deny' } ), adUpdate( 'denied' ) );
    assert.deepEqual( lib.googleUpdateForChange( { statistics: 'deny', marketing: 'allow' } ), Object.assign( { analytics_storage: 'denied' }, adUpdate( 'granted' ) ) );
} );

test( 'change event: the API sends an array with a string key', () => {
    const detail = [];
    detail.marketing = 'allow';

    assert.deepEqual( lib.googleUpdateForChange( detail ), adUpdate( 'granted' ) );
} );

test( 'change event: functional, preferences and statistics-anonymous are ignored', () => {
    assert.equal( lib.googleUpdateForChange( { functional: 'allow' } ), null );
    assert.equal( lib.googleUpdateForChange( { preferences: 'deny', 'statistics-anonymous': 'allow' } ), null );
    assert.equal( lib.googleUpdateForChange( null ), null );
    assert.equal( lib.googleUpdateForChange( 'marketing' ), null );
} );

test( 'consent type: no update without a type', () => {
    assert.equal( lib.googleUpdateForType( '', () => true, () => '' ), null );
    assert.equal( lib.googleUpdateForType( undefined, () => true, () => '' ), null );
} );

test( 'consent type: opt-in sends every type, granted where the API has consent', () => {
    const has = ( category ) => category === 'statistics';

    assert.deepEqual( lib.googleUpdateForType( 'optin', has, () => '' ), Object.assign( { analytics_storage: 'granted' }, adUpdate( 'denied' ) ) );
} );

test( 'consent type: opt-out only sends the categories whose cookie says deny', () => {
    assert.equal( lib.googleUpdateForType( 'optout', () => true, () => '' ), null );
    assert.deepEqual( lib.googleUpdateForType( 'optout', () => true, ( category ) => ( category === 'marketing' ? 'deny' : 'allow' ) ), adUpdate( 'denied' ) );
} );

test( 'early state from the cookies before the consent type is known', () => {
    const cookies = { marketing: 'allow', statistics: 'deny' };
    const value = ( category ) => cookies[ category ] || '';

    assert.equal( lib.earlyAllows( '', value, 'marketing' ), true );
    assert.equal( lib.earlyAllows( '', value, 'statistics' ), false );
    assert.equal( lib.earlyAllows( '', value, 'preferences' ), null );
    assert.equal( lib.earlyAllows( 'optin', value, 'preferences' ), false );
    assert.equal( lib.earlyAllows( 'optout', value, 'preferences' ), true );
    assert.equal( lib.earlyAllows( 'optout', value, 'statistics' ), false );
} );

test( 'resolved state: everything allowed without a type, else the API decides', () => {
    assert.equal( lib.resolvedAllows( '', () => false, 'marketing' ), true );
    assert.equal( lib.resolvedAllows( 'optin', () => false, 'marketing' ), false );
    assert.equal( lib.resolvedAllows( 'optin', () => true, 'marketing' ), true );
} );

test( 'readCookie', () => {
    assert.equal( lib.readCookie( 'a=1; wp_consent_marketing=allow; b=2', 'wp_consent_marketing' ), 'allow' );
    assert.equal( lib.readCookie( 'wp_consent_marketing_x=deny', 'wp_consent_marketing' ), '' );
    assert.equal( lib.readCookie( undefined, 'x' ), '' );
} );

test( 'without the consent API everything is allowed at once', () => {
    const { win } = fakePage();
    const consent = lib.create( win, { api: false } );
    let ran = false;

    consent.when( 'marketing', () => ( ran = true ) );

    assert.equal( consent.allows( 'marketing' ), true );
    assert.equal( ran, true );
} );

test( 'no consent type and none expected: tags fire at DOMContentLoaded and no Google update is sent', () => {
    const { win, doc } = fakePage();
    const consent = lib.create( win, { api: true, bridge: true, typeExpected: false } );
    const calls = [];

    consent.sync( 'marketing', ( granted, first ) => calls.push( [ granted, first ] ) );
    consent.when( 'marketing', () => calls.push( 'fired' ) );
    assert.deepEqual( calls, [ [ false, true ] ] );

    doc.dispatch( 'DOMContentLoaded' );

    assert.deepEqual( calls, [ [ false, true ], [ true, false ], 'fired' ] );
    assert.deepEqual( googleUpdates( win ), [] );
} );

test( 'opt-in banner: denied until the visitor allows marketing, then granted and Google updated', () => {
    const { win, doc } = fakePage();
    const consent = lib.create( win, { api: true, bridge: true } );
    const given = {};
    const held = [];

    win.wp_consent_type = 'optin';
    win.wp_has_consent = ( category ) => !! given[ category ];

    win.wcfbq = ( ...args ) => held.push( args );
    consent.wrap( 'wcfbq', 'marketing' );
    win.wcfbq( '1', 'Purchase' );

    doc.dispatch( 'wp_consent_type_defined' );
    assert.equal( consent.allows( 'marketing' ), false );
    assert.deepEqual( held, [] );
    assert.deepEqual( googleUpdates( win ), [ Object.assign( { analytics_storage: 'denied' }, adUpdate( 'denied' ) ) ] );

    given.marketing = true;
    doc.dispatch( 'wp_listen_for_consent_change', { marketing: 'allow' } );

    assert.equal( consent.allows( 'marketing' ), true );
    assert.deepEqual( held, [ [ '1', 'Purchase' ] ] );
    assert.deepEqual( googleUpdates( win )[ 1 ], adUpdate( 'granted' ) );
} );

test( 'waitfor_consent_hook: DOMContentLoaded does not resolve, the type event does', () => {
    const { win, doc } = fakePage();
    const consent = lib.create( win, { api: true, bridge: false } );

    win.consent_api = { waitfor_consent_hook: true };
    doc.dispatch( 'DOMContentLoaded' );
    assert.equal( consent.allows( 'marketing' ), false );

    doc.dispatch( 'wp_consent_type_defined' );
    assert.equal( consent.allows( 'marketing' ), true );
    assert.deepEqual( googleUpdates( win ), [] );
} );

test( 'an allow cookie from an earlier visit allows at once', () => {
    const { win } = fakePage( { cookie: 'wp_consent_marketing=allow' } );
    const consent = lib.create( win, { api: true } );

    assert.equal( consent.allows( 'marketing' ), true );
    assert.equal( consent.allows( 'statistics' ), false );
} );

test( 'a script loads once on consent and runs every callback after it loaded', () => {
    const { win, doc, inserted } = fakePage();
    const consent = lib.create( win, { api: true } );
    const ran = [];

    win.wp_consent_type = 'optin';
    win.wp_has_consent = () => false;
    consent.script( 'marketing', '//static.ads-twitter.com/uwt.js', () => ran.push( 1 ) );
    consent.script( 'marketing', '//static.ads-twitter.com/uwt.js', () => ran.push( 2 ) );
    doc.dispatch( 'wp_consent_type_defined' );
    assert.equal( inserted.length, 0 );

    doc.dispatch( 'wp_listen_for_consent_change', { marketing: 'allow' } );
    assert.equal( inserted.length, 1 );
    assert.equal( inserted[ 0 ].src, '//static.ads-twitter.com/uwt.js' );
    assert.deepEqual( ran, [] );

    inserted[ 0 ].onload();
    consent.script( 'marketing', '//static.ads-twitter.com/uwt.js', () => ran.push( 3 ) );
    assert.deepEqual( ran, [ 1, 2, 3 ] );
} );

test( 'an explicitly empty wp_consent_type wins over the fallback type, as in wp_has_consent()', () => {
    const { win, doc } = fakePage();
    const consent = lib.create( win, { api: true, bridge: true } );

    win.wp_consent_type = '';
    win.wp_fallback_consent_type = 'optin';
    win.wp_has_consent = () => false;
    doc.dispatch( 'wp_consent_type_defined' );

    assert.equal( consent.allows( 'marketing' ), true );
    assert.deepEqual( googleUpdates( win ), [] );
} );

test( 'a type is expected: no type at DOMContentLoaded or load keeps tags held', () => {
    const { win, doc, inserted } = fakePage();
    const consent = lib.create( win, { api: true, bridge: true, typeExpected: true } );

    consent.script( 'marketing', '//static.ads-twitter.com/uwt.js' );
    doc.dispatch( 'DOMContentLoaded' );
    doc.load();
    doc.dispatch( 'wp_consent_type_defined' );

    assert.equal( consent.allows( 'marketing' ), false );
    assert.equal( inserted.length, 0 );
    assert.deepEqual( googleUpdates( win ), [] );
} );

test( 'the type the server knows is used before the banner script runs', () => {
    const { win, doc } = fakePage( { cookie: 'wp_consent_marketing=deny' } );
    const consent = lib.create( win, { api: true, type: 'optout', typeExpected: true } );

    assert.equal( consent.allows( 'marketing' ), false );
    assert.equal( consent.allows( 'statistics' ), true );

    doc.dispatch( 'DOMContentLoaded' );
    assert.equal( consent.allows( 'statistics' ), true );
    assert.equal( consent.allows( 'marketing' ), false );
} );

test( 'a banner that sets an opt-in type after DOMContentLoaded never lets tags through', () => {
    const { win, doc, inserted } = fakePage();
    const consent = lib.create( win, { api: true, bridge: true, typeExpected: true } );
    const calls = [];

    win.wp_fallback_consent_type = '';
    win.wp_has_consent = () => false;
    consent.sync( 'marketing', ( granted ) => calls.push( granted ) );
    consent.script( 'marketing', '//static.ads-twitter.com/uwt.js' );

    doc.dispatch( 'DOMContentLoaded' );
    win.wp_consent_type = 'optin';
    doc.dispatch( 'wp_consent_type_defined' );
    doc.load();

    assert.deepEqual( calls, [ false ] );
    assert.equal( inserted.length, 0 );
} );

test( 'a type known at DOMContentLoaded resolves at once', () => {
    const { win, doc } = fakePage();
    const consent = lib.create( win, { api: true } );

    win.wp_fallback_consent_type = 'optout';
    win.wp_has_consent = () => true;
    doc.dispatch( 'DOMContentLoaded' );

    assert.equal( consent.allows( 'marketing' ), true );
} );

test( 'held markup scripts run in order on consent, waiting for external ones', () => {
    const { win, doc } = fakePage();
    const consent = lib.create( win, { api: true, typeExpected: true } );

    heldScript( doc, { type: 'text/plain', 'data-wcct-consent': 'marketing', src: '//cdn.example/lib.js', async: '' } );
    heldScript( doc, { type: 'text/plain', 'data-wcct-consent': 'marketing' }, 'lib.track();' );
    heldScript( doc, { type: 'text/plain', 'data-wcct-consent': 'marketing', 'data-wcct-type': 'module' }, 'm();' );
    win.wp_consent_type = 'optin';
    win.wp_has_consent = () => false;

    doc.dispatch( 'DOMContentLoaded' );
    doc.dispatch( 'wp_consent_type_defined' );
    assert.equal( doc.ran.length, 0 );

    win.wp_has_consent = () => true;
    doc.dispatch( 'wp_listen_for_consent_change', { marketing: 'allow' } );

    assert.equal( doc.ran.length, 1 );
    assert.deepEqual( doc.ran[ 0 ].attrs, { src: '//cdn.example/lib.js', async: '' } );
    assert.equal( doc.ran[ 0 ].async, false );

    doc.ran[ 0 ].onload();

    assert.equal( doc.ran.length, 3 );
    assert.equal( doc.ran[ 1 ].text, 'lib.track();' );
    assert.deepEqual( doc.ran[ 2 ].attrs, { type: 'module' } );

    doc.dispatch( 'wp_listen_for_consent_change', { marketing: 'allow' } );
    assert.equal( doc.ran.length, 3 );
} );

test( 'held markup runs at DOMContentLoaded without the consent API', () => {
    const { win, doc } = fakePage();

    heldScript( doc, { type: 'text/plain', 'data-wcct-consent': 'marketing' }, 'x();' );
    lib.create( win, { api: false } );
    doc.dispatch( 'DOMContentLoaded' );

    assert.equal( doc.ran.length, 1 );
    assert.equal( doc.ran[ 0 ].text, 'x();' );
} );

test( 'inline code from the pending events runner waits for its category', () => {
    const { win, doc } = fakePage();
    const appended = [];
    const consent = lib.create( win, { api: true, typeExpected: true } );

    doc.body = { appendChild: ( tag ) => appended.push( tag ) };
    win.wp_consent_type = 'optin';
    win.wp_has_consent = () => false;
    doc.dispatch( 'wp_consent_type_defined' );

    consent.inline( 'marketing', "fbq('track', 'AddToCart');" );
    consent.inline( 'statistics', "gtag('event', 'add_to_cart');" );
    assert.equal( appended.length, 0 );

    doc.dispatch( 'wp_listen_for_consent_change', { statistics: 'allow' } );
    assert.deepEqual( appended.map( ( t ) => t.text ), [ "gtag('event', 'add_to_cart');" ] );
    assert.deepEqual( appended[ 0 ].attrs, { 'data-no-optimize': '1' } );

    doc.dispatch( 'wp_listen_for_consent_change', { marketing: 'allow' } );
    assert.equal( appended[ 1 ].text, "fbq('track', 'AddToCart');" );
} );

test( 'revoking later calls sync with denied again', () => {
    const { win, doc } = fakePage( { cookie: 'wp_consent_marketing=allow' } );
    const consent = lib.create( win, { api: true } );
    const calls = [];

    consent.sync( 'marketing', ( granted ) => calls.push( granted ) );
    doc.dispatch( 'wp_listen_for_consent_change', { marketing: 'deny' } );

    assert.deepEqual( calls, [ true, false ] );
} );

test( 'the file runs inline in a browser and sets window.wcctConsent', () => {
    const fs = require( 'node:fs' );
    const path = require( 'node:path' );
    const vm = require( 'node:vm' );
    const { win } = fakePage();

    win.wcctConsentConfig = { api: false };
    vm.runInNewContext( fs.readFileSync( path.join( __dirname, '../../assets/js/consent-mode.js' ), 'utf8' ), { window: win } );

    assert.equal( typeof win.wcctConsent.allows, 'function' );
    assert.equal( win.wcctConsent.allows( 'marketing' ), true );
} );
