import { readFileSync } from 'fs';
import { join } from 'path';
import { test, expect } from '../utils/fixtures';
import { CapturedRequest, NetworkTracker } from '../utils/network';
import { addProductToCart, placeGuestOrder } from '../utils/checkout';
import { isProActive, loginAsAdmin, openApp } from '../utils/admin';
import { BASE_URL } from '../utils/site';
import { TEST_IDS } from '../utils/platforms';

// X and Microsoft IDs setup/seed.php saves when Pro is active.
const CHECKOUT_IDS: { xCheckoutEventId: string; microsoftTagId: string } = JSON.parse(
    readFileSync(join(__dirname, '..', 'config', 'checkout-ids.json'), 'utf8')
);

const isMetaStart = (r: CapturedRequest) =>
    r.platform === 'meta' && r.params.some(p => p.get('ev') === 'InitiateCheckout' && p.get('id') === TEST_IDS.meta?.pixelId);
const isGa4Start = (r: CapturedRequest) =>
    r.platform === 'google' && r.params.some(p => p.get('en') === 'begin_checkout' && p.get('tid') === TEST_IDS.ga4?.measurementId);
const isXStart = (r: CapturedRequest) =>
    r.platform === 'x' && r.params.some(p => p.get('txn_id') === CHECKOUT_IDS.xCheckoutEventId);
const isMicrosoftStart = (r: CapturedRequest) =>
    r.platform === 'microsoft' && r.params.some(p => p.get('ea') === 'begin_checkout' && p.get('ti') === CHECKOUT_IDS.microsoftTagId);

// Hits sent from pages whose path contains the given text.
const hitsOn = async (tracker: NetworkTracker, path: string, match: (r: CapturedRequest) => boolean) =>
    (await tracker.platformRequests()).filter(r => new URL(r.pageUrl || 'http://x/').pathname.includes(path) && match(r));

// Waits until the page sent at least one matching hit and returns them all, so callers can check there is exactly one.
const expectOnce = async (tracker: NetworkTracker, path: string, match: (r: CapturedRequest) => boolean, what: string) => {
    await expect.poll(async () => (await hitsOn(tracker, path, match)).length, { message: what, timeout: 15_000 }).toBeGreaterThan(0);

    return hitsOn(tracker, path, match);
};

let proActive = false;

test.beforeAll(async ({ browser }) => {
    const page = await browser.newPage({ baseURL: BASE_URL });

    await loginAsAdmin(page);
    await openApp(page);
    proActive = await isProActive(page);
    await page.close();
});

for (const checkout of ['block', 'classic'] as const) {
    test(`${checkout} checkout fires Start checkout once on Meta, GA4 and, with Pro, Microsoft and X`, async ({ page, tracker }) => {
        const path = checkout === 'block' ? '/checkout/' : '/classic-checkout/';

        await addProductToCart(page);
        await page.goto(path);
        await page.waitForLoadState('networkidle');

        const [meta, ...moreMeta] = await expectOnce(tracker, path, isMetaStart, 'Meta InitiateCheckout');
        const params = meta.params.find(p => p.get('ev') === 'InitiateCheckout')!;

        expect(moreMeta).toHaveLength(0);
        // Meta Ads Data Advisor's InitiateCheckout parameters. Pro's 1.1.4 wcfbq beacon drops numbers, so until #84 replaces it Pro sends only the strings.
        const metaKeys = proActive ? ['content_ids', 'currency'] : ['content_ids', 'num_items', 'value', 'currency'];

        for (const key of metaKeys) {
            expect(params.get(`cd[${key}]`), key).toBeTruthy();
        }
        expect(params.get('cd[currency]')).toBe('USD');

        expect(await expectOnce(tracker, path, isGa4Start, 'GA4 begin_checkout')).toHaveLength(1);

        if (!proActive) {
            return;
        }

        expect(await expectOnce(tracker, path, isMicrosoftStart, 'UET begin_checkout')).toHaveLength(1);

        const [x, ...moreX] = await expectOnce(tracker, path, isXStart, `X twq('event', '${CHECKOUT_IDS.xCheckoutEventId}')`);
        const event = JSON.parse(x.params[0].get('event') ?? '{}');

        expect(moreX).toHaveLength(0);
        expect(event).toMatchObject({ currency: 'USD', value: 25 });
        expect(event.contents).toHaveLength(1);
    });
}

test('the thank-you page fires no Start checkout', async ({ page, tracker }) => {
    await placeGuestOrder(page, 'block');
    // The Purchase hit shows the thank-you page's tags have run.
    await tracker.expectPurchase('meta');
    await page.waitForLoadState('networkidle');

    for (const match of [isMetaStart, isGa4Start, isXStart, isMicrosoftStart]) {
        expect(await hitsOn(tracker, '/order-received/', match)).toHaveLength(0);
    }
});
