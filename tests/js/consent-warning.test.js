/**
 * Tests for the "no cookie plugin" warning read from the settings schema in src/data/consentWarning.ts (#135). Run with `npm run test:js`.
 */
const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );

const load = () => import( '../../src/data/consentWarning.ts' );

const field = {
    id: 'consent_mode',
    type: 'field',
    warning: {
        title: 'No cookie plugin found.',
        messages: { '': 'All.', facebook: 'Meta.', twitter: 'X.' },
        link: {
            label: 'See supported cookie plugins',
            url: 'https://example.org/',
        },
    },
};

test( 'a platform gets its own text, onboarding the general one', async () => {
    const { consentWarning } = await load();

    assert.deepEqual( consentWarning( field, 'facebook' ), {
        title: 'No cookie plugin found.',
        message: 'Meta.',
        link: {
            label: 'See supported cookie plugins',
            url: 'https://example.org/',
        },
    } );
    assert.equal( consentWarning( field ).message, 'All.' );
} );

test( 'no warning without one, or for a platform it leaves out', async () => {
    const { consentWarning } = await load();

    assert.equal(
        consentWarning( { ...field, warning: null }, 'facebook' ),
        null
    );
    assert.equal( consentWarning( undefined, 'facebook' ), null );
    // Google, when a consent tool owns Google consent mode.
    assert.equal( consentWarning( field, 'google' ), null );
} );
