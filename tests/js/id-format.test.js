/**
 * Tests for the ID format check in src/data/platformForm.ts: type and 40-character cap block, the typical length only warns. Run with `npm run test:js`.
 */
const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );

// Schema::id_pattern(): the type check, up to 40 characters.
const capped = ( type ) => `^(?=.{1,40}$)${ type }$`;

const META = {
    id: 'pixel_id',
    type: 'field',
    format: 'id',
    pattern: capped( '[0-9]+' ),
    typical_length: [ 15, 16 ],
};
const ADS = {
    id: 'ads_id',
    type: 'field',
    format: 'id',
    pattern: capped( 'AW-[0-9]+' ),
    case: 'upper',
    prefix: 'AW-',
};
const MEASUREMENT = {
    id: 'measurement_id',
    type: 'field',
    format: 'id',
    pattern: capped( 'GT?-[A-Z0-9]+' ),
    case: 'upper',
};

const load = () => import( '../../src/data/platformForm.ts' );

test( 'any length that fits the type saves, up to 40 characters', async () => {
    const { checkId } = await load();

    assert.equal( checkId( META, '1' ), 'valid' );
    assert.equal( checkId( META, '1234567890' ), 'valid' );
    assert.equal( checkId( META, '1'.repeat( 40 ) ), 'valid' );
    assert.equal( checkId( META, '1'.repeat( 41 ) ), 'invalid' );
    assert.equal( checkId( MEASUREMENT, 'G-' + 'A'.repeat( 38 ) ), 'valid' );
    assert.equal( checkId( MEASUREMENT, 'G-' + 'A'.repeat( 39 ) ), 'invalid' );
} );

test( 'the type stays strict: an access token is not a Pixel ID', async () => {
    const { checkId } = await load();

    assert.equal( checkId( META, 'EAAB1234567890' ), 'invalid' );
    assert.equal( checkId( MEASUREMENT, 'UA-1234-1' ), 'invalid' );
} );

test( 'outside the typical length is a soft warning, never for Google', async () => {
    const { unusualLength } = await load();

    assert.equal( unusualLength( META, '123456789012345' ), false );
    assert.equal( unusualLength( META, '1234567890123456' ), false );
    assert.equal( unusualLength( META, '1234567890' ), true );
    assert.equal( unusualLength( META, '12345678901234567' ), true );
    // Not the right type: an error, not a length warning.
    assert.equal( unusualLength( META, 'EAAB' ), false );
    assert.equal( unusualLength( META, '' ), false );
    assert.equal( unusualLength( MEASUREMENT, 'G-A' ), false );
} );

test( 'a bare Ads conversion ID gets AW- in front', async () => {
    const { checkId, cleanId, withPrefix } = await load();

    assert.equal( cleanId( ADS, ' 123456789 ' ), 'AW-123456789' );
    assert.equal( cleanId( ADS, 'aw-123456789' ), 'AW-123456789' );
    assert.equal( checkId( ADS, '123456789' ), 'valid' );
    assert.equal( cleanId( ADS, 'AW-123/label' ), 'AW-123/LABEL' );
    assert.equal( checkId( ADS, 'AW-123/label' ), 'invalid' );
    // Only where the prefix makes it fit.
    assert.equal( withPrefix( 'AW-', ADS.pattern, 'G-123' ), 'G-123' );
    assert.equal( withPrefix( '', ADS.pattern, '123' ), '123' );
} );

test( 'the hint describes the format, never ownership', async () => {
    const { formatLooksRight, unusualLengthMessage } = await load();

    assert.equal(
        formatLooksRight( 'Meta' ),
        'Format looks right. Meta confirms it once events arrive.'
    );
    assert.equal( unusualLengthMessage(), 'Unusual length, double-check it.' );
} );
