import { Page } from '@playwright/test';
import { test, expect } from '../utils/fixtures';
import { addProductToCart } from '../utils/checkout';
import { NetworkTracker } from '../utils/network';
import { Platform, Tag, enabledTags } from '../utils/platforms';

// Each platform's own name for the add to cart event (X's is Pro, Google Ads has none).
const ADD_TO_CART: Partial<Record<Tag, string>> = { meta: 'AddToCart', ga4: 'add_to_cart' };

// The platform host each tag's requests go to.
const PLATFORM: Partial<Record<Tag, Platform>> = { meta: 'meta', ga4: 'google' };

const cartTags = (): Tag[] => enabledTags().filter(tag => tag in ADD_TO_CART);

const expectOneAddToCart = async (tracker: NetworkTracker) => {
    for (const tag of cartTags()) {
        await expect
            .poll(async () => (await tracker.eventHits(tag, ADD_TO_CART[tag]!)).length, { message: `${tag} AddToCart hits` })
            .toBe(1);
    }
};

// Another page view, so an event sent twice (on the page and again from the session) would show up.
const viewAnotherPage = async (page: Page) => {
    await page.goto('/');
    await page.waitForLoadState('networkidle');
};

test('the add to cart form sends one AddToCart per platform', async ({ page, tracker }) => {
    await addProductToCart(page);
    await expectOneAddToCart(tracker);
    await viewAnotherPage(page);
    await expectOneAddToCart(tracker);
});

test('an AJAX button on the shop page sends one AddToCart per platform', async ({ page, tracker }) => {
    await page.goto('/shop/');
    await page.locator('a.ajax_add_to_cart').first().click();
    await expect(page.locator('a.added_to_cart')).toBeVisible();
    // Sent on the same page, from the add to cart response.
    await expectOneAddToCart(tracker);
    await viewAnotherPage(page);
    await expectOneAddToCart(tracker);
});

test('a block Product Button sends one AddToCart per platform', async ({ page, tracker }) => {
    await page.goto('/block-shop/');
    const button = page.locator('.wp-block-woocommerce-product-button button').first();

    await button.click();
    await expect(page.locator('.wp-block-woocommerce-product-button').first()).toContainText(/in cart/i);
    await expectOneAddToCart(tracker);
    await viewAnotherPage(page);
    await expectOneAddToCart(tracker);
});

test('behind a full-page cache, a second browser never sends the first browser’s add to cart', async ({ page, tracker, browser, baseURL }) => {
    const cacheCookie = { name: 'ct_e2e_page_cache', value: '1', url: baseURL! };

    await page.context().addCookies([cacheCookie]);
    await page.goto('/?ct_e2e_page_cache_flush=1');

    // Browser A adds to the cart, then views a page while its add is still in its session; the cache stores that page.
    await page.goto('/shop/');
    await page.locator('a.ajax_add_to_cart').first().click();
    await expect(page.locator('a.added_to_cart')).toBeVisible();
    await expectOneAddToCart(tracker);

    const primed = await page.goto('/product/ct-e2e-product/');

    expect(primed?.headers()['x-ct-cache']).toBe('MISS');
    await page.waitForLoadState('networkidle');
    await expectOneAddToCart(tracker);

    // Browser B gets A's cached page and must send no add to cart.
    const contextB = await browser.newContext();
    const trackerB = new NetworkTracker(baseURL!);

    await trackerB.attach(contextB);
    await contextB.addCookies([cacheCookie]);

    const pageB = await contextB.newPage();
    const cached = await pageB.goto('/product/ct-e2e-product/');

    expect(cached?.headers()['x-ct-cache']).toBe('HIT');
    await pageB.waitForLoadState('networkidle');
    await pageB.waitForTimeout(1_000);

    // B's tags did load, and they sent no add to cart.
    for (const tag of cartTags()) {
        expect((await trackerB.platformRequests(PLATFORM[tag])).length, `${tag} requests from browser B`).toBeGreaterThan(0);
        expect(await trackerB.eventHits(tag, ADD_TO_CART[tag]!), `${tag} AddToCart hits from browser B`).toHaveLength(0);
    }

    await contextB.close();
    await page.goto('/?ct_e2e_page_cache_flush=1');
});
