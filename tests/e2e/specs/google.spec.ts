import { Request } from '@playwright/test';
import { test, expect } from '../utils/fixtures';
import { NetworkTracker } from '../utils/network';
import { PRODUCT_PATH, placeGuestOrder } from '../utils/checkout';
import { TEST_IDS } from '../utils/platforms';

const GA4_ID = TEST_IDS.ga4?.measurementId ?? '';
const ADS_ID = TEST_IDS.ads?.conversionId ?? '';

// gtag.js loads and Ads remarketing hits (one per Ads config) seen so far.
const loaders = async (tracker: NetworkTracker) =>
    (await tracker.platformRequests('google')).filter(r => new URL(r.url).hostname === 'www.googletagmanager.com' && r.url.includes('/gtag/js'));
const adsConfigHits = async (tracker: NetworkTracker) =>
    (await tracker.platformRequests('google')).filter(r => r.url.includes(`/viewthroughconversion/${ADS_ID.replace(/^AW-/, '')}/`));

test.beforeEach(() => {
    test.skip(!GA4_ID || !ADS_ID, 'GA4 and Google Ads need test IDs in config/test-ids.json');
});

test('Analytics and Ads share one gtag.js loader with one config each', async ({ page, tracker }) => {
    await page.goto(PRODUCT_PATH);
    await page.waitForLoadState('networkidle');

    const loaded = await loaders(tracker);

    expect(loaded, 'gtag.js loads').toHaveLength(1);
    expect(new URL(loaded[0].url).searchParams.get('id'), 'the loader uses the Analytics ID').toBe(GA4_ID);
    await expect.poll(async () => (await tracker.eventHits('ga4', 'page_view')).length, { message: 'GA4 page_view' }).toBe(1);
    expect(await adsConfigHits(tracker), 'Ads config hits').toHaveLength(1);

    const configs = await page.evaluate(() =>
        ((window as unknown as { dataLayer: IArguments[] }).dataLayer || []).filter(a => a && a[0] === 'config').map(a => String(a[1]))
    );

    expect(configs.sort()).toEqual([ADS_ID, GA4_ID].sort());
});

test('a store search sends one GA4 search with the term; a blog search sends none', async ({ page, tracker }) => {
    await page.goto('/?s=cotton&post_type=product');
    await page.waitForLoadState('networkidle');

    await expect.poll(async () => (await tracker.eventHits('ga4', 'search')).length, { message: 'GA4 search' }).toBe(1);

    const [hit] = await tracker.eventHits('ga4', 'search');

    expect(hit.params.some(p => p.get('ep.search_term') === 'cotton')).toBe(true);

    await page.goto('/?s=cotton');
    await page.waitForLoadState('networkidle');
    expect(await tracker.eventHits('ga4', 'search')).toHaveLength(1);
});

test('the Ads conversion is sent once per order, also after a reload', async ({ page, tracker }) => {
    const confirmed: string[] = [];

    page.on('requestfinished', (request: Request) => {
        if (request.url().includes('admin-ajax.php') && request.method() === 'POST') {
            confirmed.push(request.postData() ?? '');
        }
    });

    const order = await placeGuestOrder(page, 'block');
    const conversions = async () => (await tracker.purchaseHits('ads')).filter(r => r.params.some(p => p.get('oid') === String(order.id)));

    await expect.poll(async () => (await conversions()).length, { message: 'Ads conversion' }).toBe(1);
    await expect
        .poll(() => confirmed.some(body => body.includes('name="platform"\r\n\r\nadwords') && body.includes(String(order.id))), {
            message: `Ads confirmation for order ${order.id}`,
            timeout: 15_000,
        })
        .toBe(true);

    // GA4's purchase carries send_to, so it never becomes a second Ads hit.
    expect((await tracker.purchaseHits('ads')).length).toBe(1);

    await page.goto(order.url);
    await expect(page.locator('.woocommerce-order-overview__order')).toContainText(String(order.id));
    await page.waitForLoadState('networkidle');
    await page.goto('/');
    await page.waitForLoadState('networkidle');

    expect(await conversions(), 'Ads conversions after a reload').toHaveLength(1);
});
