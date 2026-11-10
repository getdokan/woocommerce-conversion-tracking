import { Browser, Page } from '@playwright/test';
import { test, expect } from '../utils/fixtures';
import { NetworkTracker } from '../utils/network';
import { BASE_URL } from '../utils/site';
import { SHOP_MANAGER, isProActive, login, loginAsAdmin, openApp } from '../utils/admin';
import { PRODUCT_PATH } from '../utils/checkout';
import { TEST_IDS } from '../utils/platforms';

// The Meta and Google platform pages (#101) with their Advanced settings (#104).

const SETTINGS_ROUTE = /wcct(\/|%2F)v1(\/|%2F)settings(\?|&|$)/;
const NEW_PIXEL = '987654321098765';
const SKU = 'CT-E2E-SIMPLE';

type State = { options: Record<string, unknown> };

// The test site's ct-e2e/v1/state route (mu-plugins/ct-e2e-helpers.php), with the admin's cookies.
async function siteState(page: Page, body?: object): Promise<State> {
    const nonce = (await (await page.request.get('/wp-admin/admin-ajax.php?action=rest-nonce')).text()).trim();
    const response = body
        ? await page.request.post('/wp-json/ct-e2e/v1/state', { headers: { 'X-WP-Nonce': nonce }, data: body })
        : await page.request.get('/wp-json/ct-e2e/v1/state', { headers: { 'X-WP-Nonce': nonce } });

    expect(response.ok(), await response.text()).toBe(true);

    return response.json();
}

// The settings route as the logged-in user.
async function settings(page: Page, data?: object): Promise<{ status: number; body: Record<string, any> }> {
    const nonce = (await (await page.request.get('/wp-admin/admin-ajax.php?action=rest-nonce')).text()).trim();
    const response = data
        ? await page.request.post('/wp-json/wcct/v1/settings', { headers: { 'X-WP-Nonce': nonce }, data })
        : await page.request.get('/wp-json/wcct/v1/settings', { headers: { 'X-WP-Nonce': nonce } });

    return { status: response.status(), body: await response.json() };
}

const postAccess = async (page: Page, roles: string[]) => {
    const nonce = (await (await page.request.get('/wp-admin/admin-ajax.php?action=rest-nonce')).text()).trim();

    expect((await page.request.post('/wp-json/wcct/v1/access', { headers: { 'X-WP-Nonce': nonce }, data: { roles } })).ok()).toBe(true);
};

// The product page in a fresh browser (logged out unless the page's cookies are passed), with its own tracker.
async function visit(browser: Browser, cookiesFrom?: Page) {
    const context = await browser.newContext({ baseURL: BASE_URL });
    const tracker = new NetworkTracker(BASE_URL);

    await tracker.attach(context);

    if (cookiesFrom) {
        await context.addCookies(await cookiesFrom.context().cookies());
    }

    const page = await context.newPage();

    await page.goto(PRODUCT_PATH);
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(500);

    const requests = await tracker.platformRequests();

    await context.close();

    return requests;
}

const field = (page: Page, platform: string, id: string) => page.locator(`#wcct-${platform}-${id}`);
const save = (page: Page) => page.getByTestId('wcct-platform-save');
const listItem = (page: Page, id: string) => page.locator(`nav[aria-labelledby="wcct-platform-list-label"] a[href="#/platforms/${id}"]`);

async function pick(page: Page, label: string, option: string) {
    await page.getByLabel(label, { exact: true }).click();
    await page.getByRole('option', { name: option, exact: true }).click();
}

async function saveAndWait(page: Page) {
    const saved = page.waitForResponse(response => SETTINGS_ROUTE.test(response.url()) && response.request().method() === 'POST');

    await save(page).click();
    expect((await saved).status()).toBe(200);
    await expect(save(page)).toHaveText('Saved');
    await expect(save(page)).toBeDisabled();
}

test.describe.serial('Platforms pages', () => {
    let before: Record<string, unknown>;

    test.beforeAll(async ({ browser }) => {
        const page = await browser.newPage({ baseURL: BASE_URL });

        await loginAsAdmin(page);
        // The first app request migrates the seeded 2.x settings.
        await openApp(page, '#/dashboard');
        before = (await siteState(page)).options;
        await page.close();
    });

    test.afterAll(async ({ browser }) => {
        const page = await browser.newPage({ baseURL: BASE_URL });

        await loginAsAdmin(page);
        await siteState(page, { options: { wcct_settings_v3: before.wcct_settings_v3, wcct_consent_mode: before.wcct_consent_mode ?? null } });

        if (await (async () => { await openApp(page, '#/dashboard'); return isProActive(page); })()) {
            await postAccess(page, []);
        }

        await page.close();
    });

    test('Meta: the Pixel ID check, the event presets, Advanced settings and a save the storefront follows', async ({ page, browser }) => {
        await loginAsAdmin(page);
        await openApp(page, '#/platforms/facebook');

        const detail = page.getByTestId('wcct-platform-status');

        await expect(detail.getByRole('heading', { name: 'Meta' })).toBeVisible();
        await expect(page.getByTestId('wcct-platform-help')).toContainText('Where to find your Pixel ID');
        await expect(save(page)).toBeDisabled();

        // The format check as you type; no lookup.
        const pixel = field(page, 'facebook', 'pixel_id');

        await expect(pixel).toHaveValue(TEST_IDS.meta!.pixelId);
        await pixel.fill('EAA12345');
        await expect(page.getByTestId('wcct-facebook-pixel_id-check')).toHaveText('Pixel IDs are 15 or 16 digits, numbers only.');
        await expect(save(page)).toHaveText('Save changes');
        await save(page).click();
        await expect(page.getByTestId('wcct-facebook-pixel_id-check')).toContainText('Fix this ID before saving');
        await expect(pixel).toBeFocused();
        await pixel.fill(NEW_PIXEL);
        await expect(page.getByTestId('wcct-facebook-pixel_id-check')).toHaveText('Looks like a Pixel ID.');

        // Presets come from the switches: Recommended, then one more event makes it Custom.
        const presets = page.getByRole('group', { name: 'Event preset' });

        await presets.getByRole('button', { name: 'Recommended' }).click();
        await expect(presets.getByRole('button', { name: 'Recommended' })).toHaveAttribute('aria-pressed', 'true');
        await expect(page.getByTestId('wcct-facebook-events-count')).toHaveText(/^4 of \d+ on$/);
        await page.getByRole('switch', { name: 'Send Search' }).click();
        await expect(presets.getByRole('button', { name: 'Custom' })).toHaveAttribute('aria-pressed', 'true');
        await expect(page.getByTestId('wcct-facebook-event-search-seen')).toHaveText('On');
        await expect(page.getByTestId('wcct-facebook-event-purchase-seen')).not.toHaveText('On');
        await presets.getByRole('button', { name: 'All' }).click();
        await expect(presets.getByRole('button', { name: 'All' })).toHaveAttribute('aria-pressed', 'true');

        if (!(await isProActive(page))) {
            await expect(page.getByTestId('wcct-facebook-event-view_category')).toContainText('PRO');
        }

        // Advanced settings, collapsed until opened.
        const advanced = page.getByTestId('wcct-facebook-advanced');

        await expect(field(page, 'facebook', 'value_mode')).toBeHidden();
        await advanced.locator('summary').click();
        await pick(page, 'Order value includes', 'Items and shipping');
        await pick(page, 'Product ID sent', 'SKU');
        await pick(page, 'Skip these roles', 'Administrator only');
        await expect(page.getByTestId('wcct-facebook-developer')).toContainText('facebook');
        await expect(page.getByTestId('wcct-facebook-developer')).toContainText('wcct_{order_id}_purchase');

        if (!(await isProActive(page))) {
            await expect(page.getByTestId('wcct-facebook-pro-preview')).toContainText('More than one pixel');
        }

        await saveAndWait(page);
        await expect(page.locator('[data-sonner-toast]').filter({ hasText: 'Saved.' })).toBeVisible();
        await expect(listItem(page, 'facebook')).toContainText('Customised');

        const { body } = await settings(page);

        expect(body.platforms.facebook).toMatchObject({ pixel_id: NEW_PIXEL, value_mode: 'shipping', product_id_mode: 'sku', skip_roles: 'admin' });
        expect(body.platforms.facebook.events).toEqual(expect.arrayContaining(['view_product', 'add_to_cart', 'begin_checkout', 'purchase', 'search', 'sign_up']));

        // A shopper gets the new pixel, with the SKU as the content ID.
        const shopper = await visit(browser);
        const views = shopper.filter(r => r.platform === 'meta' && r.params.some(p => p.get('ev') === 'ViewContent'));

        expect(views.length, 'ViewContent from the new pixel').toBeGreaterThan(0);
        expect(views.every(r => r.params.some(p => p.get('id') === NEW_PIXEL))).toBe(true);
        expect(views[0].params.find(p => p.get('ev') === 'ViewContent')?.get('cd[content_ids]')).toContain(SKU);

        // Skip these roles: Administrator only, so the logged-in admin isn't tracked by Meta but still is by Google (set to track everyone).
        const googleSkips = (await settings(page)).body.platforms.google.skip_roles;

        expect((await settings(page, { platforms: { google: { skip_roles: 'none' } } })).status).toBe(200);

        const admin = await visit(browser, page);

        expect((await settings(page, { platforms: { google: { skip_roles: googleSkips } } })).status).toBe(200);

        expect(admin.filter(r => r.platform === 'meta').length, 'Meta skips the administrator').toBe(0);
        expect(admin.filter(r => r.platform === 'google').length, 'Google still tracks the administrator').toBeGreaterThan(0);

        // Back to the defaults: the badge goes away.
        await pick(page, 'Order value includes', 'Items only (recommended)');
        await pick(page, 'Product ID sent', 'Product ID (recommended)');
        await pick(page, 'Skip these roles', 'Administrator, Shop manager');
        await saveAndWait(page);
        await expect(listItem(page, 'facebook')).not.toContainText('Customised');
    });

    test('Skip these roles takes roles picked one by one', async ({ page }) => {
        await loginAsAdmin(page);
        await openApp(page, '#/platforms/facebook?field=skip_roles');

        await expect(field(page, 'facebook', 'skip_roles')).toBeFocused();
        await pick(page, 'Skip these roles', 'Choose roles…');

        const roles = page.getByTestId('wcct-facebook-skip_roles-roles');

        // It starts from the preset's roles.
        await expect(roles.getByRole('checkbox', { name: 'Administrator', exact: true })).toBeChecked();
        await roles.getByRole('checkbox', { name: 'Shop manager', exact: true }).uncheck();
        await roles.getByRole('checkbox', { name: 'Editor', exact: true }).check();
        await saveAndWait(page);
        expect((await settings(page)).body.platforms.facebook.skip_roles).toEqual(['administrator', 'editor']);

        // A pick that is a preset saves as that preset.
        await roles.getByRole('checkbox', { name: 'Editor', exact: true }).uncheck();
        await saveAndWait(page);
        expect((await settings(page)).body.platforms.facebook.skip_roles).toBe('admin');
    });

    test('Google: the mode shows only the IDs it needs, the label helper, the Google tag ID and the storefront follows', async ({ page, browser }) => {
        await loginAsAdmin(page);
        await openApp(page, '#/platforms/google');

        const help = page.getByTestId('wcct-platform-help');

        await expect(help).toContainText('Finding the Ads conversion label');
        await expect(page.getByTestId('wcct-google-double-counting')).toContainText('Doctor');

        // Both shows every ID; Analytics hides the Ads ones.
        await expect(page.getByRole('radio', { name: /^Both/ })).toBeChecked();
        await expect(field(page, 'google', 'ads_id')).toBeVisible();
        await page.getByRole('radio', { name: /^Google Analytics/ }).click();
        await expect(field(page, 'google', 'ads_id')).toBeHidden();
        await expect(field(page, 'google', 'purchase_label')).toBeHidden();
        await expect(field(page, 'google', 'measurement_id')).toBeVisible();
        await page.getByRole('radio', { name: /^Google Ads/ }).click();
        await expect(field(page, 'google', 'measurement_id')).toBeHidden();
        await page.getByRole('radio', { name: /^Both/ }).click();

        // Enhanced conversions: a locked switch on Free, a working one with Pro (#97); before #97, an upgrade line on Free.
        const pro = await isProActive(page);
        const enhanced = page.getByRole('switch', { name: 'Enhanced conversions' });

        if (await enhanced.count()) {
            await expect(enhanced).toBeEnabled({ enabled: pro });

            // The page shows the schema's own description.
            const nonce = (await (await page.request.get('/wp-admin/admin-ajax.php?action=rest-nonce')).text()).trim();
            const schema = await (await page.request.get('/wp-json/wcct/v1/settings/schema', { headers: { 'X-WP-Nonce': nonce } })).json();
            const description = schema.platforms
                .find((platform: { id: string }) => platform.id === 'google')
                .schema.find((element: { id: string }) => element.id === 'enhanced_conversions').description;

            await expect(page.getByTestId('wcct-google-enhanced_conversions-row')).toContainText(description);

            if (!pro) {
                await expect(page.getByTestId('wcct-google-enhanced_conversions-row')).toContainText('PRO');
            }
        } else if (!pro) {
            await expect(page.getByTestId('wcct-google-pro-preview')).toContainText('Enhanced conversions');
        }

        // A short label is flagged; the format is still right.
        await field(page, 'google', 'purchase_label').fill('AbC');
        await expect(page.getByTestId('wcct-google-purchase_label-check')).toContainText('Looks incomplete');
        await field(page, 'google', 'purchase_label').fill(TEST_IDS.ads!.purchaseLabel);
        await expect(save(page)).toBeDisabled();

        // Advanced: the optional GT- tag ID and the product ID.
        await page.getByTestId('wcct-google-advanced').locator('summary').click();
        await field(page, 'google', 'tag_id').fill('gt-ab12');
        await expect(page.getByTestId('wcct-google-tag_id-check')).toHaveText('Looks right.');
        await expect(page.getByTestId('wcct-google-value_mode-field')).toContainText('Analytics always gets the item total');
        await pick(page, 'Product ID sent', 'SKU');
        await saveAndWait(page);
        await expect(listItem(page, 'google')).toContainText('Customised');

        expect((await settings(page)).body.platforms.google).toMatchObject({ tag_id: 'GT-AB12', product_id_mode: 'sku', mode: 'both' });

        // The shopper's page loads gtag.js for the GT- tag, and view_item carries the SKU.
        const shopper = await visit(browser);

        expect(shopper.some(r => /gtag\/js\?id=GT-AB12/.test(r.url))).toBe(true);

        const viewItem = shopper.flatMap(r => r.params).find(p => p.get('en') === 'view_item');

        expect(viewItem?.get('pr1')).toContain(`id${SKU}`);

        await field(page, 'google', 'tag_id').fill('');
        await pick(page, 'Product ID sent', 'Product ID (recommended)');
        await saveAndWait(page);
        await expect(listItem(page, 'google')).not.toContainText('Customised');
    });

    test('Respect cookie choices is one switch for every platform, with the consent tool line', async ({ page, context }) => {
        await loginAsAdmin(page);
        await context.addCookies([{ name: 'ct_e2e_consent_source', value: 'complianz', url: BASE_URL }]);
        await openApp(page, '#/platforms/facebook');

        const consent = page.getByRole('switch', { name: 'Respect cookie choices' });
        const was = await consent.isChecked();

        await expect(page.getByTestId('wcct-consent-handled-by')).toHaveText('Consent signals are handled by Complianz. Conversion Tracking will not set its own defaults.');
        await consent.click();
        await saveAndWait(page);

        await listItem(page, 'google').click();
        await expect(page.getByTestId('wcct-platform-status')).toHaveAttribute('data-platform', 'google');
        await expect(page.getByRole('switch', { name: 'Respect cookie choices' })).toBeChecked({ checked: !was });
        expect((await settings(page)).body.consent_mode).toBe(!was);

        await page.getByRole('switch', { name: 'Respect cookie choices' }).click();
        await saveAndWait(page);
        expect((await settings(page)).body.consent_mode).toBe(was);
    });

    test('Doctor links (?field=) focus the field, also inside Advanced settings', async ({ page }) => {
        await loginAsAdmin(page);

        await openApp(page, '#/platforms/google?field=purchase_label');
        await expect(field(page, 'google', 'purchase_label')).toBeFocused();

        await openApp(page, '#/platforms/google?field=tag_id');
        await expect(page.getByTestId('wcct-google-advanced')).toHaveAttribute('open', '');
        await expect(field(page, 'google', 'tag_id')).toBeFocused();

        // What's new links (#124).
        await openApp(page, '#/platforms/twitter?field=purchase_event_id');
        await expect(field(page, 'twitter', 'purchase_event_id')).toBeFocused();

        await openApp(page, '#/platforms/facebook?field=skip_roles');
        await expect(page.getByTestId('wcct-facebook-advanced')).toHaveAttribute('open', '');
        await expect(field(page, 'facebook', 'skip_roles')).toBeFocused();

        await openApp(page, '#/platforms/facebook?field=consent_mode');
        await expect(page.getByRole('switch', { name: 'Respect cookie choices' })).toBeFocused();

        // The dashboard's links open the Cookie choices section.
        await openApp(page, '#/platforms?section=cookie-choices');
        await expect(page).toHaveURL(/#\/platforms\/facebook\?section=cookie-choices$/);
        await expect(page.getByRole('heading', { name: 'Cookie choices' })).toBeFocused();
        await expect(page.getByTestId('wcct-cookie-choices').getByRole('switch', { name: 'Respect cookie choices' })).toBeVisible();
    });

    test('unsaved changes ask before leaving, and a save while the update to 3.0 runs keeps the edits', async ({ page, jsErrors }) => {
        jsErrors.allow(/status of 409/);
        await loginAsAdmin(page);
        await openApp(page, '#/platforms/facebook');

        await page.getByRole('switch', { name: 'Send Search' }).click();

        page.once('dialog', dialog => dialog.dismiss());
        await listItem(page, 'google').click();
        await expect(page.getByTestId('wcct-platform-status')).toHaveAttribute('data-platform', 'facebook');

        await page.route(SETTINGS_ROUTE, route =>
            route.request().method() === 'POST'
                ? route.fulfill({ status: 409, json: { code: 'wcct_rest_migration_pending', message: 'Pending', data: { status: 409 } } })
                : route.fallback()
        );
        await save(page).click();
        await expect(page.getByTestId('wcct-platform-migration-pending')).toContainText("Your changes weren't saved yet.");
        await expect(save(page)).toHaveText('Save changes');
        await page.unroute(SETTINGS_ROUTE);

        const saved = page.waitForResponse(response => SETTINGS_ROUTE.test(response.url()) && response.request().method() === 'POST');

        await page.getByTestId('wcct-platform-migration-pending').getByRole('button', { name: 'Try again' }).click();
        expect((await saved).status()).toBe(200);
        await expect(save(page)).toHaveText('Saved');

        await page.getByRole('switch', { name: 'Send Search' }).click();
        await saveAndWait(page);
    });

    test('a field the server refuses opens its section and takes focus', async ({ page, jsErrors }) => {
        jsErrors.allow(/status of 400/);
        await loginAsAdmin(page);
        await openApp(page, '#/platforms/google');

        await page.getByTestId('wcct-google-advanced').locator('summary').click();
        await pick(page, 'Product ID sent', 'SKU');
        await page.getByTestId('wcct-google-advanced').locator('summary').click();
        await expect(page.getByTestId('wcct-google-advanced')).not.toHaveAttribute('open', '');

        await page.route(SETTINGS_ROUTE, route =>
            route.request().method() === 'POST'
                ? route.fulfill({
                      status: 400,
                      json: { code: 'wcct_rest_invalid_settings', message: 'Some settings are not valid.', data: { status: 400, errors: { 'platforms.google.tag_id': "This doesn't look like a valid ID." } } },
                  })
                : route.fallback()
        );
        await save(page).click();
        await expect(page.getByTestId('wcct-platform-save-error')).toContainText('Some settings are not valid.');
        await expect(page.getByTestId('wcct-google-advanced')).toHaveAttribute('open', '');
        await expect(field(page, 'google', 'tag_id')).toBeFocused();
        await expect(page.getByTestId('wcct-google-tag_id-check')).toHaveText("This doesn't look like a valid ID.");
        await page.unroute(SETTINGS_ROUTE);

        await pick(page, 'Product ID sent', 'Product ID (recommended)');
        await expect(save(page)).toBeDisabled();
    });

    test('a saved role the site no longer has is dropped on the next save', async ({ page }) => {
        await loginAsAdmin(page);
        await openApp(page, '#/dashboard');

        const v3 = (await siteState(page)).options.wcct_settings_v3 as { platforms: Record<string, Record<string, unknown>> };

        v3.platforms.facebook = { ...v3.platforms.facebook, skip_roles: ['administrator', 'ct_e2e_gone_role'] };
        await siteState(page, { options: { wcct_settings_v3: v3 } });

        await openApp(page, '#/platforms/facebook?field=skip_roles');

        const roles = page.getByTestId('wcct-facebook-skip_roles-roles');

        await expect(roles.getByRole('checkbox', { name: 'Administrator', exact: true })).toBeChecked();
        await roles.getByRole('checkbox', { name: 'Editor', exact: true }).check();
        await saveAndWait(page);
        expect((await settings(page)).body.platforms.facebook.skip_roles).toEqual(['administrator', 'editor']);

        // A new unknown role is still refused.
        expect((await settings(page, { platforms: { facebook: { skip_roles: ['administrator', 'ct_e2e_new_role'] } } })).status).toBe(400);
    });

    test('below 1280 px the help column opens as a drawer', async ({ page }) => {
        await page.setViewportSize({ width: 1024, height: 800 });
        await loginAsAdmin(page);
        await openApp(page, '#/platforms/facebook');

        await expect(page.getByTestId('wcct-platform-help')).toBeHidden();
        await page.getByTestId('wcct-help-drawer-open').click();

        const drawer = page.getByTestId('wcct-help-drawer');

        await expect(drawer).toBeVisible();
        await expect(drawer).toContainText('Where to find your Pixel ID');
        await page.keyboard.press('Escape');
        await expect(drawer).toBeHidden();
        await expect(page.getByTestId('wcct-help-drawer-open')).toBeFocused();
    });

    test('fields a role can not change are read-only and the server refuses them', async ({ page, context, jsErrors }) => {
        jsErrors.allow(/status of 403/);
        await loginAsAdmin(page);
        await context.addCookies([{ name: 'ct_e2e_readonly_platforms', value: '1', url: BASE_URL }]);
        await openApp(page, '#/platforms/facebook');

        await expect(page.getByTestId('wcct-platform-read-only')).toBeVisible();
        await expect(field(page, 'facebook', 'pixel_id')).toHaveAttribute('readonly', '');
        await expect(page.getByRole('switch', { name: 'Send Search' })).toBeDisabled();
        await expect(page.getByRole('switch', { name: 'Respect cookie choices' })).toBeDisabled();
        await expect(save(page)).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Pause tracking' })).toHaveCount(0);

        expect((await settings(page, { platforms: { facebook: { pixel_id: '555555555555555' } } })).status).toBe(403);

        await context.clearCookies({ name: 'ct_e2e_readonly_platforms' });
    });

    test('a granted shop manager (Pro) edits a platform', async ({ page }) => {
        await loginAsAdmin(page);
        await openApp(page, '#/dashboard');
        test.skip(!(await isProActive(page)), 'No Pro 3.0 is mapped into this store');
        await postAccess(page, ['shop_manager']);

        await page.context().clearCookies();
        await login(page, SHOP_MANAGER.user, SHOP_MANAGER.password);
        await openApp(page, '#/platforms/facebook');

        await expect(page.getByTestId('wcct-platform-read-only')).toHaveCount(0);
        await page.getByRole('switch', { name: 'Send Search' }).click();
        await saveAndWait(page);
        await page.getByRole('switch', { name: 'Send Search' }).click();
        await saveAndWait(page);
    });

    test('Microsoft on Free is a locked preview; X opens its page', async ({ page }) => {
        await loginAsAdmin(page);
        await openApp(page, '#/platforms/bing_ads');

        if (await isProActive(page)) {
            await expect(field(page, 'bing_ads', 'tag_id')).toBeVisible();
        } else {
            await expect(page.getByTestId('wcct-platform-bing_ads')).toContainText('Microsoft Advertising is part of Pro');
        }

        await openApp(page, '#/platforms/twitter');
        await expect(field(page, 'twitter', 'pixel_id')).toHaveValue(TEST_IDS.x!.pixelId);
        await expect(page.getByTestId('wcct-twitter-event-page_view')).toContainText('Always on');
    });
});
