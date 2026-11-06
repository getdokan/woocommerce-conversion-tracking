import { test, expect } from '../utils/fixtures';
import { APP_PATH, WcctAdminData, loginAsAdmin, openApp, wcctAdminData } from '../utils/admin';

// Compat\ProGuard: a Pro older than 3.0 stays installed but paused. Run once with Pro 3.0 or no Pro, and once with Pro 1.1.4 mapped in.

const NOTICE = 'Conversion Tracking Pro needs an update to work with 3.0. Pro features are paused until you update.';

const isOutdated = (data: WcctAdminData): boolean => !!data.proVersion && !data.proActive;

test.describe('Version guard', () => {
    test.beforeEach(async ({ page }) => {
        await loginAsAdmin(page);
    });

    test('the Update Pro notice shows only while a Pro older than 3.0 is installed', async ({ page }) => {
        await openApp(page, '#/settings/plan');

        const data = await wcctAdminData(page);
        const notice = page.locator('#wcct-pro-update-notice');
        const plan = page.getByTestId('wcct-settings-plan');

        if (!isOutdated(data)) {
            await expect(notice).toHaveCount(0);

            return;
        }

        await expect(notice).toContainText(NOTICE);
        await expect(notice.getByRole('link', { name: 'Update Pro' })).toHaveAttribute('href', /plugins\.php\?plugin_status=upgrade$/);
        expect(data.proUpdate).toMatch(/plugins\.php\?plugin_status=upgrade$/);

        // Plan doesn't say the store is on Pro while Pro is paused.
        await expect(plan).toContainText(`Pro ${data.proVersion} is installed but paused until you update it to 3.0.`);
        await expect(plan).not.toContainText("You're on Pro");
        await expect(plan.getByRole('link', { name: 'Update Pro' })).toHaveAttribute('href', data.proUpdate);
        await expect(page.locator('#wcct-topbar-root')).toContainText(`Pro ${data.proVersion}, paused`);

        // The notice is on every admin page, not only Conversion Tracking.
        await page.goto('/wp-admin/index.php');
        await expect(page.locator('#wcct-pro-update-notice')).toContainText(NOTICE);
    });

    test('Free offers Pro (Get PRO, classic Pro tabs) unless Pro 3.0 is running', async ({ page }) => {
        await openApp(page);

        const proActive = !!(await wcctAdminData(page)).proActive;

        await page.goto('/wp-admin/plugins.php');
        await expect(page.locator('tr[data-plugin="woocommerce-conversion-tracking/conversion-tracking.php"]').getByRole('link', { name: 'Get PRO' })).toHaveCount(proActive ? 0 : 1);

        // WCCT_Pro_Features adds the locked Pro tabs to the classic screen.
        await page.goto(`${APP_PATH}&tab=integrations`);
        await expect(page.locator('.nav-tab-wrapper .nav-tab', { hasText: 'Facebook Product Catalog (Pro)' })).toHaveCount(proActive ? 0 : 1);
    });

    test('a Pro older than 3.0 keeps serving its catalog feed', async ({ page }) => {
        await openApp(page);
        test.skip(!isOutdated(await wcctAdminData(page)), 'No Pro older than 3.0 in this store');

        const response = await page.request.get('/?action=wcct-product-export');

        expect(response.status()).toBe(200);
        expect(response.headers()['content-type']).toContain('text/csv');
        expect(await response.text()).toContain('CT E2E Product');
    });
});
