import { Browser, Page } from '@playwright/test';
import { test, expect } from '../utils/fixtures';
import { CapturedRequest, NetworkTracker } from '../utils/network';
import { BASE_URL } from '../utils/site';
import { isProActive, loginAsAdmin, openApp } from '../utils/admin';
import { PRODUCT_PATH } from '../utils/checkout';
import { TEST_IDS } from '../utils/platforms';

// Meta Pro's extra pixels and custom parameters editors (#105), and their locked rows on Free.

const SETTINGS_ROUTE = /wcct(\/|%2F)v1(\/|%2F)settings(\?|&|$)/;
const MAIN = TEST_IDS.meta?.pixelId ?? '';
const SECOND = '222222222222222';
const THIRD = '333333333333333';
const SKU = 'CT-E2E-SIMPLE';
const PRODUCT_URL = 'https://woocommerce.com/products/woocommerce-conversion-tracking-pro/';

type Options = Record<string, any>;

async function siteState(page: Page, options?: Options): Promise<Options> {
    const nonce = (await (await page.request.get('/wp-admin/admin-ajax.php?action=rest-nonce')).text()).trim();
    const response = options
        ? await page.request.post('/wp-json/ct-e2e/v1/state', { headers: { 'X-WP-Nonce': nonce }, data: { options } })
        : await page.request.get('/wp-json/ct-e2e/v1/state', { headers: { 'X-WP-Nonce': nonce } });

    expect(response.ok(), await response.text()).toBe(true);

    return (await response.json()).options ?? {};
}

async function settings(page: Page, data?: object): Promise<{ status: number; body: Record<string, any> }> {
    const nonce = (await (await page.request.get('/wp-admin/admin-ajax.php?action=rest-nonce')).text()).trim();
    const response = data
        ? await page.request.post('/wp-json/wcct/v1/settings', { headers: { 'X-WP-Nonce': nonce }, data })
        : await page.request.get('/wp-json/wcct/v1/settings', { headers: { 'X-WP-Nonce': nonce } });

    return { status: response.status(), body: await response.json() };
}

// The product page in a fresh, logged-out browser with its own tracker.
async function visitProduct(browser: Browser): Promise<CapturedRequest[]> {
    const context = await browser.newContext({ baseURL: BASE_URL });
    const tracker = new NetworkTracker(BASE_URL);

    await tracker.attach(context);

    const page = await context.newPage();

    await page.goto(PRODUCT_PATH);
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(500);

    const requests = await tracker.platformRequests('meta');

    await context.close();

    return requests;
}

// Meta hits of one event: the pixel and the custom data of each.
const hitsOf = (requests: CapturedRequest[], event: string) =>
    requests.flatMap(r =>
        r.params
            .filter(p => p.get('ev') === event)
            .map(p => ({ pixel: p.get('id') ?? '', sku: p.get('cd[sku]') ?? '' }))
    );

const save = (page: Page) => page.getByTestId('wcct-platform-save');
const pixels = (page: Page) => page.getByTestId('wcct-facebook-pixels');
const pixel = (page: Page, index: number) => page.getByTestId(`wcct-facebook-pixels-${index}`);
const params = (page: Page) => page.getByTestId('wcct-facebook-custom_parameters');
const param = (page: Page, index: number) => page.getByTestId(`wcct-facebook-custom_parameters-${index}`);

async function choose(page: Page, trigger: string, option: string) {
    await page.locator(`#${trigger}`).click();
    await page.getByRole('option', { name: option, exact: true }).click();
}

async function saveAndWait(page: Page) {
    const saved = page.waitForResponse(response => SETTINGS_ROUTE.test(response.url()) && response.request().method() === 'POST');

    await save(page).click();
    expect((await saved).status()).toBe(200);
    await expect(save(page)).toHaveText('Saved');
}

async function openMetaAdvanced(page: Page) {
    await openApp(page, '#/platforms/facebook');
    await page.getByTestId('wcct-facebook-advanced').locator('summary').click();
}

test.describe.serial('Meta Pro editors', () => {
    let before: Options;
    let pro = false;

    test.skip(!MAIN, 'Meta has no test ID');

    test.beforeAll(async ({ browser }) => {
        const page = await browser.newPage({ baseURL: BASE_URL });

        await loginAsAdmin(page);
        await openApp(page, '#/dashboard');
        pro = await isProActive(page);
        before = await siteState(page);
        await page.close();
    });

    test.afterAll(async ({ browser }) => {
        const page = await browser.newPage({ baseURL: BASE_URL });

        await loginAsAdmin(page);
        await siteState(page, { wcct_settings_v3: before.wcct_settings_v3 });
        await page.close();
    });

    test('Free: both areas are locked with an upgrade link, and nothing is saved', async ({ page }) => {
        test.skip(pro, 'Pro edits them');

        await loginAsAdmin(page);
        await openMetaAdvanced(page);

        const preview = page.getByTestId('wcct-facebook-pro-preview');

        await expect(preview).toContainText('Custom parameters');
        await expect(preview).toContainText('More than one pixel');
        await expect(preview).toContainText('PRO');

        for (const link of await preview.getByRole('link', { name: /Upgrade/ }).all()) {
            await expect(link).toHaveAttribute('href', PRODUCT_URL);
        }

        await expect(pixels(page)).toHaveCount(0);
        await expect(params(page)).toHaveCount(0);

        // The route has no such fields on Free, so a post changes nothing.
        const posted = await settings(page, {
            platforms: { facebook: { pixels: [{ pixel_id: SECOND, events: ['purchase'] }], custom_parameters: [{ event: 'purchase', name: 'brand', token: '{product_brand}' }] } },
        });

        expect(posted.status).toBe(200);
        expect(posted.body.platforms.facebook).not.toHaveProperty('pixels');
        expect(posted.body.platforms.facebook).not.toHaveProperty('custom_parameters');
        expect((await siteState(page)).wcct_settings_v3.platforms.facebook).not.toHaveProperty('pixels');
    });

    test('Pro: extra pixels with their own events, the limits, a save and trackSingle per pixel', async ({ page, browser }) => {
        test.skip(!pro, 'Free shows the locked rows');

        await loginAsAdmin(page);
        await openMetaAdvanced(page);

        await expect(page.getByTestId('wcct-facebook-pro-preview')).toHaveCount(0);
        await expect(pixels(page)).toContainText('Main pixel');
        await expect(pixels(page)).toContainText(`${MAIN.slice(0, 4)}…${MAIN.slice(-3)}, the events above`);

        // A new pixel: its ID field takes focus, an empty one stops the save.
        await page.getByRole('button', { name: 'Add a pixel' }).click();
        await expect(pixel(page, 0)).toContainText('Extra pixel 1');
        await expect(page.locator('#wcct-facebook-pixels-0-pixel_id')).toBeFocused();
        await save(page).click();
        await expect(page.getByTestId('wcct-facebook-pixels-0-pixel_id-check')).toHaveText('Add the Pixel ID, or remove this pixel.');
        await expect(page.locator('#wcct-facebook-pixels-0-pixel_id')).toBeFocused();

        const id = page.locator('#wcct-facebook-pixels-0-pixel_id');

        await id.fill('12345');
        await expect(page.getByTestId('wcct-facebook-pixels-0-pixel_id-check')).toHaveText('Pixel IDs are 15 or 16 digits.');
        await id.fill(MAIN);
        await expect(page.getByTestId('wcct-facebook-pixels-0-pixel_id-check')).toHaveText('This pixel is already on the page. It gets each event once.');
        await id.fill(SECOND);
        await expect(page.getByTestId('wcct-facebook-pixels-0-pixel_id-check')).toHaveText('Looks like a Pixel ID.');

        // Its own events: Recommended to start, then Purchase only.
        const first = pixel(page, 0);

        await expect(first.getByRole('group', { name: 'Event preset' }).getByRole('button', { name: 'Recommended' })).toHaveAttribute('aria-pressed', 'true');

        for (const event of ['View product', 'Add to cart', 'Start checkout']) {
            await first.getByRole('switch', { name: `Send ${event}`, exact: true }).click();
        }

        await expect(first.getByTestId('wcct-facebook-pixels-0-events-count')).toHaveText(/^1 of \d+ on$/);

        // A second extra pixel keeps the recommended events.
        await page.getByRole('button', { name: 'Add a pixel' }).click();
        await page.locator('#wcct-facebook-pixels-1-pixel_id').fill(SECOND);
        await expect(page.getByTestId('wcct-facebook-pixels-1-pixel_id-check')).toHaveText('This pixel is already on the page. This one is removed when you save.');
        await page.locator('#wcct-facebook-pixels-1-pixel_id').fill(THIRD);

        // Ten extra pixels at most.
        for (let count = 2; count < 10; count++) {
            await page.getByRole('button', { name: 'Add a pixel' }).click();
        }

        await expect(page.getByTestId('wcct-facebook-pixels-add')).toBeDisabled();
        await expect(page.getByTestId('wcct-facebook-pixels-add')).toHaveText('Up to 10 extra pixels');

        for (let count = 2; count < 10; count++) {
            await page.getByTestId('wcct-facebook-pixels-2-remove').click();
        }

        await expect(page.getByTestId('wcct-facebook-pixels-2')).toHaveCount(0);
        await expect(page.getByTestId('wcct-facebook-pixels-add')).toBeEnabled();

        await saveAndWait(page);

        const { body } = await settings(page);

        expect(body.platforms.facebook.pixels).toEqual([
            { pixel_id: SECOND, events: ['purchase'] },
            { pixel_id: THIRD, events: ['view_product', 'add_to_cart', 'begin_checkout', 'purchase'] },
        ]);

        // The storefront: View product goes to the main pixel and the third one through trackSingle, never the Purchase-only one.
        const views = hitsOf(await visitProduct(browser), 'ViewContent');

        expect(views.map(hit => hit.pixel).sort()).toEqual([MAIN, THIRD].sort());

        // Remove takes it off the page and the store.
        await page.getByRole('button', { name: 'Remove extra pixel 2' }).click();
        await expect(page.getByTestId('wcct-facebook-pixels-1')).toHaveCount(0);
        await expect(page.getByTestId('wcct-facebook-pixels-add')).toBeFocused();
        await saveAndWait(page);
        expect((await settings(page)).body.platforms.facebook.pixels).toEqual([{ pixel_id: SECOND, events: ['purchase'] }]);

        // The server refuses what the page would: an eleventh pixel and a malformed ID.
        const eleven = Array.from({ length: 11 }, (_, index) => ({ pixel_id: String(100000000000000 + index), events: ['purchase'] }));
        const tooMany = await settings(page, { platforms: { facebook: { pixels: eleven } } });

        expect(tooMany.status).toBe(400);
        expect(tooMany.body.data.errors['platforms.facebook.pixels']).toBe('Add up to 10 more pixels.');
        expect((await settings(page, { platforms: { facebook: { pixels: [{ pixel_id: '12345', events: [] }] } } })).status).toBe(400);
    });

    test('Pro: a save the server refuses opens Advanced settings at the refused editor', async ({ page, jsErrors }) => {
        test.skip(!pro, 'Free shows the locked rows');
        // The browser logs the stubbed 400.
        jsErrors.allow(/status of 400 .*wcct\/v1\/settings/);

        await loginAsAdmin(page);
        await openApp(page, '#/platforms/facebook');
        await page.route(SETTINGS_ROUTE, route =>
            route.request().method() === 'POST'
                ? route.fulfill({
                      status: 400,
                      contentType: 'application/json',
                      body: JSON.stringify({
                          code: 'rest_invalid_param',
                          message: 'Invalid parameter(s): platforms',
                          data: { status: 400, errors: { 'platforms.facebook.pixels': 'Add up to 10 more pixels.' } },
                      }),
                  })
                : route.continue()
        );

        await page.getByRole('switch', { name: 'Send Search', exact: true }).click();
        await save(page).click();
        await expect(page.getByTestId('wcct-facebook-advanced')).toHaveAttribute('open', '');
        await expect(pixels(page)).toBeFocused();
        await expect(page.getByTestId('wcct-facebook-pixels-error')).toHaveText('Add up to 10 more pixels.');
        await page.unroute(SETTINGS_ROUTE);
    });

    test('Pro: custom parameters, five per event, with the tokens and their checks', async ({ page, browser }) => {
        test.skip(!pro, 'Free shows the locked rows');

        await loginAsAdmin(page);
        await openMetaAdvanced(page);

        const editor = params(page);

        await expect(editor).toContainText('Up to 5 extra fields per event');
        await page.getByRole('switch', { name: 'Custom parameters' }).click();
        await expect(param(page, 0)).toContainText('Parameter 1');
        await expect(page.locator('#wcct-facebook-custom_parameters-0-name')).toBeFocused();
        await expect(page.locator('#wcct-facebook-custom_parameters-0-event')).toContainText('Purchase');

        // Removing the last row keeps the editor open, with focus on Add.
        await page.getByTestId('wcct-facebook-custom_parameters-0-remove').click();
        await expect(page.getByTestId('wcct-facebook-custom_parameters-add')).toBeFocused();
        await page.getByRole('button', { name: 'Add a parameter' }).click();
        await expect(page.locator('#wcct-facebook-custom_parameters-0-name')).toBeFocused();

        // An empty field name stops the save; a bad one is explained as you type.
        await save(page).click();
        await expect(page.getByTestId('wcct-facebook-custom_parameters-0-name-check')).toHaveText('Add a field name, like content_category.');

        const name = page.locator('#wcct-facebook-custom_parameters-0-name');

        await name.fill('bad name');
        await expect(page.getByTestId('wcct-facebook-custom_parameters-0-name-check')).toHaveText('Field names use letters, numbers and underscores, and start with a letter or underscore.');
        await name.fill('customer');
        await expect(page.getByTestId('wcct-facebook-custom_parameters-0-name-check')).toHaveText('');
        await choose(page, 'wcct-facebook-custom_parameters-0-token', '{customer_type}');

        // Five for Purchase; the next one goes with another event and can't move to Purchase.
        for (let index = 1; index < 5; index++) {
            await page.getByRole('button', { name: 'Add a parameter' }).click();
            await page.locator(`#wcct-facebook-custom_parameters-${index}-name`).fill(`field_${index}`);
        }

        await page.getByRole('button', { name: 'Add a parameter' }).click();
        await expect(page.locator('#wcct-facebook-custom_parameters-5-event')).toContainText('View product');
        await page.locator('#wcct-facebook-custom_parameters-5-event').click();
        await expect(page.getByRole('option', { name: 'Purchase (5 already)', exact: true })).toBeDisabled();
        await page.keyboard.press('Escape');

        // Coupon and customer type need an order, so only Purchase sends them.
        await page.locator('#wcct-facebook-custom_parameters-5-token').click();
        await expect(page.getByRole('option', { name: '{customer_type} (Purchase only)', exact: true })).toBeDisabled();
        await page.getByRole('option', { name: '{product_sku}', exact: true }).click();
        await page.locator('#wcct-facebook-custom_parameters-5-name').fill('sku');

        await saveAndWait(page);

        const saved = (await settings(page)).body.platforms.facebook.custom_parameters;

        expect(saved).toHaveLength(6);
        expect(saved[0]).toEqual({ event: 'purchase', name: 'customer', token: '{customer_type}' });
        expect(saved[5]).toEqual({ event: 'view_product', name: 'sku', token: '{product_sku}' });

        // The storefront merges the parameter into View product.
        const views = hitsOf(await visitProduct(browser), 'ViewContent');

        expect(views.length).toBeGreaterThan(0);
        expect(views.every(hit => hit.sku === SKU)).toBe(true);

        // Off removes them all once saved.
        await page.getByRole('switch', { name: 'Custom parameters' }).click();
        await expect(param(page, 0)).toHaveCount(0);
        await saveAndWait(page);
        expect((await settings(page)).body.platforms.facebook.custom_parameters).toEqual([]);

        // The server refuses a sixth parameter for one event, an unknown token and a bad field name.
        const row = { event: 'purchase', name: 'brand', token: '{product_brand}' };
        const six = await settings(page, { platforms: { facebook: { custom_parameters: Array.from({ length: 6 }, () => row) } } });

        expect(six.status).toBe(400);
        expect(six.body.data.errors['platforms.facebook.custom_parameters']).toMatch(/^Add up to 5 custom parameters/);
        expect((await settings(page, { platforms: { facebook: { custom_parameters: [{ ...row, token: '{order_total}' }] } } })).status).toBe(400);
        expect((await settings(page, { platforms: { facebook: { custom_parameters: [{ ...row, name: '1st' }] } } })).status).toBe(400);
    });
});
