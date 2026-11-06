import { Request } from '@playwright/test';
import { test, expect } from '../utils/fixtures';
import { NetworkTracker } from '../utils/network';
import { BASE_URL } from '../utils/site';
import { PRODUCT_PATH, placeGuestOrder } from '../utils/checkout';

// Hits the Custom scripts send, per placement; the mu-plugin saves them when the cookie is set.
const customHits = (tracker: NetworkTracker, placement: 'header' | 'thankyou' | 'signup'): URL[] =>
    tracker.external.map(url => new URL(url)).filter(url => url.hostname === 'custom.ct-e2e.test' && url.pathname === `/${placement}`);

test.beforeEach(async ({ context }) => {
    await context.addCookies([{ name: 'ct_e2e_custom_scripts', value: '1', url: BASE_URL }]);
});

test('the header script runs once on every page, with its placeholders empty', async ({ page, tracker }) => {
    const paths = ['/', '/shop/', PRODUCT_PATH, '/cart/', '/my-account/'];

    for (const path of paths) {
        await page.goto(path);
        await expect.poll(() => customHits(tracker, 'header').filter(url => url.searchParams.get('page') === path).length, { message: `header hit on ${path}` }).toBe(1);
    }

    expect(customHits(tracker, 'header')).toHaveLength(paths.length);

    for (const hit of customHits(tracker, 'header')) {
        expect(hit.searchParams.get('order')).toBe('');
        expect(hit.searchParams.get('email')).toBe('');
    }
});

test('the thank-you script runs once per order with the order placeholders', async ({ page, tracker }) => {
    const confirmations: string[] = [];

    page.on('requestfinished', (request: Request) => {
        if (request.url().includes('/wcct/v1/events') && request.method() === 'POST') {
            confirmations.push(request.postData() ?? '');
        }
    });

    const order = await placeGuestOrder(page, 'block');

    await expect.poll(() => customHits(tracker, 'thankyou').length, { message: 'thank-you hits' }).toBe(1);

    const [hit] = customHits(tracker, 'thankyou');

    expect(hit.searchParams.get('order')).toBe(String(order.id));
    expect(hit.searchParams.get('id')).toBe(String(order.id));
    expect(hit.searchParams.get('currency')).toBe('USD');
    expect(hit.searchParams.get('payment')).toBe('cod');
    expect(Number(hit.searchParams.get('total'))).toBeGreaterThan(0);
    // A guest order shares no customer details.
    expect(hit.searchParams.get('email')).toBe('');

    await expect
        .poll(() => confirmations.some(body => body.includes('&platform=custom&loaded=1') && body.startsWith(`order_id=${order.id}&`)), {
            message: `Custom Purchase confirmation for order ${order.id}`,
            timeout: 15_000,
        })
        .toBe(true);

    const headers = customHits(tracker, 'header').length;

    // The address bar lost the key on load, so open the thank-you URL with its key again.
    await page.goto(order.url);
    await expect(page.locator('.woocommerce-order-overview__order')).toContainText(String(order.id));
    await page.waitForLoadState('networkidle');

    expect(customHits(tracker, 'header').length).toBe(headers + 1);
    expect(customHits(tracker, 'thankyou')).toHaveLength(1);
});

test('the sign-up script runs once per new account', async ({ page, tracker }) => {
    await page.goto('/my-account/');
    await page.locator('#reg_email').fill(`ct-custom-${Date.now()}@example.com`);
    await page.locator('button[name="register"]').click();
    await expect(page.locator('.woocommerce-MyAccount-navigation')).toBeVisible();

    await expect.poll(() => customHits(tracker, 'signup').length, { message: 'sign-up hits' }).toBe(1);

    // Customer placeholders never fill outside the thank-you page.
    expect(customHits(tracker, 'signup')[0].searchParams.get('customer')).toBe('');
    expect(customHits(tracker, 'signup')[0].searchParams.get('email')).toBe('');

    await page.goto('/shop/');
    await page.waitForLoadState('networkidle');
    expect(customHits(tracker, 'signup')).toHaveLength(1);
});
