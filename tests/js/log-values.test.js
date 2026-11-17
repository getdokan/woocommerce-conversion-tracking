/**
 * Tests for the Activity log's "Value sent" text in src/pages/log/text.ts (#138). Run with `npm run test:js`.
 */
const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );

const load = () => import( '../../src/pages/log/text.ts' );

const value = ( integration, label, amount ) => ( {
    integration,
    label,
    value: amount.replace( '$', '' ),
    amount,
} );

test( 'one value for a platform with one integration', async () => {
    const { sentValueText } = await load();

    assert.equal(
        sentValueText( {
            id: 'facebook',
            integrations: [ 'facebook' ],
            values: [ value( 'facebook', '', '$84.00' ) ],
        } ),
        'Value sent: $84.00'
    );
} );

test( 'Google names GA4 and Google Ads when their values differ', async () => {
    const { sentValueText } = await load();
    const google = {
        id: 'google',
        integrations: [ 'ga4', 'adwords' ],
        values: [
            value( 'ga4', 'GA4', '$73.00' ),
            value( 'adwords', 'Google Ads', '$82.00' ),
        ],
    };

    assert.equal(
        sentValueText( google ),
        'Value sent: GA4 $73.00, Google Ads $82.00'
    );
} );

test( 'Google shows one amount when both were sent the same', async () => {
    const { sentValueText } = await load();
    const google = {
        id: 'google',
        integrations: [ 'ga4', 'adwords' ],
        values: [
            value( 'ga4', 'GA4', '$73.00' ),
            value( 'adwords', 'Google Ads', '$73.00' ),
        ],
    };

    assert.equal( sentValueText( google ), 'Value sent: $73.00' );
} );

test( 'Google names the one integration that was sent when the other was not', async () => {
    const { sentValueText } = await load();
    const google = {
        id: 'google',
        integrations: [ 'ga4', 'adwords' ],
        values: [ value( 'ga4', 'GA4', '$73.00' ) ],
    };

    assert.equal( sentValueText( google ), 'Value sent: GA4 $73.00' );
} );

test( 'nothing when no value was recorded', async () => {
    const { sentValueText } = await load();

    assert.equal(
        sentValueText( {
            id: 'custom',
            integrations: [ 'custom' ],
            values: [],
        } ),
        ''
    );
    assert.equal(
        sentValueText( { id: 'twitter', integrations: [ 'twitter' ] } ),
        ''
    );
} );
