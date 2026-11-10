import { Browser, Page } from '@playwright/test';
import { test, expect } from '../utils/fixtures';
import { CapturedRequest, NetworkTracker } from '../utils/network';
import { BASE_URL } from '../utils/site';
import { isProActive, loginAsAdmin, openApp } from '../utils/admin';
import { PRODUCT_PATH, placeGuestOrder } from '../utils/checkout';
import { TEST_IDS } from '../utils/platforms';

// The X and Microsoft platform pages (#102), and the Google split notice for upgraders.

const SETTINGS_ROUTE = /wcct(\/|%2F)v1(\/|%2F)settings(\?|&|$)/;
const X_PIXEL = TEST_IDS.x?.pixelId ?? '';
const X_PURCHASE = 'tw-cte2x-newpur';
const X_CART = 'tw-cte2x-newadd';
const UET_TAG = '87654321';
const PRODUCT_URL = 'https://woocommerce.com/products/woocommerce-conversion-tracking-pro/';

type Options = Record<string, any>;

// The test site's ct-e2e/v1/state route (mu-plugins/ct-e2e-helpers.php), with the admin's cookies.
async function siteState(page: Page, options?: Options): Promise<Options> {
    const nonce = (await (await page.request.get('/wp-admin/admin-ajax.php?action=rest-nonce')).text()).trim();
    const response = options
        ? await page.request.post('/wp-json/ct-e2e/v1/state', { headers: { 'X-WP-Nonce': nonce }, data: { options } })
        : await page.request.get('/wp-json/ct-e2e/v1/state', { headers: { 'X-WP-Nonce': nonce } });

    expect(response.ok(), await response.text()).toBe(true);

    return (await response.json()).options ?? {};
}

async function settings(page: Page): Promise<Record<string, any>> {
    const nonce = (await (await page.request.get('/wp-admin/admin-ajax.php?action=rest-nonce')).text()).trim();

    return (await page.request.get('/wp-json/wcct/v1/settings', { headers: { 'X-WP-Nonce': nonce } })).json();
}

// A logged-out shopper in a fresh browser with its own tracker.
async function shopper(browser: Browser, work: (page: Page) => Promise<void>): Promise<CapturedRequest[]> {
    const context = await browser.newContext({ baseURL: BASE_URL });
    const tracker = new NetworkTracker(BASE_URL);

    await tracker.attach(context);

    const page = await context.newPage();

    await work(page);
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(500);

    const requests = await tracker.platformRequests();

    await context.close();

    return requests;
}

const field = (page: Page, platform: string, id: string) => page.locator(`#wcct-${platform}-${id}`);
const save = (page: Page) => page.getByTestId('wcct-platform-save');
const pill = (page: Page) => page.getByTestId('wcct-platform-pill');
const listItem = (page: Page, id: string) => page.locator(`nav[aria-labelledby="wcct-platform-list-label"] a[href="#/platforms/${id}"]`);

async function saveAndWait(page: Page) {
    const saved = page.waitForResponse(response => SETTINGS_ROUTE.test(response.url()) && response.request().method() === 'POST');

    await save(page).click();
    expect((await saved).status()).toBe(200);
    await expect(save(page)).toHaveText('Saved');
}

// X hits by event ID (txn_id): the base tag sends the Pixel ID, each event its own ID.
const xHits = (requests: CapturedRequest[], id: string) =>
    requests.filter(r => r.platform === 'x' && new URL(r.url).pathname.endsWith('/i/adsct') && r.params.some(p => p.get('txn_id') === id));

test.describe.serial('X and Microsoft pages', () => {
    let before: Options;
    let pro = false;

    test.beforeAll(async ({ browser }) => {
        const page = await browser.newPage({ baseURL: BASE_URL });

        await loginAsAdmin(page);
        // The first app request migrates the seeded 2.x settings.
        await openApp(page, '#/dashboard');
        pro = await isProActive(page);
        before = await siteState(page);
        await page.close();
    });

    test.afterEach(async ({ browser }) => {
        const page = await browser.newPage({ baseURL: BASE_URL });

        await loginAsAdmin(page);
        await siteState(page, { wcct_settings_v3: before.wcct_settings_v3, wcct_migration_flags: null });
        await page.close();
    });

    test('X: the re-setup notice, the event ID rows, a save and the storefront', async ({ page, browser }) => {
        test.skip(!X_PIXEL, 'X has no test ID');

        // An upgrader whose 2.x Purchase (and, on Pro, Add to cart label) needs a new event ID.
        const v3 = structuredClone(before.wcct_settings_v3);
        const flagged = pro ? ['purchase', 'add_to_cart'] : ['purchase'];

        v3.platforms.twitter = {
            ...v3.platforms.twitter,
            purchase_event_id: '',
            ...(pro ? { cart_event_id: '' } : {}),
            events: [...new Set([...(v3.platforms.twitter.events ?? []), ...flagged])],
            needs_resetup: flagged,
        };

        await loginAsAdmin(page);
        await siteState(page, { wcct_settings_v3: v3 });
        await openApp(page, '#/platforms/twitter');

        const detail = page.getByTestId('wcct-platform-status');

        await expect(detail.getByRole('heading', { name: 'X (formerly Twitter)' })).toBeVisible();
        await expect(pill(page)).toHaveText('Needs attention');
        await expect(listItem(page, 'twitter')).toContainText('Needs attention');

        const notice = page.getByTestId('wcct-x-resetup');

        await expect(notice).toContainText('X retired the old single-event codes');
        await expect(page.getByTestId('wcct-x-resetup-events')).toHaveText(pro ? 'Set up again: Purchase, Add to cart.' : 'Set up again: Purchase.');
        await notice.getByRole('button', { name: 'Set up the purchase event' }).click();
        await expect(field(page, 'twitter', 'purchase_event_id')).toBeFocused();

        // The help column for X.
        const help = page.getByTestId('wcct-platform-help');

        await expect(help).toContainText('Where to find the event ID');
        await expect(help).toContainText('Why a re-setup');

        // Rows wait for their ID; the page view comes from the base tag.
        await expect(page.getByTestId('wcct-twitter-event-page_view')).toContainText('Always on');
        await expect(page.getByTestId('wcct-twitter-event-purchase-seen')).toHaveText('Needs its event ID');
        await expect(page.getByRole('switch', { name: 'Send Purchase (add its event ID first)' })).toBeDisabled();
        await expect(page.getByTestId('wcct-twitter-pixel_id-check')).toHaveText('Looks right. This is the base tag on every page.');

        const purchase = field(page, 'twitter', 'purchase_event_id');

        await purchase.fill('tw-bad');
        await expect(page.getByTestId('wcct-twitter-purchase_event_id-check')).toContainText('Starts with tw-, then your Pixel ID');
        await purchase.fill(X_PURCHASE);
        await expect(page.getByTestId('wcct-twitter-purchase_event_id-check')).toContainText('Looks right.');
        await expect(page.getByRole('switch', { name: 'Send Purchase', exact: true })).toBeChecked();

        if (pro) {
            // Typing a valid tw- ID turns the row on.
            await expect(page.getByTestId('wcct-x-resetup-events')).toHaveText('Set up again: Add to cart.');
            await expect(page.getByTestId('wcct-twitter-event-add_to_cart-seen')).toHaveText('Needs its event ID');
            await field(page, 'twitter', 'cart_event_id').fill(X_CART);
            await expect(page.getByRole('switch', { name: 'Send Add to cart', exact: true })).toBeChecked();
            await expect(page.getByTestId('wcct-twitter-event-add_to_cart-seen')).toHaveText('On');

            // A row switched off by hand stays off while its valid ID is edited; only an empty ID becoming valid turns it on.
            await page.getByRole('switch', { name: 'Send Add to cart', exact: true }).click();
            await field(page, 'twitter', 'cart_event_id').fill('tw-cte2x-other');
            await expect(page.getByRole('switch', { name: 'Send Add to cart', exact: true })).not.toBeChecked();
            await field(page, 'twitter', 'cart_event_id').fill(X_CART);
            await page.getByRole('switch', { name: 'Send Add to cart', exact: true }).click();

            // Registration is off until switched on, and needs its ID first.
            await field(page, 'twitter', 'signup_event_id').fill('');
            await expect(page.getByTestId('wcct-twitter-event-sign_up-seen')).toHaveText('Needs its event ID');
        } else {
            // On Free, X sends Purchase only; the other event IDs are Pro.
            await expect(field(page, 'twitter', 'cart_event_id')).toBeDisabled();
            await expect(page.getByTestId('wcct-twitter-cart_event_id-field')).toContainText('PRO');
            await expect(page.getByTestId('wcct-twitter-event-sign_up')).toContainText('PRO');
        }

        await expect(notice).toBeHidden();
        await saveAndWait(page);
        await expect(pill(page)).not.toHaveText('Needs attention');
        await expect(listItem(page, 'twitter')).not.toContainText('Needs attention');

        const body = await settings(page);

        expect(body.platforms.twitter).toMatchObject({ pixel_id: X_PIXEL, purchase_event_id: X_PURCHASE, needs_resetup: [] });
        expect(body.platforms.twitter.events).toContain('purchase');

        if (pro) {
            expect(body.platforms.twitter).toMatchObject({ cart_event_id: X_CART, signup_event_id: '' });
            expect(body.platforms.twitter.events).toContain('add_to_cart');
        }

        // A shopper: the base tag on every page, the saved event IDs on their events.
        // placeGuestOrder() adds to the cart from the product page first.
        const requests = await shopper(browser, async shop => {
            await placeGuestOrder(shop, 'block');
        });

        expect(xHits(requests, X_PIXEL).length, 'the base tag').toBeGreaterThan(0);
        expect(xHits(requests, X_PURCHASE), 'one Purchase under the new event ID').toHaveLength(1);

        if (pro) {
            expect(xHits(requests, X_CART).length, 'Add to cart under its new event ID').toBeGreaterThan(0);
        }
    });

    test('Microsoft on Pro: the UET tag ID, the events and the storefront', async ({ page, browser }) => {
        test.skip(!pro, 'Microsoft is Pro');

        await loginAsAdmin(page);
        await openApp(page, '#/platforms/bing_ads');

        const detail = page.getByTestId('wcct-platform-status');

        await expect(detail.getByRole('heading', { name: 'Microsoft Advertising' })).toBeVisible();
        await expect(page.getByTestId('wcct-platform-help')).toContainText('Where to find your UET tag ID');

        const tag = field(page, 'bing_ads', 'tag_id');

        await expect(tag).toHaveValue(/^\d{7,9}$/);
        await tag.fill('abc');
        await expect(page.getByTestId('wcct-bing_ads-tag_id-check')).toHaveText('UET tag IDs are 7 to 9 digits.');
        await tag.fill(UET_TAG);
        await expect(page.getByTestId('wcct-bing_ads-tag_id-check')).toHaveText('Looks like a UET tag ID.');

        await expect(page.getByTestId('wcct-bing_ads-event-page_view')).toContainText('Always on');

        const presets = page.getByRole('group', { name: 'Event preset' });

        await presets.getByRole('button', { name: 'Recommended' }).click();
        await expect(page.getByTestId('wcct-bing_ads-events-count')).toHaveText('4 of 4 on');
        await page.getByRole('switch', { name: 'Send Start checkout' }).click();
        await expect(presets.getByRole('button', { name: 'Custom' })).toHaveAttribute('aria-pressed', 'true');
        await expect(page.getByTestId('wcct-bing_ads-events-count')).toHaveText('3 of 4 on');

        await page.getByTestId('wcct-bing_ads-advanced').locator('summary').click();
        await expect(page.getByTestId('wcct-bing_ads-developer')).toContainText('bing_ads');

        await saveAndWait(page);

        const body = await settings(page);

        expect(body.platforms.bing_ads.tag_id).toBe(UET_TAG);
        expect(body.platforms.bing_ads.events).toEqual(['page_view', 'add_to_cart', 'purchase']);

        // A shopper's page load goes to the new tag.
        const requests = await shopper(browser, async shop => {
            await shop.goto(PRODUCT_PATH);
        });
        const loads = requests.filter(r => r.platform === 'microsoft' && r.params.some(p => p.get('evt') === 'pageLoad'));

        expect(loads.length, 'UET page load').toBeGreaterThan(0);
        expect(loads.every(r => r.params.some(p => p.get('ti') === UET_TAG))).toBe(true);
    });

    test('Microsoft on Free: the locked preview with the upgrade card', async ({ page }) => {
        test.skip(pro, 'Pro runs Microsoft');

        await loginAsAdmin(page);
        await openApp(page, '#/platforms/bing_ads');

        // Listed with its PRO badge, never greyed out.
        await expect(listItem(page, 'bing_ads')).toContainText('PRO');
        await expect(listItem(page, 'bing_ads')).not.toHaveAttribute('aria-disabled', 'true');

        const locked = page.getByTestId('wcct-platform-bing_ads');

        await expect(locked.getByRole('heading', { name: /Microsoft Advertising/ }).first()).toContainText('PRO');
        await expect(locked).toContainText('Microsoft Advertising is part of Pro');
        await expect(locked).toContainText('UET tag on every page, purchases with order value');
        await expect(locked).toContainText('Also in Pro');
        await expect(locked.locator('a', { hasText: 'Compare plans' })).toHaveAttribute('href', PRODUCT_URL);
        await expect(field(page, 'bing_ads', 'tag_id')).toHaveCount(0);

        await locked.locator('a', { hasText: 'Continue with Free' }).click();
        await expect(page).toHaveURL(/#\/platforms\/facebook$/);
    });

    test('Google: upgraders whose Ads ID held the label see the split until they change it', async ({ page }) => {
        const google = before.wcct_settings_v3.platforms.google;

        await loginAsAdmin(page);
        await siteState(page, {
            wcct_migration_flags: {
                google_ads_id_split: {
                    platform: 'google',
                    fields: ['ads_id', 'purchase_label'],
                    saved: `${google.ads_id}/${google.purchase_label}`,
                    ads_id: google.ads_id,
                    purchase_label: google.purchase_label,
                },
            },
        });
        await openApp(page, '#/platforms/google');

        const notice = page.getByTestId('wcct-google-split');

        await expect(notice).toContainText('We split your Google Ads ID into a conversion ID and a label');
        await expect(notice).toContainText(`${google.ads_id}/${google.purchase_label}`);
        await expect(pill(page)).toHaveText('Needs attention');
        await expect(listItem(page, 'google')).toContainText('Needs attention');

        await field(page, 'google', 'purchase_label').fill('CtE2eNewLabel');
        await saveAndWait(page);
        await expect(notice).toBeHidden();
        await expect(pill(page)).not.toHaveText('Needs attention');
    });
});
