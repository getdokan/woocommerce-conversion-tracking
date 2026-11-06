import { Page, expect } from '@playwright/test';
import { BASE_URL } from './site';

export type CheckoutType = 'classic' | 'block';

export interface PlacedOrder {
    id: number;
    key: string;
    // Thank-you page URL as requested, with the order key.
    url: string;
}

// Seeded by setup/seed.php.
export const PRODUCT_PATH = '/product/ct-e2e-product/';

export const GUEST = {
    firstName: 'Casey',
    lastName: 'Tester',
    address: '1 Market Street',
    city: 'San Francisco',
    state: 'CA',
    postcode: '94105',
    phone: '4155550100',
    email: 'guest@example.com',
};

export const addProductToCart = async (page: Page, productPath = PRODUCT_PATH): Promise<void> => {
    await page.goto(productPath);
    await page.locator('button[name="add-to-cart"]').click();
    await expect(page.locator('.woocommerce-message')).toContainText('has been added to your cart');
};

const fillClassicCheckout = async (page: Page): Promise<void> => {
    await page.goto('/classic-checkout/');
    await page.locator('#billing_first_name').fill(GUEST.firstName);
    await page.locator('#billing_last_name').fill(GUEST.lastName);
    await page.locator('#billing_address_1').fill(GUEST.address);
    await page.locator('#billing_city').fill(GUEST.city);
    await page.locator('#billing_state').selectOption(GUEST.state, { force: true });
    await page.locator('#billing_postcode').fill(GUEST.postcode);
    await page.locator('#billing_phone').fill(GUEST.phone);
    await page.locator('#billing_email').fill(GUEST.email);
    // Wait for the payment section the checkout refreshes over AJAX after an address change.
    await expect(page.locator('.blockUI')).toHaveCount(0);
    await page.locator('#payment_method_cod').check({ force: true });
    await page.locator('#place_order').click();
};

const fillBlockCheckout = async (page: Page): Promise<void> => {
    await page.goto('/checkout/');
    await page.locator('#email').fill(GUEST.email);
    await page.locator('#billing-first_name').fill(GUEST.firstName);
    await page.locator('#billing-last_name').fill(GUEST.lastName);
    await page.locator('#billing-address_1').fill(GUEST.address);
    await page.locator('#billing-city').fill(GUEST.city);
    await page.locator('#billing-state').selectOption(GUEST.state);
    await page.locator('#billing-postcode').fill(GUEST.postcode);
    await page.locator('#billing-phone').fill(GUEST.phone);
    await page.locator('#radio-control-wc-payment-method-options-cod').check();
    await page.locator('.wc-block-components-checkout-place-order-button').click();
};

// Places a guest order paid by cash on delivery and waits for the thank-you page.
export const placeGuestOrder = async (page: Page, checkout: CheckoutType): Promise<PlacedOrder> => {
    // The mu-plugin makes the shortcode page the checkout page for this browser, so the order lands there.
    if (checkout === 'classic') {
        await page.context().addCookies([{ name: 'ct_e2e_checkout', value: 'classic', url: BASE_URL }]);
    }

    await addProductToCart(page);

    // The plugin removes the key from the address bar on load, so read the URL from the request.
    const thankYou = page.waitForRequest(
        request => request.isNavigationRequest() && /\/order-received\/\d+\/\?.*key=wc_order_/.test(request.url()),
        { timeout: 30_000 }
    );

    if (checkout === 'classic') {
        await fillClassicCheckout(page);
    } else {
        await fillBlockCheckout(page);
    }

    const url = new URL((await thankYou).url());

    await page.waitForURL(/\/order-received\/\d+\//, { timeout: 30_000 });
    await page.waitForLoadState('load');

    return {
        id: Number(url.pathname.match(/\/order-received\/(\d+)/)![1]),
        key: url.searchParams.get('key')!,
        url: url.toString(),
    };
};
