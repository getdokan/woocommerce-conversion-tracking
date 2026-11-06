import { Request } from '@playwright/test';
import { test, expect } from '../utils/fixtures';
import { placeGuestOrder } from '../utils/checkout';
import { enabledTags } from '../utils/platforms';

// Platform ID each tag confirms its Purchase under (GA4 keeps its own AJAX action).
const CONFIRMATION: Record<string, string> = {
    meta: 'name="platform"\r\n\r\nfacebook',
    ga4: 'wcct_ga4_purchase_sent',
};

test('reloading the thank-you page sends no second Purchase', async ({ page, tracker }) => {
    const confirmations: string[] = [];

    // Answered confirmations only, so the flag is saved before the reload.
    page.on('requestfinished', (request: Request) => {
        if (request.url().includes('admin-ajax.php') && request.method() === 'POST') {
            confirmations.push(request.postData() ?? '');
        }
    });

    const order = await placeGuestOrder(page, 'block');

    for (const tag of enabledTags()) {
        await tracker.expectPurchase(tag);

        // The browser confirms once the platform library has taken the event; only then is the order flagged.
        await expect
            .poll(() => confirmations.some(body => body.includes(CONFIRMATION[tag]) && body.includes(String(order.id))), {
                message: `${tag} Purchase confirmation for order ${order.id}`,
                timeout: 15_000,
            })
            .toBe(true);
    }

    const before = Object.fromEntries(await Promise.all(enabledTags().map(async tag => [tag, (await tracker.purchaseHits(tag)).length])));

    await page.reload();
    await expect(page.locator('.woocommerce-order-overview__order')).toContainText(String(order.id));
    await page.waitForLoadState('networkidle');

    for (const tag of enabledTags()) {
        expect((await tracker.purchaseHits(tag)).length, `${tag} Purchase hits after the reload`).toBe(before[tag]);
    }
});
