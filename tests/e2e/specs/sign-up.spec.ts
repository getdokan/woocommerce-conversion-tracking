import { test, expect } from '../utils/fixtures';
import { BASE_URL } from '../utils/site';
import { GUEST, addProductToCart } from '../utils/checkout';
import { isProActive, loginAsAdmin, openApp } from '../utils/admin';
import { NetworkTracker } from '../utils/network';
import { TEST_IDS } from '../utils/platforms';

const signUpHits = async (tracker: NetworkTracker) => (await tracker.eventHits('meta', 'CompleteRegistration')).length;

// GA4 sign_up, and with Pro the Ads conversion under the Registration label (#87).
const ga4SignUps = async (tracker: NetworkTracker) => (await tracker.eventHits('ga4', 'sign_up')).length;
const adsSignUps = async (tracker: NetworkTracker) => (await tracker.eventHits('ads', TEST_IDS.ads?.registrationLabel ?? '')).length;

let proActive = false;

test.beforeAll(async ({ browser }) => {
    const page = await browser.newPage({ baseURL: BASE_URL });

    await loginAsAdmin(page);
    await openApp(page);
    proActive = await isProActive(page);
    await page.close();
});

// One Google sign-up per new account: GA4 sign_up, plus the Ads registration conversion with Pro.
const expectGoogleSignUps = async (tracker: NetworkTracker) => {
    await expect.poll(() => ga4SignUps(tracker), { message: 'GA4 sign_up hits' }).toBe(1);
    await expect.poll(() => adsSignUps(tracker), { message: 'Ads registration conversions' }).toBe(proActive ? 1 : 0);
};

const uniqueEmail = (label: string) => `ct-${label}-${Date.now()}@example.com`;

test('signing up on My account sends one CompleteRegistration and one Google sign-up', async ({ page, tracker }) => {
    await page.goto('/my-account/');
    await page.locator('#reg_email').fill(uniqueEmail('account'));
    await page.locator('button[name="register"]').click();
    await expect(page.locator('.woocommerce-MyAccount-navigation')).toBeVisible();

    await expect.poll(() => signUpHits(tracker), { message: 'Meta CompleteRegistration hits' }).toBe(1);
    await expectGoogleSignUps(tracker);

    // The flag is cleared: the next page view sends nothing.
    await page.goto('/shop/');
    await page.waitForLoadState('networkidle');
    expect(await signUpHits(tracker)).toBe(1);
    await expectGoogleSignUps(tracker);
});

test('creating an account at checkout sends one CompleteRegistration and one Google sign-up', async ({ page, tracker }) => {
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
    await expectGoogleSignUps(tracker);

    await page.goto('/shop/');
    await page.waitForLoadState('networkidle');
    expect(await signUpHits(tracker)).toBe(1);
    await expectGoogleSignUps(tracker);
});
