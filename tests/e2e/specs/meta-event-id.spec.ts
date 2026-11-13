import { test, expect } from '../utils/fixtures';
import { PRODUCT_PATH, placeGuestOrder } from '../utils/checkout';
import { TEST_IDS } from '../utils/platforms';

const pixelId = TEST_IDS.meta?.pixelId ?? '';

test.skip(!pixelId, 'Meta has no test ID');

test('Meta Purchase goes through fbq once, with the order-derived event ID', async ({ page, tracker }) => {
    const order = await placeGuestOrder(page, 'classic');
    const hits = await tracker.expectPurchase('meta');

    // Give the fallback's load check time to run; it must stay quiet when fbq loaded.
    await page.waitForTimeout(1500);

    const purchases = (await tracker.purchaseHits('meta')).flatMap(hit => hit.params.filter(p => p.get('ev') === 'Purchase'));

    expect(hits.length).toBeGreaterThan(0);
    expect(purchases).toHaveLength(1);
    expect(purchases[0].get('eid')).toBe(`wcct_${order.id}_purchase`);
    expect(purchases[0].get('event_id')).toBeNull();
    expect(JSON.parse(purchases[0].get('cd[content_ids]') ?? 'null')).toEqual(expect.arrayContaining([expect.any(String)]));
});

test('when fbevents.js is blocked the image fallback sends Purchase once, with the same event ID', async ({ page, context, tracker, jsErrors }) => {
    jsErrors.allow(/fbevents\.js|ERR_FAILED|Failed to load resource/);
    // Registered after the tracker's route, so it runs first.
    await context.route(/connect\.facebook\.net\/.*fbevents\.js/, route => route.abort());

    const order = await placeGuestOrder(page, 'block');

    await tracker.expectPurchase('meta');
    await page.waitForTimeout(1500);

    const purchases = (await tracker.purchaseHits('meta')).flatMap(hit => hit.params.filter(p => p.get('ev') === 'Purchase'));

    expect(purchases).toHaveLength(1);
    expect(purchases[0].get('eid')).toBe(`wcct_${order.id}_purchase`);
    expect(purchases[0].get('event_id')).toBe(`wcct_${order.id}_purchase`);
    expect(purchases[0].get('v')).toBe('2.9.435');
    expect(purchases[0].get('dl')).not.toContain('key=');
});

test('two visits to the same cached product page send different PageView and ViewContent event IDs', async ({ page, tracker }) => {
    const html = await (await page.request.get(PRODUCT_PATH)).text();

    // Serve the same HTML on every visit, as a page cache does.
    await page.route(url => url.pathname === PRODUCT_PATH, route => route.fulfill({ contentType: 'text/html', body: html }));

    const eids = async (event: string) =>
        (await tracker.platformRequests('meta')).flatMap(hit => hit.params.filter(p => p.get('ev') === event).map(p => p.get('eid')));

    for (let visit = 1; visit <= 2; visit++) {
        await page.goto(PRODUCT_PATH);
        await expect.poll(async () => (await eids('ViewContent')).length).toBe(visit);
        await expect.poll(async () => (await eids('PageView')).length).toBe(visit);
    }

    for (const event of ['PageView', 'ViewContent']) {
        const ids = await eids(event);

        expect(ids[0], event).toMatch(/^wcct_[0-9a-f-]{36}$/);
        expect(ids[1], event).toMatch(/^wcct_[0-9a-f-]{36}$/);
        expect(ids[0], event).not.toBe(ids[1]);
    }
});

test('with Respect cookie choices on and fbevents.js blocked, no Meta image goes out before Accept, then Purchase goes out once', async ({ page, context, tracker, jsErrors, baseURL }) => {
    jsErrors.allow(/fbevents\.js|ERR_FAILED|Failed to load resource/);
    await context.addCookies([{ name: 'ct_e2e_consent', value: 'optin', url: baseURL! }]);
    await context.route(/connect\.facebook\.net\/.*fbevents\.js/, route => route.abort());

    const order = await placeGuestOrder(page, 'classic');

    const images = async () => (await tracker.platformRequests('meta')).filter(r => /\/tr\/?$/.test(new URL(r.url).pathname)).map(r => r.url);

    // Past the fallback's wait on the thank-you page, still without consent: not one /tr request on any page.
    await page.waitForTimeout(2500);
    expect(await images()).toEqual([]);

    await page.evaluate(() => (window as unknown as { wp_set_consent: (c: string, v: string) => void }).wp_set_consent('marketing', 'allow'));
    await tracker.expectPurchase('meta');
    await page.waitForTimeout(1500);

    const purchases = (await tracker.purchaseHits('meta')).flatMap(hit => hit.params.filter(p => p.get('ev') === 'Purchase'));

    expect(purchases).toHaveLength(1);
    expect(purchases[0].get('eid')).toBe(`wcct_${order.id}_purchase`);
});
