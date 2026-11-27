import { test, expect } from '../utils/fixtures';
import { CheckoutType, placeGuestOrder } from '../utils/checkout';
import { enabledTags } from '../utils/platforms';

const checkouts: CheckoutType[] = ['classic', 'block'];

for (const checkout of checkouts) {
    test(`guest order on ${checkout} checkout sends a Purchase hit to every enabled platform`, async ({ page, tracker }) => {
        const order = await placeGuestOrder(page, checkout);

        await expect(page.locator('.woocommerce-order-overview__order')).toContainText(String(order.id));

        for (const tag of enabledTags()) {
            await tracker.expectPurchase(tag);
        }
    });
}
