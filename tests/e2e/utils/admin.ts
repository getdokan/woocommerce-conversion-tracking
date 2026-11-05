import { Page, expect } from '@playwright/test';

// wp-env's default administrator.
const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASSWORD = process.env.WP_ADMIN_PASSWORD ?? 'password';

export const APP_PATH = '/wp-admin/admin.php?page=conversion-tracking';

export async function loginAsAdmin(page: Page): Promise<void> {
    await page.goto('/wp-login.php');
    await page.locator('#user_login').fill(ADMIN_USER);
    await page.locator('#user_pass').fill(ADMIN_PASSWORD);
    await page.locator('#wp-submit').click();
    await expect(page.locator('#wpadminbar')).toBeVisible();
}

// Opens the admin app at a hash route and waits for the page heading.
export async function openApp(page: Page, hash = ''): Promise<void> {
    await page.goto(`${APP_PATH}${hash}`);
    // The first admin request on a fresh store is slow.
    await expect(page.locator('#wcct-topbar-root nav')).toBeVisible({ timeout: 30_000 });
    await expect(page.locator('#wcct-app-root h1')).toBeVisible({ timeout: 30_000 });
}
