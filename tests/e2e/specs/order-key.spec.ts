import { test, expect } from '../utils/fixtures';
import { CheckoutType, placeGuestOrder } from '../utils/checkout';
import { CapturedRequest } from '../utils/network';
import { enabledTags } from '../utils/platforms';

const checkouts: CheckoutType[] = ['classic', 'block'];

// Decodes a value a few times over, so a key encoded once or twice is still found.
const decoded = (value: string): string => {
    let out = value;

    for (let i = 0; i < 3; i++) {
        try {
            out = decodeURIComponent(out.replace(/\+/g, ' '));
        } catch {
            break;
        }
    }

    return out;
};

const leaks = (request: CapturedRequest): string[] =>
    (['url', 'postData', 'referer'] as const)
        .filter(field => {
            const value = decoded(request[field]);

            return /wc_order_/i.test(value) || /(^|[^a-z0-9_-])key=/i.test(value);
        })
        .map(field => `${request.platform} ${field}: ${request[field]} (page ${request.pageUrl})`);

for (const checkout of checkouts) {
    test(`no platform receives the order key from the ${checkout} thank-you page or the next page`, async ({ page, tracker }) => {
        const order = await placeGuestOrder(page, checkout);

        // The thank-you page still shows the order on the first load, and the order number stays in the path.
        await expect(page.locator('.woocommerce-order-overview__order')).toContainText(String(order.id));
        expect(page.url()).toContain(`/order-received/${order.id}/`);
        expect(page.url()).not.toContain('key=');
        expect(await page.evaluate(() => (window as any).wcctOrder)).toEqual({ id: order.id, key: order.key });

        for (const tag of enabledTags()) {
            await tracker.expectPurchase(tag);
        }

        const before = (await tracker.platformRequests()).length;

        // Follow a link, so the next page has the thank-you page as its referrer.
        await Promise.all([
            page.waitForURL(url => !url.pathname.includes('/order-received/')),
            page.evaluate(() => {
                const link = document.createElement('a');

                link.href = '/shop/';
                document.body.appendChild(link);
                link.click();
            }),
        ]);
        await page.waitForLoadState('load');

        // Every platform with a base code sends the next page view.
        await expect.poll(async () => new Set((await tracker.platformRequests()).slice(before).map(r => r.platform)).size).toBeGreaterThanOrEqual(3);

        const next = (await tracker.platformRequests()).slice(before);

        expect(next.some(r => decoded(r.url).includes(`/order-received/${order.id}/`)), 'the next page view carries the thank-you page as referrer').toBe(true);
        expect((await tracker.platformRequests()).flatMap(leaks)).toEqual([]);
    });
}

test('Google gets a clean page location on a page with order parameters but no order key', async ({ page, tracker }) => {
    // Not a thank-you page, so the address bar keeps its parameters and only Google's config cleans them.
    await page.goto('/shop/?order_id=7&key=ctleak&orderby=price&order=desc&utm_source=ct');

    const hits = async () =>
        (await tracker.platformRequests('google')).filter(r => /\/(g\/collect|viewthroughconversion)/.test(r.url));

    await expect.poll(async () => (await hits()).length).toBeGreaterThanOrEqual(2);

    const expected = '/shop/?orderby=price&order=desc&utm_source=ct';

    for (const hit of await hits()) {
        const location = hit.params.map(p => p.get('dl') ?? p.get('url') ?? '').find(Boolean) ?? '';

        expect(location, hit.url).toMatch(new RegExp(`${expected.replace(/[?.]/g, '\\$&')}$`));
        expect(leaks(hit)).toEqual([]);
    }
});

test('no platform receives an order key on any page whose URL carries one', async ({ page, tracker }) => {
    // A funnel thank-you page off WooCommerce's order received page.
    await page.goto('/shop/?ref=ct&order_key=wc_order_ctleak');
    await expect(page).toHaveURL(/\/shop\/\?ref=ct$/);

    await expect.poll(async () => new Set((await tracker.platformRequests()).map(r => r.platform)).size).toBeGreaterThanOrEqual(3);

    expect((await tracker.platformRequests()).flatMap(leaks)).toEqual([]);
    expect(await page.evaluate(() => (window as any).wcctOrder)).toEqual({ id: 0, key: 'wc_order_ctleak' });
});
