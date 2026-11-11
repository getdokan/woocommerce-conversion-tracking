import { Browser, BrowserContext, Page } from '@playwright/test';
import { test, expect, JsErrors } from '../utils/fixtures';
import { NetworkTracker } from '../utils/network';
import { APP_PATH, loginAsAdmin } from '../utils/admin';
import { BASE_URL } from '../utils/site';

const LITE = 'woocommerce-conversion-tracking/conversion-tracking.php';
const PRO = 'wc-conversion-tracking-pro/woocommerce-conversion-tracking-pro.php';

const PIXEL = '1234567890123456';
const OTHER_PIXEL = '6543210987654321';
const THIRD_PIXEL = '1111222233334444';

type SetupState = {
    options: Record<string, unknown>;
    active: string[];
};

// The test site's ct-e2e/v1/state route (mu-plugins/ct-e2e-helpers.php), called with the admin's cookies.
async function siteState(page: Page, body?: object): Promise<SetupState> {
    const nonce = (await (await page.request.get('/wp-admin/admin-ajax.php?action=rest-nonce')).text()).trim();
    const response = body
        ? await page.request.post('/wp-json/ct-e2e/v1/state', { headers: { 'X-WP-Nonce': nonce }, data: body })
        : await page.request.get('/wp-json/ct-e2e/v1/state', { headers: { 'X-WP-Nonce': nonce } });

    expect(response.ok(), await response.text()).toBe(true);

    return response.json();
}

async function wcct<T>(page: Page, path: string, data?: object): Promise<T> {
    const nonce = (await (await page.request.get('/wp-admin/admin-ajax.php?action=rest-nonce')).text()).trim();
    const response = data
        ? await page.request.post(`/wp-json/wcct/v1/${path}`, { headers: { 'X-WP-Nonce': nonce }, data })
        : await page.request.get(`/wp-json/wcct/v1/${path}`, { headers: { 'X-WP-Nonce': nonce } });

    expect(response.ok(), await response.text()).toBe(true);

    return response.json();
}

// Every Conversion Tracking option but the Verify step's test link (ct-e2e/v1/options in mu-plugins/ct-e2e-helpers.php).
async function wcctOptions(page: Page): Promise<Record<string, unknown>> {
    const nonce = (await (await page.request.get('/wp-admin/admin-ajax.php?action=rest-nonce')).text()).trim();
    const response = await page.request.get('/wp-json/ct-e2e/v1/options', { headers: { 'X-WP-Nonce': nonce } });

    expect(response.ok()).toBe(true);

    const { wcct_verify: _verify, ...options } = await response.json();

    return options;
}

// Presses "Open your store" (or Try again) and returns the new test link; the admin's own tab gets a blank page, so only the visitor pings.
async function newTestLink(page: Page, opener: () => Promise<void>): Promise<string> {
    const shown = page.getByTestId('wcct-verify-link');
    const previous = (await shown.count()) ? await shown.getAttribute('href') : null;
    const popup = page.waitForEvent('popup');

    await page.context().route(
        url => url.searchParams.has('wcct_verify'),
        route => route.fulfill({ contentType: 'text/html', body: '<!doctype html><title>Test link</title>' })
    );
    await opener();

    const tab = await popup;

    await tab.waitForURL(/[?&]wcct_verify=/);

    const url = tab.url();

    await tab.close();
    await expect(shown).toHaveAttribute('href', url);
    expect(url).not.toBe(previous);

    return url;
}

type Visitor = { context: BrowserContext; page: Page };

// A logged-out shopper in another browser, behind the tracker, with the worst-case page cache on.
async function cachedVisitor(browser: Browser, tracker: NetworkTracker, jsErrors: JsErrors, cookies: string[] = []): Promise<Visitor> {
    const context = await browser.newContext({ baseURL: BASE_URL });

    await tracker.attach(context);
    context.on('page', visitor => jsErrors.watch(visitor));
    await context.addCookies(['ct_e2e_page_cache', ...cookies].map(name => ({ name, value: '1', url: BASE_URL })));

    const page = await context.newPage();

    // The home page is cached, so only the test link's own address reaches WordPress.
    await page.goto('/?ct_e2e_page_cache_flush=1');
    await page.goto('/');
    expect((await page.goto('/'))?.headers()['x-ct-cache']).toBe('HIT');

    return { context, page };
}

async function closeVisitor({ context, page }: Visitor): Promise<void> {
    await page.goto('/?ct_e2e_page_cache_flush=1');
    await context.close();
}

async function openWizard(page: Page, hash = '#/onboarding'): Promise<void> {
    await page.goto(`${APP_PATH}${hash}`);
    await expect(page.getByTestId('wcct-onboarding-steps')).toBeVisible({ timeout: 30_000 });
}

const step = (page: Page, n: number) => page.getByTestId(`wcct-onboarding-step-${n}`);
const verifyRow = (page: Page, id: string) => page.getByTestId(`wcct-verify-${id}`);
const currentDot = (page: Page) => page.locator('[data-testid="wcct-onboarding-steps"] li[aria-current="step"]');
const button = (page: Page, name: string) => page.getByRole('button', { name, exact: true });

test.describe('setup wizard', () => {
    let saved: SetupState;

    test.beforeEach(async ({ page }) => {
        await loginAsAdmin(page);
        saved = await siteState(page);
    });

    test.afterEach(async ({ page }) => {
        const now = await siteState(page);

        // Put back the options and the plugins the store had; Pro, which needs Lite, last.
        await siteState(page, {
            options: saved.options,
            activate: [LITE, PRO].filter(plugin => saved.active.includes(plugin) && !now.active.includes(plugin)),
        });
    });

    test.describe('activation redirect', () => {
        // A store that never ran Conversion Tracking, with the plugin switched off without a page view.
        const freshAndInactive = async (page: Page) =>
            siteState(page, {
                options: { wcct_install_type: 'new', wcct_onboarding: null, wcct_onboarding_redirect: null },
                deactivate: [PRO, LITE].filter(plugin => saved.active.includes(plugin)),
            });

        test('a fresh activation opens the wizard once', async ({ page }) => {
            await freshAndInactive(page);
            await page.goto('/wp-admin/plugins.php');
            await page.locator(`tr[data-plugin="${LITE}"] .activate a`).click();

            await expect(page).toHaveURL(/page=conversion-tracking#\/onboarding\?step=1$/, { timeout: 30_000 });
            await expect(step(page, 1).getByRole('heading', { level: 1 })).toHaveText('Show your ads which visits turn into sales');
            await expect(currentDot(page)).toContainText('Welcome');
            expect((await siteState(page)).options.wcct_onboarding_redirect).toBe('done');

            // The next admin page stays where it is.
            await page.goto('/wp-admin/plugins.php');
            await expect(page).toHaveURL(/plugins\.php$/);
        });

        test('a bulk activation never redirects', async ({ page }) => {
            await freshAndInactive(page);
            await page.goto('/wp-admin/plugins.php');
            const checkbox = page.getByRole('checkbox', { name: 'Select Conversion Tracking for WooCommerce', exact: true });

            // With Pro installed, WordPress offers no bulk action on Lite, which Pro requires.
            test.skip(await checkbox.isDisabled(), 'Lite has no bulk checkbox while Pro is installed');
            await checkbox.check();
            await page.locator('#bulk-action-selector-top').selectOption('activate-selected');
            await page.locator('#doaction').click();

            await expect(page).toHaveURL(/\/wp-admin\/plugins\.php/);
            await expect(page.locator('#message')).toContainText('activated');
            await expect(page.locator(`tr[data-plugin="${LITE}"]`)).toHaveClass(/\bactive\b/);
            expect((await siteState(page)).options.wcct_onboarding_redirect).toBeNull();

            await page.goto('/wp-admin/index.php');
            await expect(page).toHaveURL(/\/wp-admin\/index\.php$/);
        });

        test('an upgrade from 2.x never redirects', async ({ page }) => {
            await freshAndInactive(page);
            await siteState(page, { options: { wcct_install_type: 'upgrade' } });
            await page.goto('/wp-admin/plugins.php');
            await page.locator(`tr[data-plugin="${LITE}"] .activate a`).click();

            // WordPress drops activate=true from the address once the page loads.
            await expect(page.locator(`tr[data-plugin="${LITE}"]`)).toHaveClass(/\bactive\b/);
            await expect(page).toHaveURL(/\/wp-admin\/plugins\.php/);
            expect((await siteState(page)).options.wcct_onboarding_redirect).toBeNull();

            await page.goto('/wp-admin/index.php');
            await expect(page).toHaveURL(/\/wp-admin\/index\.php$/);
        });
    });

    test.describe('wizard', () => {
        test.beforeEach(async ({ page }) => {
            // A new store with nothing connected and the wizard never opened.
            await siteState(page, {
                // No test link left open by another spec, which the Verify step would pick up.
                options: { wcct_onboarding: null, wcct_settings_v3: { consent_mode: false, platforms: {} }, wcct_verify: null },
            });
        });

        test('skip, resume at the furthest step, then finish', async ({ page, browser, tracker, jsErrors }) => {
            await openWizard(page);
            await expect(currentDot(page)).toContainText('Welcome');
            await button(page, 'Start').click();

            // Step 2: Meta and Google start picked.
            await expect(step(page, 2)).toBeVisible();
            await expect(page).toHaveURL(/#\/onboarding\?step=2$/);
            await expect(page.getByTestId('wcct-pick-facebook')).toHaveAttribute('aria-pressed', 'true');
            await expect(page.getByTestId('wcct-pick-google')).toHaveAttribute('aria-pressed', 'true');
            await expect(page.getByTestId('wcct-pick-facebook')).toContainText('Popular');
            await expect(page.getByTestId('wcct-pick-twitter')).toHaveAttribute('aria-pressed', 'false');
            await expect(page.getByTestId('wcct-onboarding-note')).toHaveText('2 selected.');
            await page.getByTestId('wcct-pick-twitter').click();
            await expect(page.getByTestId('wcct-onboarding-note')).toHaveText('3 selected.');
            await button(page, 'Continue').click();

            // Step 3: one panel per pick, format checked as typed.
            await expect(step(page, 3)).toBeVisible();
            await expect(page.getByTestId('wcct-connect-facebook')).toBeVisible();
            await expect(page.getByTestId('wcct-connect-google')).toBeVisible();
            await expect(page.getByTestId('wcct-connect-twitter').locator('input')).toHaveCount(2);
            await expect(page.getByTestId('wcct-connect-twitter')).toContainText('Purchase event ID');
            await expect(button(page, 'Continue')).toBeDisabled();

            await page.locator('#wcct-connect-facebook-pixel_id').fill('EAA1234');
            await expect(page.getByTestId('wcct-connect-facebook')).toContainText('Pixel IDs are numbers only.');
            await expect(button(page, 'Continue')).toBeDisabled();
            await page.locator('#wcct-connect-facebook-pixel_id').fill(PIXEL);
            await expect(page.getByTestId('wcct-connect-facebook')).toContainText('Format looks right. Meta confirms it once events arrive.');
            await expect(page.getByTestId('wcct-connect-facebook')).not.toContainText('Check it\'s yours');
            await expect(page.getByTestId('wcct-onboarding-note')).toHaveText('1 of 3 ready to connect.');

            // Skip: back to the dashboard checklist; nothing on this step is saved.
            await button(page, 'Skip for now').click();
            await expect(page).toHaveURL(/#\/dashboard$/);
            await expect(page.getByTestId('wcct-welcome')).toBeVisible();
            await expect(page.getByTestId('wcct-setup-checklist').locator('li[data-done]')).toHaveText([
                /Plugin installed/,
                /Pick where you run ads/,
            ]);
            expect(await wcct(page, 'onboarding')).toEqual({
                status: 'skipped',
                step: 3,
                platforms: ['facebook', 'google', 'twitter'],
                eu: '',
            });

            // Continue setup resumes at the furthest step, with the picks kept.
            await page.getByRole('button', { name: 'Continue setup' }).click();
            await expect(step(page, 3)).toBeVisible();
            await expect(page).toHaveURL(/#\/onboarding\?step=3$/);
            await expect(currentDot(page)).toContainText('Connect');
            await expect(page.getByTestId('wcct-connect-twitter')).toBeVisible();

            await page.locator('#wcct-connect-facebook-pixel_id').fill(PIXEL);
            await page.locator('#wcct-connect-google-measurement_id').fill('g-test123');
            await expect(page.getByTestId('wcct-onboarding-note')).toHaveText('2 of 3 ready to connect.');

            // An Ads ID needs its conversion label.
            await page.locator('#wcct-connect-google-ads_id').fill('AW-123456');
            await expect(page.getByTestId('wcct-connect-google')).toContainText('Add the conversion label to count Ads conversions.');
            await expect(page.getByTestId('wcct-onboarding-note')).toHaveText('1 of 3 ready to connect.');
            await page.locator('#wcct-connect-google-purchase_label').fill('AbC_123-x');
            await expect(page.getByTestId('wcct-onboarding-note')).toHaveText('2 of 3 ready to connect.');

            // Back keeps what was typed.
            await button(page, 'Back').click();
            await expect(step(page, 2)).toBeVisible();
            await button(page, 'Continue').click();
            await expect(page.locator('#wcct-connect-facebook-pixel_id')).toHaveValue(PIXEL);
            await button(page, 'Continue').click();

            // Step 4: the IDs are saved through the settings route.
            await expect(step(page, 4)).toBeVisible();
            const settings = await wcct<{ consent_mode: boolean; platforms: Record<string, Record<string, unknown>> }>(page, 'settings');

            expect(settings.platforms.facebook.pixel_id).toBe(PIXEL);
            expect(settings.platforms.google.measurement_id).toBe('G-TEST123');
            expect(settings.platforms.google.ads_id).toBe('AW-123456');
            expect(settings.platforms.google.purchase_label).toBe('AbC_123-x');
            expect(settings.platforms.google.mode).toBe('both');
            expect(settings.platforms.twitter.pixel_id).toBe('');

            // Clearing a saved ID clears it on the server too.
            await button(page, 'Back').click();
            await expect(step(page, 3)).toBeVisible();
            await page.locator('#wcct-connect-facebook-pixel_id').fill('');
            await expect(page.getByTestId('wcct-onboarding-note')).toHaveText('1 of 3 ready to connect.');
            await button(page, 'Continue').click();
            await expect(step(page, 4)).toBeVisible();
            const cleared = await wcct<{ platforms: Record<string, Record<string, unknown>> }>(page, 'settings');

            expect(cleared.platforms.facebook.pixel_id).toBe('');
            expect(cleared.platforms.google.measurement_id).toBe('G-TEST123');

            await expect(button(page, 'Continue')).toBeDisabled();
            await page.getByRole('radio', { name: /^Yes/ }).click();
            await expect(page.getByTestId('wcct-onboarding-eu-note')).toContainText('Respect cookie choices is on.');
            await button(page, 'Continue').click();

            // Step 5: Verify (#120 waits for the check-in here).
            await expect(step(page, 5)).toBeVisible();
            expect((await wcct<{ consent_mode: boolean }>(page, 'settings')).consent_mode).toBe(true);
            // The switch the storefront reads (ConsentMode).
            expect((await siteState(page)).options.wcct_consent_mode).toBe('on');
            await expect(step(page, 5)).toContainText('Google');
            await expect(step(page, 5)).not.toContainText('Meta');

            // Continue waits for the test link's page view.
            await expect(verifyRow(page, 'google')).toContainText('Waiting for the first event…');
            await expect(button(page, 'Continue')).toBeDisabled();

            const link = await newTestLink(page, () => page.getByTestId('wcct-verify-open').click());
            const shopper = await cachedVisitor(browser, tracker, jsErrors);

            await shopper.page.goto(link);
            await expect(verifyRow(page, 'google')).toHaveAttribute('data-state', /^(ok|warn)$/, { timeout: 30_000 });
            await expect(button(page, 'Continue')).toBeEnabled();
            await closeVisitor(shopper);
            await button(page, 'Continue').click();

            // Step 6: the summary, then the dashboard.
            await expect(step(page, 6)).toBeVisible();
            await expect(page.getByTestId('wcct-onboarding-summary')).toContainText('Google: sending 4 recommended events');
            await expect(page.getByTestId('wcct-onboarding-summary')).not.toContainText('Meta');
            await expect(page.getByTestId('wcct-onboarding-summary')).toContainText('Respect cookie choices: on');
            await expect(page.getByTestId('wcct-onboarding-summary')).not.toContainText('Other connected platforms');
            await button(page, 'Go to dashboard').click();

            await expect(page).toHaveURL(/#\/dashboard$/);
            await expect(page.getByTestId('wcct-dashboard')).toBeVisible();
            await expect(page.getByTestId('wcct-welcome')).toHaveCount(0);
            expect(await wcct(page, 'onboarding')).toEqual({
                status: 'done',
                step: 6,
                platforms: ['facebook', 'google', 'twitter'],
                eu: 'yes',
            });

            await openWizard(page);
            await expect(page.getByRole('heading', { level: 1 })).toHaveText('Setup is finished');
        });

        test('leaving the wizard with an unsaved ID asks first; Microsoft waits for Pro on Free', async ({ page }) => {
            await siteState(page, {
                options: { wcct_onboarding: { status: 'in_progress', step: 3, platforms: ['facebook'], eu: '' } },
            });
            await openWizard(page);
            await expect(step(page, 3)).toBeVisible();
            await page.locator('#wcct-connect-facebook-pixel_id').fill(PIXEL);

            const dialogs: string[] = [];

            page.on('dialog', dialog => {
                dialogs.push(dialog.message());
                void dialog.dismiss();
            });
            await page.locator('#wcct-topbar-root a[href="#/dashboard"]').click();
            await expect.poll(() => dialogs.length).toBe(1);
            expect(dialogs[0]).toContain('unsaved changes');
            await expect(page).toHaveURL(/#\/onboarding\?step=3$/);
            await expect(page.locator('#wcct-connect-facebook-pixel_id')).toHaveValue(PIXEL);

            // Steps within the wizard keep the draft without asking.
            await button(page, 'Back').click();
            await expect(step(page, 2)).toBeVisible();
            expect(dialogs).toHaveLength(1);

            const plan = await page.evaluate(() => {
                const admin = (window as unknown as { wcctAdmin?: { proVersion: string; proActive: boolean | string } }).wcctAdmin;

                return { version: admin?.proVersion ?? '', active: !!admin?.proActive };
            });

            await page.getByTestId('wcct-pick-bing_ads').click();
            await button(page, 'Continue').click();

            const microsoft = page.getByTestId('wcct-connect-bing_ads');

            if (plan.active) {
                await expect(page.locator('#wcct-connect-bing_ads-tag_id')).toBeVisible();
            } else if (plan.version) {
                // A Pro older than 3.0 is paused: Microsoft stays locked, with the way to update.
                await expect(microsoft).toContainText('needs Pro 3.0');
                await expect(microsoft.getByRole('link', { name: 'Update Pro' })).toBeVisible();
                await expect(microsoft.locator('input')).toHaveCount(0);
            } else {
                await expect(microsoft).toContainText('part of Pro');
                await expect(microsoft.getByRole('link', { name: /Compare plans/ })).toHaveAttribute(
                    'href',
                    'https://woocommerce.com/products/woocommerce-conversion-tracking-pro/'
                );
                await expect(microsoft.locator('input')).toHaveCount(0);
            }
        });

        test('a finished setup with nothing connected points to the Platforms page', async ({ page }) => {
            await siteState(page, {
                options: { wcct_onboarding: { status: 'done', step: 6, platforms: ['facebook'], eu: 'no' } },
            });
            await page.goto(`${APP_PATH}#/dashboard`);

            const welcome = page.getByTestId('wcct-welcome');

            await expect(welcome).toBeVisible({ timeout: 30_000 });
            await expect(welcome.getByTestId('wcct-setup-checklist')).toHaveCount(0);
            await expect(welcome.getByRole('button', { name: 'Connect a platform' })).toHaveAttribute('href', '#/platforms');
        });

        test('"No" leaves Respect cookie choices off', async ({ page }) => {
            await siteState(page, {
                options: {
                    wcct_onboarding: { status: 'in_progress', step: 4, platforms: ['facebook'], eu: '' },
                    wcct_settings_v3: { consent_mode: true, platforms: {} },
                },
            });
            await openWizard(page);
            await expect(step(page, 4)).toBeVisible();
            await page.getByRole('radio', { name: /^No/ }).click();
            await expect(page.getByTestId('wcct-onboarding-eu-note')).toHaveText('Respect cookie choices stays off.');
            await button(page, 'Continue').click();

            await expect(step(page, 5)).toBeVisible();
            expect((await wcct<{ consent_mode: boolean }>(page, 'settings')).consent_mode).toBe(false);
            expect((await siteState(page)).options.wcct_consent_mode).toBe('off');
        });

        test('a save during the update to 3.0 can be tried again', async ({ page, jsErrors }) => {
            jsErrors.allow(/409/);
            await siteState(page, {
                options: { wcct_onboarding: { status: 'skipped', step: 4, platforms: ['facebook'], eu: '' } },
            });
            await openWizard(page);
            await expect(step(page, 4)).toBeVisible();

            // The settings route answers 409 while Store::migration_pending().
            await page.route(/wcct(\/|%2F)v1(\/|%2F)settings/, route =>
                route.request().method() === 'POST'
                    ? route.fulfill({
                          status: 409,
                          contentType: 'application/json',
                          body: JSON.stringify({ code: 'wcct_rest_migration_pending', message: 'Pending.', data: { status: 409 } }),
                      })
                    : route.fallback()
            );
            await page.getByRole('radio', { name: /^Yes/ }).click();
            await button(page, 'Continue').click();

            await expect(page.getByTestId('wcct-onboarding-error')).toContainText(
                'Your settings are still being updated to 3.0. Try again in a moment.'
            );
            await expect(step(page, 4)).toBeVisible();

            await page.unroute(/wcct(\/|%2F)v1(\/|%2F)settings/);
            await button(page, 'Try again').click();
            await expect(step(page, 5)).toBeVisible();
            expect((await wcct<{ consent_mode: boolean }>(page, 'settings')).consent_mode).toBe(true);
        });
    });

    test.describe('Verify step', () => {
        test.beforeEach(async ({ page }) => {
            await siteState(page, {
                options: {
                    wcct_onboarding: { status: 'in_progress', step: 5, platforms: ['facebook'], eu: 'no' },
                    wcct_verify: null,
                },
            });
            // Meta connected through the settings route, as step 3 saves it.
            await wcct(page, 'settings', { consent_mode: false, platforms: { facebook: { pixel_id: PIXEL } } });
        });

        test('turns green from the test link on a cached store, after the hint and a retry', async ({ page, browser, tracker, jsErrors }) => {
            await page.clock.install();
            await openWizard(page, '#/onboarding?step=5');
            await expect(step(page, 5)).toBeVisible();
            await expect(currentDot(page)).toContainText('Verify');

            const row = verifyRow(page, 'facebook');
            const status = page.getByTestId('wcct-verify-status');

            await expect(row).toHaveAttribute('data-state', 'idle');
            await expect(row).toContainText('Waiting for the first event…');
            await expect(button(page, 'Continue')).toBeDisabled();

            const first = await newTestLink(page, () => page.getByTestId('wcct-verify-open').click());

            expect(new URL(first).pathname).toBe('/');
            await expect(row).toHaveAttribute('data-state', 'busy');
            await expect(status).toHaveText('Waiting for the first event from your store.');

            // Nobody opens it: after a minute the hint shows, with a retry.
            await page.clock.fastForward(61_000);

            const hint = page.getByTestId('wcct-verify-hint');

            await expect(hint).toContainText("We haven't heard from your store yet.");
            await expect(hint).toContainText('A page cache or an optimization plugin');
            await expect(hint.getByRole('link', { name: 'Open the Doctor' })).toHaveAttribute('href', '#/doctor');
            await expect(status).toHaveText('Nothing has arrived yet. Still checking.');
            await expect(button(page, 'Continue')).toBeDisabled();

            const second = await newTestLink(page, () => hint.getByRole('button', { name: /^Try again/ }).click());

            await expect(hint).toHaveCount(0);
            // The hint is gone, so focus moves to Open your store.
            await expect(page.getByTestId('wcct-verify-open')).toBeFocused();

            // A reload picks up the open test and keeps listening.
            await page.reload();
            await expect(step(page, 5)).toBeVisible({ timeout: 30_000 });
            await expect(page.getByTestId('wcct-verify-link')).toHaveAttribute('href', second);
            await expect(row).toHaveAttribute('data-state', 'busy');

            // A shopper behind the page cache: the home page is a cache hit, the test link is its own address.
            const shopper = await cachedVisitor(browser, tracker, jsErrors);

            expect((await shopper.page.goto(second))?.headers()['x-ct-cache']).toBe('MISS');
            await expect(row).toHaveAttribute('data-state', 'ok', { timeout: 30_000 });
            await expect(row).toContainText('First event sent: Page view, just now.');
            await expect(status).toHaveText('First event sent to Meta.');
            await expect(button(page, 'Continue')).toBeEnabled();

            // The cached copy of the test link is throttled, so a reload pings no more.
            expect((await shopper.page.reload())?.headers()['x-ct-cache']).toBe('HIT');
            await closeVisitor(shopper);

            await button(page, 'Continue').click();
            await expect(step(page, 6)).toBeVisible();
            await expect.poll(async () => (await wcct<{ step: number }>(page, 'onboarding')).step).toBe(6);
        });

        test('a blocked script says why, and only Skip for now leaves', async ({ page, browser, tracker, jsErrors }) => {
            await openWizard(page, '#/onboarding?step=5');
            await expect(step(page, 5)).toBeVisible();

            const link = await newTestLink(page, () => page.getByTestId('wcct-verify-open').click());
            const shopper = await cachedVisitor(browser, tracker, jsErrors);

            // Like an ad blocker: fbevents.js never loads.
            jsErrors.allow(/ERR_BLOCKED_BY_CLIENT|Failed to load resource/);
            await shopper.context.route(/connect\.facebook\.net|facebook\.com\/tr/, route => route.abort('blockedbyclient'));
            await shopper.page.goto(link);

            const row = verifyRow(page, 'facebook');
            const hint = page.getByTestId('wcct-verify-hint');

            await expect(row).toHaveAttribute('data-state', 'warn', { timeout: 30_000 });
            await expect(row).toContainText("Your store checked in, but this platform's script didn't load.");
            await expect(hint).toContainText('Your store checked in, but nothing was sent to Meta.');
            await expect(hint).toContainText('An ad blocker or a browser privacy setting');
            await expect(hint).not.toContainText('Respect cookie choices');
            await expect(page.getByTestId('wcct-verify-status')).toHaveText('Not sent to Meta.');
            // As in the prototype, Continue waits for one platform to send; Skip for now is the way out.
            await expect(hint).toContainText('Continue opens once one platform sends its first event. You can also skip for now');
            await expect(button(page, 'Continue')).toBeDisabled();
            await expect(button(page, 'Skip for now')).toBeEnabled();
            await closeVisitor(shopper);
        });

        test('turns green through admin-ajax when the host blocks guest REST', async ({ page, browser, tracker, jsErrors }) => {
            await openWizard(page, '#/onboarding?step=5');
            await expect(step(page, 5)).toBeVisible();

            const link = await newTestLink(page, () => page.getByTestId('wcct-verify-open').click());
            // The ct_e2e_block_rest cookie answers 403 on wcct/v1/events, so the ping retries on admin-ajax.
            const shopper = await cachedVisitor(browser, tracker, jsErrors, ['ct_e2e_block_rest']);
            const pings: string[] = [];

            jsErrors.allow(/status of 403/);
            shopper.context.on('request', request => {
                if (request.method() === 'POST' && /wcct\/v1\/events|admin-ajax\.php/.test(request.url())) {
                    pings.push(`${new URL(request.url()).pathname} ${request.postData() ?? ''}`);
                }
            });
            await shopper.page.goto(link);

            await expect(verifyRow(page, 'facebook')).toHaveAttribute('data-state', 'ok', { timeout: 30_000 });
            await expect(button(page, 'Continue')).toBeEnabled();
            expect(pings.map(ping => ping.split(' ')[0])).toEqual(['/wp-json/wcct/v1/events', '/wp-admin/admin-ajax.php']);
            expect(pings[1]).toContain('action=wcct_events&type=seen&token=');
            await closeVisitor(shopper);
        });
    });

    test.describe('run again', () => {
        test.beforeEach(async ({ page }) => {
            // A finished setup with Meta connected and Respect cookie choices off.
            await siteState(page, {
                options: {
                    wcct_onboarding: { status: 'done', step: 6, platforms: ['facebook'], eu: 'no' },
                    wcct_settings_v3: { consent_mode: false, platforms: {} },
                    wcct_verify: null,
                },
            });
            await wcct(page, 'settings', { consent_mode: false, platforms: { facebook: { pixel_id: PIXEL } } });
        });

        // Settings > Setup > Run setup wizard again.
        async function openRerun(page: Page): Promise<void> {
            await page.goto(`${APP_PATH}#/settings/setup`);

            const rerun = page.getByTestId('wcct-setup-rerun');

            await expect(rerun).toHaveText('Run setup wizard again', { timeout: 30_000 });
            await expect(rerun).toHaveAttribute('href', '#/onboarding?rerun=1&step=1');
            await rerun.click();

            await expect(step(page, 1)).toBeVisible();
            await expect(currentDot(page)).toContainText('Welcome');
            await expect(button(page, 'Close without saving')).toBeVisible();
            await expect(button(page, 'Skip for now')).toHaveCount(0);
        }

        // Steps 1 to 4 of a re-run, with a new Pixel ID and Europe "Yes".
        async function rerunToVerify(page: Page): Promise<void> {
            await button(page, 'Start').click();

            // The connected platform is picked; picks stay in the draft too.
            await expect(step(page, 2)).toBeVisible();
            await expect(page).toHaveURL(/#\/onboarding\?rerun=1&step=2$/);
            await expect(currentDot(page)).toContainText('Platforms');
            await expect(page.getByTestId('wcct-pick-facebook')).toHaveAttribute('aria-pressed', 'true');
            await expect(page.getByTestId('wcct-pick-google')).toHaveAttribute('aria-pressed', 'false');
            await page.getByTestId('wcct-pick-google').click();
            await button(page, 'Continue').click();

            await expect(step(page, 3)).toBeVisible();
            await expect(currentDot(page)).toContainText('Connect');
            await expect(page.locator('#wcct-connect-facebook-pixel_id')).toHaveValue(PIXEL);
            await page.locator('#wcct-connect-facebook-pixel_id').fill(OTHER_PIXEL);
            await button(page, 'Continue').click();

            await expect(step(page, 4)).toBeVisible();
            await expect(page.getByRole('radio', { name: /^No/ })).toBeChecked();
            await page.getByRole('radio', { name: /^Yes/ }).click();
            await button(page, 'Continue').click();

            // The new ID isn't live yet, so Verify doesn't hold Continue.
            await expect(step(page, 5)).toBeVisible();
            await expect(page).toHaveURL(/#\/onboarding\?rerun=1&step=5$/);
            await expect(currentDot(page)).toContainText('Verify');
            await expect(verifyRow(page, 'facebook')).toContainText('Saved when you finish.');
            await expect(button(page, 'Continue')).toBeEnabled();
        }

        test('closing the re-run or its window changes no option', async ({ page, context }) => {
            await openRerun(page);

            const before = await wcctOptions(page);

            await rerunToVerify(page);
            await button(page, 'Continue').click();
            await expect(step(page, 6)).toBeVisible();
            await expect(currentDot(page)).toContainText('Done');
            await expect(button(page, 'Save and go to Settings')).toBeVisible();
            expect(await wcctOptions(page)).toEqual(before);

            // Close without saving, from step 5, asks nothing and saves nothing.
            const dialogs: string[] = [];

            page.on('dialog', dialog => {
                dialogs.push(dialog.message());
                void dialog.dismiss();
            });
            await page.goBack();
            await expect(step(page, 5)).toBeVisible();
            await button(page, 'Close without saving').click();
            await expect(page).toHaveURL(/#\/settings\/setup$/);
            await expect(page.getByTestId('wcct-setup-rerun')).toBeVisible();
            expect(dialogs).toEqual([]);
            expect(await wcctOptions(page)).toEqual(before);

            // Closing the window halfway saves nothing either.
            const other = await context.newPage();

            await other.goto(`${APP_PATH}#/onboarding?rerun=1&step=1`);
            await button(other, 'Start').click();
            await button(other, 'Continue').click();
            await other.locator('#wcct-connect-facebook-pixel_id').fill(OTHER_PIXEL);
            await button(other, 'Continue').click();
            await expect(step(other, 4)).toBeVisible();
            await other.close({ runBeforeUnload: false });
            expect(await wcctOptions(page)).toEqual(before);
        });

        test('finishing the re-run saves the draft', async ({ page }) => {
            await openRerun(page);
            await rerunToVerify(page);
            await button(page, 'Continue').click();
            await expect(page.getByTestId('wcct-onboarding-summary')).toContainText('Meta: sending');
            await expect(page.getByTestId('wcct-onboarding-summary')).toContainText('Respect cookie choices: on');
            await expect(page.getByTestId('wcct-onboarding-summary')).toContainText('Other connected platforms keep their settings.');

            // Meta's ID changed elsewhere since the draft started: finishing asks first, and No keeps the other save.
            await wcct(page, 'settings', { platforms: { facebook: { pixel_id: THIRD_PIXEL } } });

            const dialogs: string[] = [];
            let answer = false;

            page.on('dialog', dialog => {
                dialogs.push(dialog.message());
                void (answer ? dialog.accept() : dialog.dismiss());
            });
            await button(page, 'Save and go to Settings').click();
            await expect.poll(() => dialogs.length).toBe(1);
            expect(dialogs[0]).toContain('Meta changed since you opened the wizard');
            await expect(step(page, 6)).toBeVisible();
            expect((await wcct<{ platforms: Record<string, Record<string, unknown>> }>(page, 'settings')).platforms.facebook.pixel_id).toBe(THIRD_PIXEL);

            answer = true;
            await button(page, 'Save and go to Settings').click();

            await expect(page).toHaveURL(/#\/settings\/setup$/);
            await expect(page.getByTestId('wcct-setup-rerun')).toBeVisible();

            const settings = await wcct<{ consent_mode: boolean; platforms: Record<string, Record<string, unknown>> }>(page, 'settings');

            expect(settings.platforms.facebook.pixel_id).toBe(OTHER_PIXEL);
            expect(settings.consent_mode).toBe(true);
            expect((await siteState(page)).options.wcct_consent_mode).toBe('on');
            expect(await wcct(page, 'onboarding')).toEqual({ status: 'done', step: 6, platforms: ['facebook'], eu: 'no' });
        });

        test('a finished setup opens on Done, and an unfinished one continues', async ({ page }) => {
            await openWizard(page);
            await expect(page.getByRole('heading', { level: 1 })).toHaveText('Setup is finished');
            await expect(page).toHaveURL(/#\/onboarding\?step=6$/);
            await expect(currentDot(page)).toContainText('Done');
            await expect(page.getByRole('button', { name: 'Run setup wizard again' })).toHaveAttribute('href', '#/onboarding?rerun=1&step=1');

            await siteState(page, {
                options: { wcct_onboarding: { status: 'skipped', step: 3, platforms: ['facebook'], eu: '' } },
            });
            await page.goto(`${APP_PATH}#/settings/setup`);

            const resume = page.getByTestId('wcct-setup-rerun');

            await expect(resume).toHaveText('Continue setup', { timeout: 30_000 });
            await expect(resume).toHaveAttribute('href', '#/onboarding');
            await expect(page.getByTestId('wcct-settings-setup')).toContainText('Pick up the setup where you left it.');
        });
    });
});
