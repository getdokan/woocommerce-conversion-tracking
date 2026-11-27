import { Page } from '@playwright/test';
import { test, expect } from '../utils/fixtures';
import { APP_PATH, SHOP_MANAGER, isProActive, login, loginAsAdmin, openApp, wcctAdminData } from '../utils/admin';

// Admin\Upgrade: Pro is sold only on WooCommerce.com.
const PRODUCT_URL = 'https://woocommerce.com/products/woocommerce-conversion-tracking-pro/';
const SUBSCRIPTIONS_URL = 'https://woocommerce.com/my-account/my-subscriptions/';

const ACCESS_ROUTE = /wcct(\/|%2F)v1(\/|%2F)access/;
const SETTINGS_ROUTE = /wcct(\/|%2F)v1(\/|%2F)settings(\?|&|$)/;
const SECTIONS = 'nav[aria-label="Settings sections"]';

const proVersion = async (page: Page): Promise<string> => (await wcctAdminData(page)).proVersion;

// Saves the granted roles through the access route, as the logged-in user.
const postAccess = (page: Page, roles: string[]): Promise<{ ok: boolean; status?: number; code?: string }> =>
    page.evaluate(async data => {
        const wp = (window as unknown as { wp: { apiFetch: (options: object) => Promise<unknown> } }).wp;

        try {
            await wp.apiFetch({ path: '/wcct/v1/access', method: 'POST', data: { roles: data } });

            return { ok: true };
        } catch (error) {
            const { code, data: info } = error as { code?: string; data?: { status?: number } };

            return { ok: false, code, status: info?.status };
        }
    }, roles);

test.describe('Settings', () => {
    test.beforeEach(async ({ page }) => {
        await loginAsAdmin(page);
    });

    test('#/settings opens Alerts, and an unknown section goes back to it', async ({ page }) => {
        await openApp(page, '#/settings');
        await expect(page.locator(`${SECTIONS} a[aria-current="page"]`)).toHaveText('Alerts');
        await expect(page.getByTestId('wcct-settings-alerts')).toBeVisible();

        await openApp(page, '#/settings/nope');
        await expect(page).toHaveURL(/#\/settings$/);
        await expect(page.locator(`${SECTIONS} a[aria-current="page"]`)).toHaveText('Alerts');

        const reads: string[] = [];

        page.on('request', request => {
            if (SETTINGS_ROUTE.test(request.url())) {
                reads.push(request.url());
            }
        });
        await page.locator(`${SECTIONS} a`, { hasText: 'Plan' }).click();
        await expect(page).toHaveURL(/#\/settings\/plan$/);
        // Focus moves to the section's heading, not back to the page heading.
        await expect(page.getByRole('heading', { level: 2, name: 'Plan' })).toBeFocused();
        // Another section of the same page doesn't re-read the settings.
        expect(reads).toEqual([]);
    });

    test.describe('on Free', () => {
        test.beforeEach(async ({ page }) => {
            await openApp(page, '#/settings');
            test.skip(!!(await proVersion(page)), 'Pro is installed in this store');
        });

        test('Integration access is a locked preview with the way to Pro', async ({ page }) => {
            const reads: string[] = [];

            page.on('request', request => {
                if (ACCESS_ROUTE.test(request.url())) {
                    reads.push(request.url());
                }
            });
            await openApp(page, '#/settings/access');

            const section = page.getByTestId('wcct-settings-access');

            await expect(page.locator(`${SECTIONS} a[aria-current="page"]`)).toContainText('Integration access');
            await expect(page.locator(`${SECTIONS} a`, { hasText: 'Integration access' })).toContainText('PRO');
            await expect(section.getByRole('heading', { name: 'Role access is part of Pro' })).toBeVisible();
            await expect(section).toContainText('Administrators always keep access');
            await expect(section.getByRole('checkbox')).toHaveCount(0);

            const compare = section.getByRole('link', { name: /Compare plans/ });

            await expect(compare).toHaveAttribute('href', PRODUCT_URL);
            await expect(compare).toHaveAttribute('target', '_blank');
            await expect(compare).toContainText('(opens in a new tab)');
            await expect(section.getByRole('link', { name: 'Continue with Free' })).toHaveAttribute('href', '#/dashboard');
            await expect(section.getByRole('link', { name: 'Meta catalog feed' })).toHaveAttribute('href', '#/catalog');
            // Free has no access logic, so the route isn't read.
            expect(reads).toEqual([]);
        });

        test('Plan compares Free and Pro, with no price, and upgrades on WooCommerce.com', async ({ page }) => {
            await openApp(page, '#/settings/plan');

            const section = page.getByTestId('wcct-settings-plan');

            await expect(section).toContainText("You're on Free. Here's what Pro adds.");
            await expect(page.getByTestId('wcct-plan-free')).toContainText('Your plan');
            await expect(page.getByTestId('wcct-plan-free').getByRole('listitem')).toHaveCount(6);
            await expect(page.getByTestId('wcct-plan-pro').getByRole('listitem')).toHaveCount(7);
            await expect(page.getByTestId('wcct-plan-pro')).not.toContainText('Your plan');

            const upgrade = section.getByRole('link', { name: /Upgrade to Pro/ });

            await expect(upgrade).toHaveAttribute('href', PRODUCT_URL);
            await expect(upgrade).toHaveAttribute('target', '_blank');
            await expect(section.getByRole('link', { name: /See pricing/ })).toHaveAttribute('href', PRODUCT_URL);
            await expect(section.getByRole('link', { name: 'Choose which roles can manage tracking' })).toHaveAttribute(
                'href',
                '#/settings/access'
            );
            await expect(section).not.toContainText('Manage your subscription');
            // No price string anywhere: "See pricing" opens the listing.
            await expect(section).not.toContainText(/\[PRICE\]|\$\s?\d|per year|money-back/);
        });

        test('every upgrade link in the app and on the plugins list goes to WooCommerce.com', async ({ page }) => {
            await openApp(page, '#/dashboard');

            const microsoft = page.getByTestId('wcct-platform-card-bing_ads');

            await expect(microsoft.getByRole('link', { name: /Compare plans/ })).toHaveAttribute('href', PRODUCT_URL);

            await page.goto('/wp-admin/plugins.php');

            const getPro = page.getByRole('row', { name: /^Select Conversion Tracking for WooCommerce\b/ }).getByRole('link', { name: 'Get PRO' });

            await expect(getPro).toHaveAttribute('href', PRODUCT_URL);
            await expect(page.locator('a[href*="wedevs.com/woocommerce-conversion-tracking"]')).toHaveCount(0);
        });
    });

    test.describe('on Pro', () => {
        test.beforeEach(async ({ page }) => {
            await openApp(page, '#/settings');
            test.skip(!(await isProActive(page)), 'No Pro 3.0 is mapped into this store');
            expect(await postAccess(page, [])).toEqual({ ok: true });
        });

        test.afterEach(async ({ page }, testInfo) => {
            if (testInfo.status === 'skipped') {
                return;
            }

            // Back to administrators only, whoever the test logged in as.
            await page.context().clearCookies();
            await loginAsAdmin(page);
            await openApp(page, '#/settings');
            expect(await postAccess(page, [])).toEqual({ ok: true });
        });

        test('Plan links the subscription on WooCommerce.com and offers no upgrade', async ({ page }) => {
            await openApp(page, '#/settings/plan');

            const section = page.getByTestId('wcct-settings-plan');

            await expect(section).toContainText("You're on Pro. Thanks for supporting the plugin.");
            await expect(section.getByRole('link', { name: /Manage your subscription on WooCommerce.com/ })).toHaveAttribute(
                'href',
                SUBSCRIPTIONS_URL
            );
            await expect(section.getByRole('link', { name: /Upgrade to Pro/ })).toHaveCount(0);
            await expect(section).not.toContainText('See pricing');
            await expect(page.locator(`${SECTIONS} a`, { hasText: 'Integration access' })).not.toContainText('PRO');
        });

        test('the Integration access heading keeps focus once the role list loads', async ({ page }) => {
            await openApp(page, '#/settings/plan');

            let release: () => void = () => {};
            const held = new Promise<void>(resolve => (release = resolve));

            // Hold the list so focus lands while the section is still loading.
            await page.route(ACCESS_ROUTE, async route => {
                await held;
                await route.continue();
            });
            await page.locator(`${SECTIONS} a`, { hasText: 'Integration access' }).click();

            const heading = page.getByRole('heading', { level: 2, name: 'Integration access' });

            await expect(page.getByTestId('wcct-access-skeleton')).toBeVisible();
            await expect(heading).toBeFocused();
            release();
            await expect(page.getByTestId('wcct-settings-access').getByRole('checkbox', { name: 'Administrator' })).toBeVisible();
            await expect(heading).toBeFocused();
        });

        test('a new role needs a confirmation, and Cancel leaves it off', async ({ page }) => {
            await openApp(page, '#/settings/access');

            const section = page.getByTestId('wcct-settings-access');
            const admin = section.getByRole('checkbox', { name: 'Administrator' });
            const editor = section.getByRole('checkbox', { name: 'Editor' });
            const save = section.getByRole('button', { name: 'Saved' });
            const dialog = page.getByRole('alertdialog');

            await expect(admin).toBeChecked();
            await expect(admin).toBeDisabled();
            await expect(section).toContainText('Always has access.');
            await expect(editor).not.toBeChecked();
            await expect(save).toBeDisabled();
            await expect(section).toContainText('Only administrators have access until you tick a role.');

            await editor.click();
            await expect(dialog).toBeVisible();
            await expect(dialog.getByRole('heading')).toHaveText('Give Editor access to Conversion Tracking?');
            await expect(dialog).toContainText('Custom scripts stay with administrators.');
            await expect(dialog).not.toContainText('outside your business');
            await dialog.getByRole('button', { name: 'Cancel' }).click();
            await expect(dialog).toHaveCount(0);
            await expect(editor).not.toBeChecked();
            await expect(editor).toBeFocused();
            await expect(save).toBeDisabled();

            // Escape cancels too.
            await editor.click();
            await expect(dialog).toBeVisible();
            await page.keyboard.press('Escape');
            await expect(dialog).toHaveCount(0);
            await expect(editor).not.toBeChecked();
        });

        test('an administrator grants a role after confirming and saves it', async ({ page }) => {
            await openApp(page, '#/settings/access');

            const section = page.getByTestId('wcct-settings-access');
            const editor = section.getByRole('checkbox', { name: 'Editor' });
            const customer = section.getByRole('checkbox', { name: 'Customer' });
            const dialog = page.getByRole('alertdialog');
            const status = page.getByTestId('wcct-access-status');

            await customer.click();
            await expect(dialog).toContainText('This role usually belongs to people outside your business.');
            await dialog.getByRole('button', { name: 'Cancel' }).click();

            await editor.click();
            await dialog.getByRole('button', { name: 'Give access' }).click();
            await expect(dialog).toHaveCount(0);
            await expect(editor).toBeChecked();
            await expect(status).toHaveText('Editor can manage tracking once you save.');

            const saved = page.waitForResponse(
                response => ACCESS_ROUTE.test(response.url()) && response.request().method() === 'POST'
            );

            await section.getByRole('button', { name: 'Save changes' }).click();
            expect((await saved).status()).toBe(200);
            expect((await saved).request().postDataJSON()).toEqual({ roles: ['editor'] });
            await expect(status).toHaveText('Saved just now');
            await expect(section.getByRole('button', { name: 'Saved' })).toBeDisabled();

            await page.reload();
            await expect(section.getByRole('checkbox', { name: 'Editor' })).toBeChecked();

            // Unticking needs no confirmation.
            await section.getByRole('checkbox', { name: 'Editor' }).click();
            await expect(dialog).toHaveCount(0);
            await expect(status).toHaveText('Unsaved changes');
        });

        test('a Dokan vendor role shows the REST warning', async ({ page }) => {
            // The e2e store has no Dokan, so the route answers with its vendor role.
            await page.route(ACCESS_ROUTE, async (route, request) => {
                if (request.method() !== 'GET') {
                    return route.continue();
                }

                const response = await route.fetch();
                const body = await response.json();

                body.roles.push({ id: 'seller', name: 'Vendor', granted: false, always: false, outside: true, dokan_vendor: true, carried: false });
                await route.fulfill({ response, json: body });
            });
            await openApp(page, '#/settings/access');

            const section = page.getByTestId('wcct-settings-access');
            const dialog = page.getByRole('alertdialog');

            await expect(section).toContainText('Added by Dokan.');
            await expect(page.getByTestId('wcct-access-vendor-warning')).toHaveCount(0);

            await section.getByRole('checkbox', { name: 'Vendor' }).click();
            await expect(dialog).toContainText('Dokan keeps vendors out of wp-admin');
            await expect(dialog).toContainText('outside your business');
            await dialog.getByRole('button', { name: 'Give access' }).click();
            await expect(page.getByTestId('wcct-access-vendor-warning')).toContainText('WordPress REST API');
        });

        test('a granted shop manager sees the list read-only and cannot save it', async ({ page, jsErrors }) => {
            // The browser logs the rejected POST.
            jsErrors.allow(/status of 403/);
            expect(await postAccess(page, ['shop_manager'])).toEqual({ ok: true });

            await page.context().clearCookies();
            await login(page, SHOP_MANAGER.user, SHOP_MANAGER.password);
            await openApp(page, '#/settings/access');

            const section = page.getByTestId('wcct-settings-access');

            await expect(section.getByRole('checkbox', { name: 'Shop manager' })).toBeChecked();
            await expect(section.getByRole('checkbox', { name: 'Shop manager' })).toBeDisabled();
            await expect(section.getByRole('checkbox', { name: 'Editor' })).toBeDisabled();
            await expect(section).toContainText('Only administrators can change who has access.');
            await expect(section.getByRole('button', { name: /Save|Saved/ })).toHaveCount(0);

            expect(await postAccess(page, ['shop_manager', 'editor'])).toEqual({ ok: false, code: 'rest_forbidden', status: 403 });
        });
    });
});
