import { Page, expect } from '@playwright/test';

// wp-env's default administrator.
const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASSWORD = process.env.WP_ADMIN_PASSWORD ?? 'password';

export const APP_PATH = '/wp-admin/admin.php?page=conversion-tracking';

// Seeded by setup/seed.php.
export const SHOP_MANAGER = { user: 'ct_e2e_shop_manager', password: 'password' };

export async function login(page: Page, user: string, password: string): Promise<void> {
    await page.goto('/wp-login.php');
    await page.locator('#user_login').fill(user);
    await page.locator('#user_pass').fill(password);
    await page.locator('#wp-submit').click();
    await expect(page.locator('#wpadminbar')).toBeVisible();
}

export async function loginAsAdmin(page: Page): Promise<void> {
    await login(page, ADMIN_USER, ADMIN_PASSWORD);
}

// Opens the admin app at a hash route and waits for the page heading.
export async function openApp(page: Page, hash = ''): Promise<void> {
    await page.goto(`${APP_PATH}${hash}`);
    // The first admin request on a fresh store is slow.
    await expect(page.locator('#wcct-topbar-root nav')).toBeVisible({ timeout: 30_000 });
    await expect(page.locator('#wcct-app-root h1')).toBeVisible({ timeout: 30_000 });
}

// The Pro state the admin app gets (Admin\Assets); wp_localize_script sends proActive as '1' or ''.
export type WcctAdminData = { version: string; proVersion: string; proActive: boolean | string; proUpdate: string };

export const wcctAdminData = (page: Page): Promise<WcctAdminData> =>
    page.evaluate(() => {
        const data: Partial<WcctAdminData> = (window as unknown as { wcctAdmin?: Partial<WcctAdminData> }).wcctAdmin ?? {};

        return { version: data.version ?? '', proVersion: data.proVersion ?? '', proActive: data.proActive ?? '', proUpdate: data.proUpdate ?? '' };
    });

// Pro 3.0 or later is running; an older Pro is paused (specs/version-guard.spec.ts).
export const isProActive = async (page: Page): Promise<boolean> => !!(await wcctAdminData(page)).proActive;
