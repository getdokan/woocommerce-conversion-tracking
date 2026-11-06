import { test, expect } from '../utils/fixtures';
import { BASE_URL } from '../utils/site';
import { GUEST, addProductToCart } from '../utils/checkout';

const signUpHits = async (tracker: import('../utils/network').NetworkTracker) => (await tracker.eventHits('meta', 'CompleteRegistration')).length;

const uniqueEmail = (label: string) => `ct-${label}-${Date.now()}@example.com`;

test('signing up on My account sends one CompleteRegistration', async ({ page, tracker }) => {
    await page.goto('/my-account/');
    await page.locator('#reg_email').fill(uniqueEmail('account'));
    await page.locator('button[name="register"]').click();
    await expect(page.locator('.woocommerce-MyAccount-navigation')).toBeVisible();

    await expect.poll(() => signUpHits(tracker), { message: 'Meta CompleteRegistration hits' }).toBe(1);

    // The flag is cleared: the next page view sends nothing.
    await page.goto('/shop/');
    await page.waitForLoadState('networkidle');
    expect(await signUpHits(tracker)).toBe(1);
});

test('creating an account at checkout sends one CompleteRegistration', async ({ page, tracker }) => {
    await page.context().addCookies([{ name: 'ct_e2e_checkout', value: 'classic', url: BASE_URL }]);
    await addProductToCart(page);
    await page.goto('/classic-checkout/');
    await page.locator('#billing_first_name').fill(GUEST.firstName);
    await page.locator('#billing_last_name').fill(GUEST.lastName);
    await page.locator('#billing_address_1').fill(GUEST.address);
    await page.locator('#billing_city').fill(GUEST.city);
    await page.locator('#billing_state').selectOption(GUEST.state, { force: true });
    await page.locator('#billing_postcode').fill(GUEST.postcode);
    await page.locator('#billing_phone').fill(GUEST.phone);
    await page.locator('#billing_email').fill(uniqueEmail('checkout'));
    await page.locator('#createaccount').check();
    await expect(page.locator('.blockUI')).toHaveCount(0);
    await page.locator('#payment_method_cod').check({ force: true });
    await page.locator('#place_order').click();
    await page.waitForURL(/\/order-received\/\d+\//, { timeout: 30_000 });

    await expect.poll(() => signUpHits(tracker), { message: 'Meta CompleteRegistration hits' }).toBe(1);

    await page.goto('/shop/');
    await page.waitForLoadState('networkidle');
    expect(await signUpHits(tracker)).toBe(1);
});
