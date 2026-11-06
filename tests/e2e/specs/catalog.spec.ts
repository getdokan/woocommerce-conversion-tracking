import type { Page, Route } from '@playwright/test';
import { test, expect } from '../utils/fixtures';
import { APP_PATH, loginAsAdmin, openApp } from '../utils/admin';

// The catalog route, not catalog/anything.
const CATALOG_ROUTE = /wcct(\/|%2F)v1(\/|%2F)catalog(\?|&|$)/;

type Catalog = Record<string, unknown> & {
    locked: boolean;
    login: Record<string, unknown>;
    exclusions: { types: string[]; categories: number[] };
};

// Calls the REST API from the admin page, with WordPress's nonce; resolves with { status, body }.
async function api(page: Page, path: string, method = 'GET', data?: unknown): Promise<{ status: number; body: any }> {
    return page.evaluate(
        async ({ path, method, data }) => {
            const wp = (window as any).wp;

            try {
                const body = await wp.apiFetch({ path, method, data });

                return { status: 200, body };
            } catch (error: any) {
                return { status: error?.data?.status ?? 0, body: error };
            }
        },
        { path, method, data }
    );
}

const isPro = (page: Page) =>
    page.evaluate(() => !!(window as unknown as { wcctAdmin?: { proVersion: string } }).wcctAdmin?.proVersion);

// Serves the real GET response with some fields replaced, once.
async function patchCatalogOnce(page: Page, patch: (data: Catalog) => Catalog): Promise<void> {
    await page.route(
        CATALOG_ROUTE,
        async (route: Route, request) => {
            if (request.method() !== 'GET') {
                return route.fallback();
            }

            const response = await route.fetch();

            await route.fulfill({ response, json: patch(await response.json()) });
        },
        { times: 1 }
    );
}

async function openCatalog(page: Page): Promise<void> {
    await openApp(page, '#/catalog');
    await expect(page.getByTestId('wcct-catalog')).toBeVisible();
    await expect(page.getByTestId('wcct-card-skeleton')).toHaveCount(0);
}

test.describe('Catalog page', () => {
    test.beforeEach(async ({ page }) => {
        await loginAsAdmin(page);
    });

    test('Free shows the upgrade card and the route says locked', async ({ page }) => {
        await openApp(page, '#/dashboard');
        test.skip(await isPro(page), 'Pro is active in this store');

        expect((await api(page, '/wcct/v1/catalog')).body).toEqual({ locked: true });
        await openCatalog(page);

        const card = page.getByTestId('wcct-catalog-upgrade');

        await expect(card.getByRole('heading', { name: 'The Meta catalog feed is part of Pro' })).toBeVisible();
        await expect(card.getByRole('listitem')).toHaveCount(7);

        // plugin-ui Buttons rendered as links, as on the dashboard.
        const compare = card.getByText('Compare plans');

        await expect(compare).toHaveAttribute('href', /^https:\/\/woocommerce\.com\//);
        await expect(compare).toHaveAttribute('target', '_blank');
        await expect(compare).toContainText('(opens in a new tab)');
        await expect(card.getByText('Continue with Free')).toHaveAttribute('href', '#/platforms/facebook');

        // An upgrade card, not greyed-out settings, and no price.
        await expect(page.getByTestId('wcct-catalog')).not.toContainText(/[$€£]\s?\d/);
        await expect(page.getByRole('switch')).toHaveCount(0);
        await expect(page.getByRole('checkbox')).toHaveCount(0);
        await expect(page.getByTestId('wcct-catalog-save')).toHaveCount(0);
    });

    test('a locked response shows the upgrade card', async ({ page }) => {
        await page.route(CATALOG_ROUTE, route => route.fulfill({ json: { locked: true } }));
        await openCatalog(page);

        await expect(page.getByTestId('wcct-catalog-upgrade')).toBeVisible();
        await expect(page.getByTestId('wcct-feed-status')).toHaveCount(0);
    });

    test.describe('with Pro', () => {
        // The app page the REST helper runs on; Pro shows in its wcctAdmin.
        test.beforeEach(async ({ page }) => {
            await openApp(page, '#/dashboard');
            test.skip(!(await isPro(page)), 'Pro is not mapped into this store');
        });

        test('the status card shows the feed age and product count', async ({ page }) => {
            await patchCatalogOnce(page, data => ({
                ...data,
                generated_at: Math.floor(Date.now() / 1000) - 3 * 3600,
                age_hours: 3,
                rows: 1248,
                stale: false,
                building: false,
                error: '',
            }));
            await openCatalog(page);

            const status = page.getByTestId('wcct-feed-status');

            await expect(status).toHaveAttribute('data-state', 'ready');
            await expect(status.getByRole('heading', { name: 'Your feed is ready' })).toBeVisible();
            await expect(status).toContainText('Generated 3 hours ago');
            await expect(page.getByTestId('wcct-feed-rows')).toHaveText(/^1,?248$/);
            await expect(status).toContainText('each variation counts as one');
            await expect(status.locator('#wcct-feed-url')).toHaveText(/\?action=wcct-product-export$/);
            await expect(status.getByRole('button', { name: /Rebuild now/ })).toHaveCount(0);
        });

        test('a feed past 26 hours shows the warning, and Rebuild now queues a build', async ({ page }) => {
            await patchCatalogOnce(page, data => ({
                ...data,
                generated_at: Math.floor(Date.now() / 1000) - 30 * 3600,
                age_hours: 30,
                stale: true,
                building: false,
                error: '',
            }));
            await openCatalog(page);

            const status = page.getByTestId('wcct-feed-status');

            await expect(status).toHaveAttribute('data-state', 'stale');
            await expect(status.getByRole('heading', { name: 'Your feed is 30 hours old' })).toBeVisible();
            await expect(status).toContainText('real server cron job');

            const posted = page.waitForRequest(request => CATALOG_ROUTE.test(request.url()) && request.method() === 'POST');

            await status.getByRole('button', { name: 'Rebuild now' }).click();
            expect((await posted).postDataJSON()).toEqual({ rebuild: true });

            // The card now shows the real, queued build.
            await expect(status.getByRole('button', { name: /Rebuild now/ })).toHaveCount(0);
            await expect(status).not.toHaveAttribute('data-state', 'stale');
            await expect(page.getByText('The feed is being rebuilt.')).toBeAttached();
        });

        test('a build that never moves shows the cron hint and offers Rebuild now again', async ({ page }) => {
            await page.clock.install();
            await page.route(CATALOG_ROUTE, async (route, request) => {
                if (request.method() !== 'GET') {
                    return route.fallback();
                }

                const response = await route.fetch();
                const data = await response.json();

                // Queued but never picked up, as when scheduled tasks don't run.
                await route.fulfill({ response, json: { ...data, generated_at: Math.floor(Date.now() / 1000) - 3600, age_hours: 1, stale: false, building: true, build_rows: 0, error: '' } });
            });
            await openCatalog(page);

            const status = page.getByTestId('wcct-feed-status');

            await expect(status).toContainText('Rebuilding now');
            await expect(status.getByRole('button', { name: 'Rebuild now' })).toHaveCount(0);

            // The first poll sets the baseline; six more without progress mark the build stuck.
            for (let poll = 0; poll < 7; poll++) {
                const read = page.waitForResponse(res => CATALOG_ROUTE.test(res.url()) && res.request().method() === 'GET');

                await page.clock.runFor(30_000);
                await read;
            }

            await expect(page.getByTestId('wcct-feed-stalled')).toBeVisible();
            await expect(status).toContainText('real server cron job');

            const posted = page.waitForRequest(request => CATALOG_ROUTE.test(request.url()) && request.method() === 'POST');

            await status.getByRole('button', { name: 'Rebuild now' }).click();
            expect((await posted).postDataJSON()).toEqual({ rebuild: true });
            await expect(page.getByTestId('wcct-feed-stalled')).toHaveCount(0);
        });

        test('exclusions by type and searched category save through REST', async ({ page }) => {
            const parent = (await api(page, '/wc/v3/products/categories', 'POST', { name: 'CT E2E Apparel' })).body;
            // An ampersand, which WordPress stores encoded.
            const child = (await api(page, '/wc/v3/products/categories', 'POST', { name: 'CT E2E Hats & Caps', parent: parent.id })).body;

            try {
                await openCatalog(page);

                const types = page.getByRole('group', { name: 'By product type' });

                await types.getByRole('checkbox', { name: 'Grouped product' }).click();

                const search = page.getByRole('searchbox', { name: 'Find a category' });

                await search.fill('hats & c');

                const list = page.getByRole('group', { name: 'Categories' });

                await expect(list.getByRole('checkbox')).toHaveCount(1);
                await expect(list).toContainText('CT E2E Hats & Caps (in CT E2E Apparel)');
                await expect(list).not.toContainText('&amp;');
                await list.getByRole('checkbox', { name: /CT E2E Hats/ }).click();
                await expect(page.getByTestId('wcct-cat-count')).toHaveText(/^1 of \d+ left out$/);

                await search.fill('nothing like this');
                await expect(list).toContainText('No categories match.');
                await search.fill('');

                const posted = page.waitForRequest(request => CATALOG_ROUTE.test(request.url()) && request.method() === 'POST');

                await page.getByTestId('wcct-catalog-save').click();
                expect((await posted).postDataJSON()).toEqual({ exclusions: { types: ['grouped'], categories: [child.id] } });
                await expect(page.getByTestId('wcct-catalog-note')).toHaveText('Saved. The feed is being rebuilt with your changes.');
                await expect(page.getByTestId('wcct-catalog-save')).toBeDisabled();

                // Stored, and shown again after a reload.
                expect((await api(page, '/wcct/v1/catalog')).body.exclusions).toEqual({ types: ['grouped'], categories: [child.id] });
                await page.reload();
                await expect(page.getByRole('checkbox', { name: 'Grouped product' })).toBeChecked();
                await expect(page.getByRole('checkbox', { name: 'CT E2E Hats & Caps' })).toBeChecked();
                await expect(page.getByRole('checkbox', { name: 'CT E2E Apparel' })).not.toBeChecked();

                // Clear empties the category list and leaves focus in the box.
                await page.getByRole('button', { name: 'Clear the categories left out' }).click();
                await expect(page.getByTestId('wcct-cat-count')).toHaveText(/^0 of \d+ left out$/);
                await expect(search).toBeFocused();

                // Leaving with unsaved changes asks first; staying keeps them.
                page.once('dialog', dialog => dialog.dismiss());
                await page.locator('#wcct-topbar-root nav a', { hasText: 'Dashboard' }).click();
                await expect(page).toHaveURL(/#\/catalog$/);
                await expect(page.getByTestId('wcct-cat-count')).toHaveText(/^0 of \d+ left out$/);
                page.once('dialog', dialog => dialog.accept());
                await page.locator('#wcct-topbar-root nav a', { hasText: 'Dashboard' }).click();
                await expect(page.locator('#wcct-app-root h1')).toHaveText('Dashboard');
            } finally {
                await api(page, '/wcct/v1/catalog', 'POST', { exclusions: { types: [], categories: [] } });
                await api(page, `/wc/v3/products/categories/${child.id}?force=true`, 'DELETE');
                await api(page, `/wc/v3/products/categories/${parent.id}?force=true`, 'DELETE');
            }
        });

        test('a feed ID that differs from the Meta pixel shows the mismatch warning', async ({ page }) => {
            try {
                await openCatalog(page);

                await expect(page.getByTestId('wcct-pixel-id-mode')).toHaveText('Product ID');
                await expect(page.getByTestId('wcct-id-match')).toBeVisible();
                await expect(page.getByRole('link', { name: /Change/ })).toHaveAttribute('href', '#/platforms/facebook');
                await expect(page.getByTestId('wcct-catalog')).toContainText('item_group_id');

                await page.getByLabel('ID used in the feed').click();
                await page.getByRole('option', { name: /^SKU/ }).click();

                // Warned before saving.
                await expect(page.getByTestId('wcct-id-mismatch')).toContainText('The feed and the pixel use different IDs.');

                const posted = page.waitForRequest(request => CATALOG_ROUTE.test(request.url()) && request.method() === 'POST');

                await page.getByTestId('wcct-catalog-save').click();
                expect((await posted).postDataJSON()).toEqual({ product_id_mode: 'sku' });
                await expect(page.getByTestId('wcct-catalog-note')).toHaveText('Saved. The feed is being rebuilt with your changes.');
                await expect(page.getByTestId('wcct-catalog-save')).toHaveText('Saved');

                const saved = (await api(page, '/wcct/v1/catalog')).body;

                // The Meta setting is separate and unchanged.
                expect([saved.product_id_mode, saved.pixel_product_id_mode]).toEqual(['sku', 'id']);
                await page.reload();
                await expect(page.getByTestId('wcct-id-mismatch')).toBeVisible();
            } finally {
                await api(page, '/wcct/v1/catalog', 'POST', { product_id_mode: 'id' });
            }
        });

        test('an administrator turns the feed login on, and the password is never sent back', async ({ page, request }) => {
            const password = 'Fake-Feed-Pass-42';

            try {
                // A password saved by an earlier run stays (an empty one keeps it), so only the username starts empty.
                await api(page, '/wcct/v1/catalog', 'POST', { login: { enabled: false, username: '' } });

                const hadPassword = (await api(page, '/wcct/v1/catalog')).body.login.password_set;

                await openCatalog(page);

                const login = page.getByTestId('wcct-feed-login');
                const toggle = login.getByRole('switch', { name: 'Ask for a login' });

                await expect(toggle).not.toBeChecked();
                await expect(page.locator('#wcct-feed-user')).toHaveCount(0);
                await toggle.click();

                // Needs a username and a password first.
                await page.getByTestId('wcct-catalog-save').click();
                await expect(login).toContainText('Enter a username to turn on the feed login.');
                if (hadPassword) {
                    await expect(login).not.toContainText('Enter a password');
                } else {
                    await expect(login).toContainText('Enter a password to turn on the feed login.');
                }
                await expect(page.getByLabel('Feed username')).toBeFocused();

                await page.getByLabel('Feed username').fill('ct:feed');
                await page.getByLabel('Feed password').fill(password);
                await page.getByTestId('wcct-catalog-save').click();
                await expect(login).toContainText("The username can't contain a colon.");

                await page.getByLabel('Feed username').fill('ctfeed');

                const response = page.waitForResponse(res => CATALOG_ROUTE.test(res.url()) && res.request().method() === 'POST');

                await page.getByTestId('wcct-catalog-save').click();

                const saved = await response;

                expect(saved.request().postDataJSON()).toEqual({ login: { enabled: true, username: 'ctfeed', password } });
                expect(await saved.text()).not.toContain(password);
                await expect(page.getByTestId('wcct-catalog-note')).toHaveText('Saved.');
                await expect(page.getByLabel('Feed password')).toHaveValue('');
                await expect(login).toContainText('A password is saved. Leave this empty to keep it.');

                const feedUrl = (await page.locator('#wcct-feed-url').textContent())!;

                expect((await request.get(feedUrl, { maxRedirects: 0 })).status()).toBe(401);
                expect(JSON.stringify((await api(page, '/wcct/v1/catalog')).body)).not.toContain(password);
                expect(await page.content()).not.toContain(password);

                // Off again.
                await toggle.click();
                await page.getByTestId('wcct-catalog-save').click();
                await expect(page.getByTestId('wcct-catalog-note')).toHaveText('Saved.');
                expect((await api(page, '/wcct/v1/catalog')).body.login.enabled).toBe(false);
                expect((await request.get(feedUrl, { maxRedirects: 0 })).status()).not.toBe(401);
            } finally {
                await api(page, '/wcct/v1/catalog', 'POST', { login: { enabled: false, username: '' } });
            }
        });

        test('a granted shop manager sees the login read-only and gets 403 changing it', async ({ page, browser, baseURL }) => {
            // A manager left over from an interrupted run.
            for (const leftover of (await api(page, '/wp/v2/users?slug=ct-e2e-manager&context=edit')).body ?? []) {
                await api(page, `/wp/v2/users/${leftover.id}?force=true&reassign=1`, 'DELETE');
            }

            const before = (await api(page, '/wcct/v1/access')).body;
            const granted = before.roles.filter((role: { granted: boolean; always?: boolean }) => role.granted && !role.always).map((role: { id: string }) => role.id);
            const user = (
                await api(page, '/wp/v2/users', 'POST', {
                    username: 'ct-e2e-manager',
                    email: 'ct-e2e-manager@example.com',
                    password: 'ct-e2e-manager-pass',
                    roles: ['shop_manager'],
                })
            ).body;

            expect(user.id).toBeTruthy();

            try {
                expect((await api(page, '/wcct/v1/access', 'POST', { roles: [...new Set([...granted, 'shop_manager'])] })).status).toBe(200);

                const context = await browser.newContext({ baseURL });
                const manager = await context.newPage();

                await manager.goto('/wp-login.php');
                await manager.locator('#user_login').fill('ct-e2e-manager');
                await manager.locator('#user_pass').fill('ct-e2e-manager-pass');
                await manager.locator('#wp-submit').click();
                await expect(manager.locator('#wpadminbar')).toBeVisible();
                await manager.goto(`${APP_PATH}#/catalog`);
                await expect(manager.getByTestId('wcct-feed-status')).toBeVisible({ timeout: 30_000 });

                const login = manager.getByTestId('wcct-feed-login');

                await expect(login.getByRole('switch', { name: 'Ask for a login' })).toBeDisabled();
                await expect(login).toContainText('Only administrators can change the feed login.');
                await expect(manager.locator('#wcct-feed-user')).toHaveCount(0);

                // The rest of the page still works for them.
                await expect(manager.getByRole('checkbox', { name: 'Grouped product' })).toBeEnabled();
                expect((await api(manager, '/wcct/v1/catalog', 'POST', { login: { enabled: true } })).status).toBe(403);

                await context.close();
            } finally {
                await api(page, '/wcct/v1/access', 'POST', { roles: granted });
                await api(page, `/wp/v2/users/${user.id}?force=true&reassign=1`, 'DELETE');
            }
        });
    });
});
