import { Page } from '@playwright/test';
import { test, expect } from '../utils/fixtures';
import { APP_PATH, loginAsAdmin } from '../utils/admin';

const LITE = 'woocommerce-conversion-tracking/conversion-tracking.php';
const PRO = 'wc-conversion-tracking-pro/woocommerce-conversion-tracking-pro.php';

const PIXEL = '1234567890123456';

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

async function wcct<T>(page: Page, path: string): Promise<T> {
    const nonce = (await (await page.request.get('/wp-admin/admin-ajax.php?action=rest-nonce')).text()).trim();
    const response = await page.request.get(`/wp-json/wcct/v1/${path}`, { headers: { 'X-WP-Nonce': nonce } });

    expect(response.ok()).toBe(true);

    return response.json();
}

async function openWizard(page: Page, hash = '#/onboarding'): Promise<void> {
    await page.goto(`${APP_PATH}${hash}`);
    await expect(page.getByTestId('wcct-onboarding-steps')).toBeVisible({ timeout: 30_000 });
}

const step = (page: Page, n: number) => page.getByTestId(`wcct-onboarding-step-${n}`);
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
                options: { wcct_onboarding: null, wcct_settings_v3: { consent_mode: false, platforms: {} } },
            });
        });

        test('skip, resume at the furthest step, then finish', async ({ page }) => {
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
            await expect(page.getByTestId('wcct-connect-facebook')).toContainText('Pixel IDs are 15 or 16 digits, numbers only.');
            await expect(button(page, 'Continue')).toBeDisabled();
            await page.locator('#wcct-connect-facebook-pixel_id').fill(PIXEL);
            await expect(page.getByTestId('wcct-connect-facebook')).toContainText('Looks like a Pixel ID.');
            await expect(page.getByTestId('wcct-connect-facebook')).toContainText('Looks right');
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
            await button(page, 'Continue').click();

            // Step 6: the summary, then the dashboard.
            await expect(step(page, 6)).toBeVisible();
            await expect(page.getByTestId('wcct-onboarding-summary')).toContainText('Google: sending 4 recommended events');
            await expect(page.getByTestId('wcct-onboarding-summary')).not.toContainText('Meta');
            await expect(page.getByTestId('wcct-onboarding-summary')).toContainText('Respect cookie choices: on');
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
});
