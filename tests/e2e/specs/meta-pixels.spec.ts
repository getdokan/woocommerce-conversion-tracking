import { Browser, Page } from '@playwright/test';
import { test, expect } from '../utils/fixtures';
import { CapturedRequest } from '../utils/network';
import { loginAsAdmin } from '../utils/admin';
import { PRODUCT_PATH, placeGuestOrder } from '../utils/checkout';
import { TEST_IDS } from '../utils/platforms';

// #88 and #90: a store configured through the 3.0 settings with three Meta pixels and custom parameters.
const MAIN = TEST_IDS.meta?.pixelId ?? '';
const SECOND = '222222222222222';
const THIRD = '333333333333333';
const PRO = 'wc-conversion-tracking-pro/woocommerce-conversion-tracking-pro.php';
const YITH = 'yith-woocommerce-wishlist/init.php';
// The seeded product has no category of its own.
const CATEGORY_PATH = '/product-category/uncategorized/';

const EVENT_ID = /^wcct_[0-9a-f-]{36}$/;

type State = { options: Record<string, unknown>; active: string[] };

// Admin REST calls with the admin's cookies: the test site's ct-e2e/v1 routes and the plugin's wcct/v1 routes.
async function rest<T>(page: Page, path: string, data?: object): Promise<T> {
    const nonce = (await (await page.request.get('/wp-admin/admin-ajax.php?action=rest-nonce')).text()).trim();
    const response = data
        ? await page.request.post(`/wp-json/${path}`, { headers: { 'X-WP-Nonce': nonce }, data })
        : await page.request.get(`/wp-json/${path}`, { headers: { 'X-WP-Nonce': nonce } });

    expect(response.ok(), await response.text()).toBe(true);

    return response.json();
}

async function asAdmin<T>(browser: Browser, work: (page: Page) => Promise<T>): Promise<T> {
    const context = await browser.newContext();
    const page = await context.newPage();

    try {
        await loginAsAdmin(page);

        return await work(page);
    } finally {
        await context.close();
    }
}

// Meta hits of one event, one entry per pixel request.
async function metaHits(tracker: { platformRequests: (platform?: 'meta') => Promise<CapturedRequest[]> }, event: string) {
    return (await tracker.platformRequests('meta')).flatMap(hit =>
        hit.params
            .filter(p => p.get('ev') === event)
            .map(p => ({
                pixel: p.get('id') ?? '',
                eid: p.get('eid') ?? '',
                data: Object.fromEntries([...p.entries()].filter(([key]) => key.startsWith('cd[')).map(([key, value]) => [key.slice(3, -1), value])),
            }))
    );
}

// fbq() calls the page made (recorded by the fbevents.js stand-in).
const fbqCalls = (page: Page): Promise<unknown[][]> => page.evaluate(() => (window as unknown as { ctE2eFbqCalls?: unknown[][] }).ctE2eFbqCalls ?? []);

// Each pixel gets the event once, through its own trackSingle call, all with one event ID.
async function expectOncePerPixel(page: Page, tracker: Parameters<typeof metaHits>[0], event: string, pixels: string[], method = 'trackSingle') {
    await expect.poll(async () => (await metaHits(tracker, event)).map(hit => hit.pixel).sort()).toEqual([...pixels].sort());

    const hits = await metaHits(tracker, event);
    const calls = (await fbqCalls(page)).filter(call => call[2] === event);

    expect(calls.map(call => call[0])).toEqual(pixels.map(() => method));
    expect(new Set(hits.map(hit => hit.eid)).size).toBe(1);

    return hits;
}

let pro = false;
let saved: State | null = null;

test.describe.configure({ mode: 'serial' });
test.skip(!MAIN, 'Meta has no test ID');

test.beforeAll(async ({ browser }) => {
    await asAdmin(browser, async page => {
        saved = await rest<State>(page, 'ct-e2e/v1/state');

        // Running Pro 3.0 adds Microsoft; a Pro left in active_plugins without its files doesn't.
        const current = await rest<{ platforms: Record<string, { events: string[] }> }>(page, 'wcct/v1/settings');

        pro = saved.active.includes(PRO) && 'bing_ads' in current.platforms;

        await rest(page, 'ct-e2e/v1/state', { activate: [YITH] });

        if (!pro) {
            return;
        }

        // Saved through the settings route, as the 3.0 Meta page saves them; the 2.x option never sees these.
        await rest(page, 'wcct/v1/settings', {
            platforms: {
                facebook: {
                    events: [...new Set([...current.platforms.facebook.events, 'view_category', 'add_to_wishlist'])],
                    pixels: [
                        { pixel_id: SECOND, events: ['view_product', 'view_category', 'add_to_wishlist'] },
                        { pixel_id: THIRD, events: ['purchase'] },
                    ],
                    custom_parameters: [
                        { event: 'view_product', name: 'sku', token: '{product_sku}' },
                        { event: 'view_category', name: 'category', token: '{product_category}' },
                        { event: 'purchase', name: 'customer', token: '{customer_type}' },
                    ],
                },
            },
        });
    });
});

// YITH's own wishlist lists request answers guests with 401.
test.beforeEach(({ jsErrors }) => jsErrors.allow(/status of 401 .*\/wp-json\/yith\/wishlist\//));

test.afterAll(async ({ browser }) => {
    await asAdmin(browser, async page => {
        await rest(page, 'ct-e2e/v1/state', {
            options: { wcct_settings: saved?.options.wcct_settings ?? null, wcct_settings_v3: saved?.options.wcct_settings_v3 ?? null },
            deactivate: [YITH],
            activate: pro ? [PRO] : [],
        });
    });
});

test.describe('with Pro', () => {
    test.beforeEach(() => test.skip(!pro, 'Pro is not active'));

    test('every pixel is initialised once and each gets only the events it has on, with one event ID per event', async ({ page, tracker }) => {
        await page.goto(PRODUCT_PATH);

        const init = (await fbqCalls(page)).filter(call => call[0] === 'init').map(call => call[1]);

        expect(init).toEqual([MAIN, SECOND, THIRD]);

        const views = await expectOncePerPixel(page, tracker, 'ViewContent', [MAIN, SECOND]);

        expect(views[0].eid).toMatch(EVENT_ID);
        expect(views.map(hit => hit.data.sku)).toEqual(['CT-E2E-SIMPLE', 'CT-E2E-SIMPLE']);

        // Pixel views: every initialised pixel; PageView isn't per pixel.
        expect((await metaHits(tracker, 'PageView')).map(hit => hit.pixel).sort()).toEqual([MAIN, SECOND, THIRD].sort());
    });

    test('View category goes to each pixel with it on, with its details and custom parameter', async ({ page, tracker }) => {
        await page.goto(CATEGORY_PATH);

        const hits = await expectOncePerPixel(page, tracker, 'ViewCategory', [MAIN, SECOND], 'trackSingleCustom');

        expect(hits[0].eid).toMatch(EVENT_ID);

        for (const hit of hits) {
            expect(hit.data.content_name).toBe('Uncategorized');
            expect(hit.data.content_category).toBe('Uncategorized');
            expect(hit.data.content_type).toBe('product');
            expect(JSON.parse(hit.data.content_ids)).toEqual(expect.arrayContaining([expect.stringMatching(/^\d+$/)]));
            expect(hit.data.category).toBe('Uncategorized');
        }
    });

    test('a pixel with Purchase off gets no Purchase, the others the same order-derived event ID', async ({ page, tracker }) => {
        const order = await placeGuestOrder(page, 'classic');
        const hits = await expectOncePerPixel(page, tracker, 'Purchase', [MAIN, THIRD]);

        expect(hits.map(hit => hit.eid)).toEqual([`wcct_${order.id}_purchase`, `wcct_${order.id}_purchase`]);
        expect(['new', 'returning']).toContain(hits[0].data.customer);
        expect(hits[0].data).toEqual(hits[1].data);
    });

    for (const rendering of ['php-templates', 'react-components']) {
        test(`a YITH add to wishlist (${rendering}) sends Add to wishlist once per pixel with the product ID`, async ({ page, tracker, baseURL }) => {
            await page.context().addCookies([{ name: 'ct_e2e_yith_rendering', value: rendering, url: baseURL! }]);

            // The React button ignores clicks until it has loaded the product's wishlist state.
            const loaded = rendering === 'react-components' ? page.waitForResponse(response => /\/yith\/wishlist\/v1\/products/.test(response.url())) : null;

            await page.goto(PRODUCT_PATH);

            const productId = await page.locator('button[name="add-to-cart"]').getAttribute('value');

            if (rendering === 'php-templates') {
                await page.locator('.yith-wcwl-add-to-wishlist a.add_to_wishlist').first().click();
            } else {
                await loaded;
                // A link or a button, depending on YITH's button style.
                await page.locator('.yith-wcwl-add-to-wishlist-button').first().click();
                await expect(page.locator('.yith-wcwl-add-to-wishlist-button--added').first()).toBeVisible();
            }

            const hits = await expectOncePerPixel(page, tracker, 'AddToWishlist', [MAIN, SECOND]);

            expect(hits[0].eid).toMatch(EVENT_ID);

            for (const hit of hits) {
                expect(JSON.parse(hit.data.content_ids)).toEqual([productId]);
            }

            // The next page view doesn't send it again.
            await page.goto(PRODUCT_PATH);
            await page.waitForTimeout(1500);
            expect(await metaHits(tracker, 'AddToWishlist')).toHaveLength(2);
        });
    }

    test('a WooCommerce Wishlists add sends Add to wishlist once per pixel after its redirect', async ({ page, tracker, baseURL }) => {
        await page.goto(PRODUCT_PATH);

        const productId = await page.locator('button[name="add-to-cart"]').getAttribute('value');

        // mu-plugins/ct-e2e-wishlist.php stands in for WooCommerce Wishlists' form post.
        await page.context().addCookies([{ name: 'ct_e2e_wc_wishlists', value: '1', url: baseURL! }]);
        await page.goto(`/?ct_e2e_wishlist_add=${productId}`);
        await expect(page).toHaveURL(new RegExp(PRODUCT_PATH));

        const hits = await expectOncePerPixel(page, tracker, 'AddToWishlist', [MAIN, SECOND]);

        expect(JSON.parse(hits[0].data.content_ids)).toEqual([productId]);
    });
});

test.describe('on Free', () => {
    test.beforeAll(async ({ browser }) => {
        if (pro) {
            await asAdmin(browser, page => rest(page, 'ct-e2e/v1/state', { deactivate: [PRO] }));
        }
    });

    test.afterAll(async ({ browser }) => {
        if (pro) {
            await asAdmin(browser, page => rest(page, 'ct-e2e/v1/state', { activate: [PRO] }));
        }
    });

    test('one pixel, no custom parameters, no View category and no Add to wishlist', async ({ page, tracker, baseURL }) => {
        await page.context().addCookies([{ name: 'ct_e2e_yith_rendering', value: 'php-templates', url: baseURL! }]);
        await page.goto(PRODUCT_PATH);

        expect((await fbqCalls(page)).filter(call => call[0] === 'init').map(call => call[1])).toEqual([MAIN]);

        const views = await expectOncePerPixel(page, tracker, 'ViewContent', [MAIN]);

        expect(views[0].data.sku).toBeUndefined();

        await page.locator('.yith-wcwl-add-to-wishlist a.add_to_wishlist').first().click();
        await expect(page.locator('.yith-wcwl-add-to-wishlist')).toContainText(/added|browse|view/i);
        await page.goto(CATEGORY_PATH);
        await page.waitForTimeout(1500);

        expect(await metaHits(tracker, 'AddToWishlist')).toEqual([]);
        expect(await metaHits(tracker, 'ViewCategory')).toEqual([]);
        expect((await tracker.platformRequests('meta')).some(hit => hit.params.some(p => p.get('id') === SECOND))).toBe(false);
    });
});
