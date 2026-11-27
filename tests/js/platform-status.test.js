/**
 * Tests for the platform pages' status in src/data/platformForm.ts (#140): the Dashboard card's state once wcct/v1/settings/status sends it. Run with `npm run test:js`.
 */
const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );

const load = () => import( '../../src/data/platformForm.ts' );

const twitter = {
    id: 'twitter',
    schema: [],
};

test( 'the server status decides, both ways', async () => {
    const { needsAttention } = await load();

    assert.equal(
        needsAttention(
            twitter,
            {},
            { state: 'attention', reason: 'finding', note: null }
        ),
        true
    );
    // A Google split the merchant already checked: the stale flag on the values doesn't win.
    assert.equal(
        needsAttention(
            twitter,
            { ads_id_split: { saved: 'AW-1/x' } },
            { state: 'working', reason: '', note: null }
        ),
        false
    );
} );

test( 'without a status, the page falls back to its own checks', async () => {
    const { needsAttention } = await load();

    assert.equal(
        needsAttention( twitter, { ads_id_split: { saved: 'x' } } ),
        true
    );
    assert.equal( needsAttention( twitter, {} ), false );
} );
