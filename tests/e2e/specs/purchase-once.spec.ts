import { Request } from '@playwright/test';
import { test, expect } from '../utils/fixtures';
import { placeGuestOrder } from '../utils/checkout';
import { enabledTags } from '../utils/platforms';

// Platform ID each tag checks in under on wcct/v1/events.
const CONFIRMATION: Record<string, string> = {
    meta: '&platform=facebook&loaded=1',
    ga4: '&platform=ga4&loaded=1',
    ads: '&platform=adwords&loaded=1',
    x: '&platform=twitter&loaded=1',
};

test('reloading the thank-you page sends no second Purchase', async ({ page, tracker }) => {
    const confirmations: string[] = [];

    // Answered confirmations only, so the flag is saved before the reload.
    page.on('requestfinished', (request: Request) => {
        if (request.url().includes('/wcct/v1/events') && request.method() === 'POST') {
            confirmations.push(request.postData() ?? '');
        }
    });

    const order = await placeGuestOrder(page, 'block');

    for (const tag of enabledTags()) {
        await tracker.expectPurchase(tag);

        // The browser checks in once the platform library has taken the event; only then is the order flagged.
        await expect
            .poll(() => confirmations.some(body => body.includes(CONFIRMATION[tag]) && body.startsWith(`order_id=${order.id}&`)), {
                message: `${tag} Purchase confirmation for order ${order.id}`,
                timeout: 15_000,
            })
            .toBe(true);
    }

    const before = Object.fromEntries(await Promise.all(enabledTags().map(async tag => [tag, (await tracker.purchaseHits(tag)).length])));

    // The address bar lost the key on load (#79), so open the thank-you URL with its key again, as a reload before #79 did.
    await page.goto(order.url);
    await expect(page.locator('.woocommerce-order-overview__order')).toContainText(String(order.id));
    await page.waitForLoadState('networkidle');

    for (const tag of enabledTags()) {
        expect((await tracker.purchaseHits(tag)).length, `${tag} Purchase hits after the reload`).toBe(before[tag]);
    }
});
