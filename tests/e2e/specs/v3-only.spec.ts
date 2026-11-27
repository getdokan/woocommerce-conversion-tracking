import { APIRequestContext, Page } from '@playwright/test';
import { test, expect } from '../utils/fixtures';
import { placeGuestOrder } from '../utils/checkout';
import { TEST_IDS, enabledTags } from '../utils/platforms';
import { BASE_URL } from '../utils/site';
import { isProActive, loginAsAdmin, openApp } from '../utils/admin';

// A store set up only through wcct/v1/settings, as the wizard and the Platforms pages leave it: no 2.x wcct_settings.

const STATE_OPTIONS = ['wcct_settings', 'wcct_settings_v3', 'wcct_paused_platforms'];
const MICROSOFT_TAG = '12345678';

type SiteState = { options: Record<string, unknown> };

let saved: Record<string, unknown> = {};
let proActive = false;

const nonceOf = async (page: Page): Promise<string> => (await (await page.request.get('/wp-admin/admin-ajax.php?action=rest-nonce')).text()).trim();

// The test site's ct-e2e/v1/state route (mu-plugins/ct-e2e-helpers.php).
async function siteState(page: Page, body?: object): Promise<SiteState> {
    const headers = { 'X-WP-Nonce': await nonceOf(page) };
    const response = body ? await page.request.post('/wp-json/ct-e2e/v1/state', { headers, data: body }) : await page.request.get('/wp-json/ct-e2e/v1/state', { headers });

    expect(response.ok(), await response.text()).toBe(true);

    return response.json();
}

// The 3.0 settings of every tag with a test ID, plus Microsoft with Pro.
const platforms = (): Record<string, Record<string, unknown>> => {
    const values: Record<string, Record<string, unknown>> = {};

    if (TEST_IDS.meta) {
        values.facebook = { pixel_id: TEST_IDS.meta.pixelId, events: ['view_product', 'add_to_cart', 'begin_checkout', 'purchase'] };
    }

    if (TEST_IDS.ga4 || TEST_IDS.ads) {
        values.google = {
            mode: TEST_IDS.ga4 && TEST_IDS.ads ? 'both' : TEST_IDS.ga4 ? 'analytics' : 'ads',
            measurement_id: TEST_IDS.ga4?.measurementId ?? '',
            ads_id: TEST_IDS.ads?.conversionId ?? '',
            purchase_label: TEST_IDS.ads?.purchaseLabel ?? '',
            events: ['purchase'],
        };
    }

    if (TEST_IDS.x) {
        values.twitter = { pixel_id: TEST_IDS.x.pixelId, purchase_event_id: TEST_IDS.x.purchaseEventId, events: ['page_view', 'purchase'] };
    }

    if (proActive) {
        values.bing_ads = { tag_id: MICROSOFT_TAG, events: ['page_view', 'purchase'] };
    }

    return values;
};

const PLATFORM: Record<string, string> = { meta: 'facebook', ga4: 'ga4', ads: 'adwords', x: 'twitter' };

const orderLog = async (request: APIRequestContext, orderId: number): Promise<{ rows: { platform: string; library_loaded: string }[] }> => {
    const response = await request.get(`/?rest_route=/ct-e2e/v1/order-log/${orderId}`);

    expect(response.ok()).toBe(true);

    return response.json();
};

test.describe.configure({ mode: 'serial' });

test.beforeAll(async ({ browser }) => {
    const page = await browser.newPage({ baseURL: BASE_URL });

    await loginAsAdmin(page);
    await openApp(page);
    proActive = await isProActive(page);

    const state = await siteState(page);

    saved = Object.fromEntries(STATE_OPTIONS.map(name => [name, state.options[name] ?? null]));

    // A fresh store: no 2.x settings, no 3.0 settings, nothing paused.
    await siteState(page, { options: Object.fromEntries(STATE_OPTIONS.map(name => [name, null])) });

    const response = await page.request.post('/wp-json/wcct/v1/settings', { headers: { 'X-WP-Nonce': await nonceOf(page) }, data: { platforms: platforms() } });

    expect(response.ok(), await response.text()).toBe(true);

    const after = await siteState(page);

    expect(after.options.wcct_settings).toBeNull();
    expect(after.options.wcct_settings_v3).not.toBeNull();
    await page.close();
});

test.afterAll(async ({ browser }) => {
    const page = await browser.newPage({ baseURL: BASE_URL });

    await loginAsAdmin(page);
    await siteState(page, { options: saved });
    await page.close();
});

test('a store set up only through the 3.0 settings loads every pixel and sends the Purchase', async ({ page, tracker }) => {
    await page.goto('/');

    if (TEST_IDS.meta) {
        await expect.poll(async () => (await tracker.eventHits('meta', 'PageView')).length, { message: 'Meta PageView' }).toBeGreaterThan(0);
    }

    if (TEST_IDS.x) {
        await expect.poll(async () => (await tracker.platformRequests('x')).length, { message: 'X base tag' }).toBeGreaterThan(0);
    }

    if (proActive) {
        await expect.poll(async () => (await tracker.platformRequests('microsoft')).length, { message: 'Microsoft UET tag' }).toBeGreaterThan(0);
    }

    await placeGuestOrder(page, 'block');

    for (const tag of enabledTags()) {
        await tracker.expectPurchase(tag);
    }
});

test('a Purchase on a 3.0-only store checks in once per platform', async ({ page, request }) => {
    const order = await placeGuestOrder(page, 'block');
    const expected = [...enabledTags().map(tag => PLATFORM[tag]), ...(proActive ? ['bing_ads'] : [])].sort();

    await expect
        .poll(async () => (await orderLog(request, order.id)).rows.filter(row => row.library_loaded === '1').map(row => row.platform).sort(), {
            message: `one handed-over row per platform for order ${order.id}`,
            timeout: 15_000,
        })
        .toEqual(expected);
});
