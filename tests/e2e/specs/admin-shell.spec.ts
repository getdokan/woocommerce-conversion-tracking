import { test, expect } from '../utils/fixtures';
import { APP_PATH, loginAsAdmin, openApp } from '../utils/admin';

// Every 3.0 route, its page heading and the nav tab it lights up.
const ROUTES = [
    { hash: '#/dashboard', title: 'Dashboard', tab: 'Dashboard' },
    { hash: '#/platforms/facebook', title: 'Platforms', tab: 'Platforms' },
    { hash: '#/platforms/google', title: 'Platforms', tab: 'Platforms' },
    { hash: '#/log', title: 'Activity log', tab: 'Activity log' },
    { hash: '#/doctor', title: 'Tracking Doctor', tab: 'Doctor' },
    { hash: '#/catalog', title: 'Catalog', tab: 'Catalog' },
    { hash: '#/settings', title: 'Settings', tab: 'Settings' },
    { hash: '#/settings/plan', title: 'Settings', tab: 'Settings' },
    { hash: '#/whats-new', title: "What's new", tab: 'Dashboard' },
];

const NAV = '#wcct-topbar-root nav[aria-label="Conversion Tracking pages"]';

// The settings route, not settings/schema.

const SETTINGS_ROUTE = /wcct(\/|%2F)v1(\/|%2F)settings(\?|&|$)/;

test.describe('admin app shell', () => {
    test.beforeEach(async ({ page }) => {
        await loginAsAdmin(page);
    });

    for (const route of ROUTES) {
        test(`${route.hash} renders through the shell`, async ({ page }) => {
            await openApp(page, route.hash);

            await expect(page.locator('#wcct-app-root h1')).toHaveText(route.title);
            await expect(page.locator(`${NAV} a[aria-current="page"]`)).toHaveText(route.tab);
            await expect(page.locator(`${NAV} a[aria-current="page"]`)).toHaveCount(1);
            await expect(page).toHaveTitle(new RegExp(`^${route.title} ‹ Conversion Tracking ‹ `));
            await expect(page.locator('#wcct-topbar-root')).toContainText('Conversion Tracking');
            await expect(page.locator('#wcct-topbar-root')).toContainText(/Lite \d/);
        });
    }

    for (const hash of ['#/nope', '#/platforms/nope', '#/log/extra/parts']) {
        test(`unknown route ${hash} redirects to the dashboard`, async ({ page }) => {
            await openApp(page, hash);
            await expect(page).toHaveURL(/#\/dashboard$/);
            await expect(page.locator('#wcct-app-root h1')).toHaveText('Dashboard');
        });
    }

    test('a route keeps its query string', async ({ page }) => {
        await openApp(page, '#/log?tab=not-confirmed');
        await expect(page.locator('#wcct-app-root h1')).toHaveText('Activity log');
        await expect(page).toHaveURL(/#\/log\?tab=not-confirmed$/);
    });

    test('a cold load reads the settings once', async ({ page }) => {
        let reads = 0;

        page.on('request', request => {
            if (request.method() === 'GET' && SETTINGS_ROUTE.test(request.url())) {
                reads++;
            }
        });
        await openApp(page, '#/dashboard');
        await expect(page.getByTestId('wcct-dashboard')).toBeVisible();
        await page.waitForTimeout(1000);
        await expect(page.getByTestId('wcct-card-skeleton')).toHaveCount(0);
        expect(reads).toBe(1);
    });

    test('the nav is a list of links, all reachable with Tab', async ({ page }) => {
        await openApp(page, '#/dashboard');

        const links = page.locator(`${NAV} a`);

        await expect(links).toHaveText(['Dashboard', 'Platforms', 'Catalog', 'Activity log', 'Doctor', 'Settings']);
        await expect(page.locator(`${NAV} [role="tab"]`)).toHaveCount(0);

        for (const link of await links.all()) {
            expect(await link.getAttribute('tabindex')).toBeNull();
        }
    });

    test('nav tabs switch pages without a reload', async ({ page }) => {
        await openApp(page, '#/dashboard');
        await page.evaluate(() => ((window as unknown as { ctMarker: boolean }).ctMarker = true));

        await page.locator(`${NAV} a`, { hasText: 'Activity log' }).click();
        await expect(page).toHaveURL(/#\/log$/);
        await expect(page.locator('#wcct-app-root h1')).toHaveText('Activity log');
        // Focus moves to the new page's heading.
        await expect(page.locator('#wcct-app-root h1')).toBeFocused();
        expect(await page.evaluate(() => (window as unknown as { ctMarker?: boolean }).ctMarker)).toBe(true);
    });

    test('the Help menu lists Docs, Support and Feature request', async ({ page }) => {
        await openApp(page, '#/dashboard');
        await page.getByRole('button', { name: 'Help' }).click();

        const menu = page.locator('[data-slot="dropdown-menu-content"]');
        const items = menu.getByRole('menuitem');

        await expect(items).toHaveCount(3);
        await expect(items).toContainText(['Docs', 'Support', 'Feature request']);
        await expect(items.first()).toContainText('(opens in a new tab)');

        for (const name of ['Docs', 'Support', 'Feature request']) {
            await expect(menu.getByRole('menuitem', { name })).toHaveAttribute('href', /^https:\/\//);
        }
    });

    test('admin notices show under the top bar', async ({ page, context, baseURL }) => {
        await context.addCookies([{ name: 'ct_e2e_notice', value: '1', url: baseURL! }]);
        await openApp(page, '#/dashboard');

        const notice = page.locator('#ct-e2e-notice');

        await expect(notice).toBeVisible();

        const bar = await page.locator('#wcct-topbar-root').boundingBox();
        const box = await notice.boundingBox();

        expect(bar).not.toBeNull();
        expect(box).not.toBeNull();
        expect(box!.y).toBeGreaterThanOrEqual(bar!.y + bar!.height);
        // React must not own the notice.
        expect(await page.locator('#wcct-app-root #ct-e2e-notice').count()).toBe(0);
    });

    test('the dashboard shows the normal layout once a platform is connected', async ({ page }) => {
        await openApp(page, '#/dashboard');

        const dashboard = page.getByTestId('wcct-dashboard');

        await expect(dashboard).toBeVisible();
        await expect(dashboard).toContainText('Purchases tracked');
        await expect(dashboard).toContainText('Tracking Doctor');
        await expect(dashboard).toContainText('What to do next');
        await expect(page.getByRole('heading', { name: 'Connected', exact: true })).toBeVisible();
        await expect(page.getByTestId('wcct-platform-card-facebook')).toContainText('Meta');
    });

    test('the dashboard shows the welcome state with no platform connected', async ({ page }) => {
        await page.route(SETTINGS_ROUTE, async (route, request) => {
            if (request.method() !== 'GET') {
                return route.continue();
            }

            await route.fulfill({ json: { consent_mode: false, platforms: {} } });
        });
        await openApp(page, '#/dashboard');

        const welcome = page.getByTestId('wcct-welcome');

        await expect(welcome).toContainText("Welcome. Let's get one platform working.");
        await expect(welcome.getByRole('listitem')).toHaveCount(5);
        // plugin-ui Button rendered as a link.
        await expect(welcome.getByText('Continue setup')).toHaveAttribute('href', '#/onboarding');
        await expect(page.getByRole('heading', { name: 'Platforms you can connect' })).toBeVisible();
        await expect(page.getByText('Run the Doctor')).toHaveCount(0);
    });

    test('the first load shows a full-page skeleton', async ({ page }) => {
        let release: () => void = () => {};
        const held = new Promise<void>(resolve => (release = resolve));

        await page.route(SETTINGS_ROUTE, async route => {
            await held;
            await route.continue();
        });
        await page.goto(`${APP_PATH}#/dashboard`);
        await expect(page.getByTestId('wcct-page-skeleton')).toBeVisible();
        release();
        await expect(page.getByTestId('wcct-page-skeleton')).toHaveCount(0);
        await expect(page.locator('#wcct-app-root h1')).toHaveText('Dashboard');
    });

    test('coming back to the dashboard shows card skeletons while it refreshes', async ({ page }) => {
        await openApp(page, '#/log');

        let release: () => void = () => {};
        const held = new Promise<void>(resolve => (release = resolve));

        await page.route(SETTINGS_ROUTE, async route => {
            await held;
            await route.continue();
        });
        await page.locator(`${NAV} a`, { hasText: 'Dashboard' }).click();
        await expect(page.getByTestId('wcct-card-skeleton').first()).toBeVisible();
        release();
        await expect(page.getByTestId('wcct-card-skeleton')).toHaveCount(0);
    });

    test('a failed refresh keeps the page and offers a retry', async ({ page, jsErrors }) => {
        // The browser logs the simulated 500.
        jsErrors.allow(/status of 500/);
        await openApp(page, '#/log');

        let fail = true;

        await page.route(SETTINGS_ROUTE, async route => {
            if (fail) {
                await route.fulfill({ status: 500, json: { code: 'internal_error', message: 'Simulated failure' } });
                return;
            }

            await route.continue();
        });
        await page.locator(`${NAV} a`, { hasText: 'Dashboard' }).click();

        const notice = page.getByTestId('wcct-load-error');

        await expect(notice).toContainText('Simulated failure');
        await expect(page.getByTestId('wcct-dashboard')).toBeVisible();

        fail = false;
        await notice.getByRole('button', { name: 'Try again' }).click();
        await expect(notice).toHaveCount(0);
    });

    test('Pro adds its version pill, unlocks Microsoft and sends Support to WooCommerce.com', async ({ page }) => {
        await openApp(page, '#/dashboard');

        const proVersion = await page.evaluate(() => (window as unknown as { wcctAdmin?: { proVersion: string } }).wcctAdmin?.proVersion ?? '');

        test.skip(!proVersion, 'Pro is not mapped into this store');

        await expect(page.locator('#wcct-topbar-root')).toContainText(`Pro ${proVersion}`);

        const microsoft = page.getByTestId('wcct-platform-card-bing_ads');

        await expect(microsoft).toContainText('Microsoft Advertising');
        await expect(microsoft).not.toContainText('PRO');
        await expect(microsoft).not.toContainText('Compare plans');

        await page.getByRole('button', { name: 'Help' }).click();
        await expect(
            page.locator('[data-slot="dropdown-menu-content"]').getByRole('menuitem', { name: 'Support' })
        ).toHaveAttribute('href', /^https:\/\/woocommerce\.com\//);
    });

    test('the classic screens stay reachable with ?tab=', async ({ page }) => {
        await page.goto(`${APP_PATH}&tab=integrations`);
        await expect(page.locator('.nav-tab-wrapper')).toBeVisible();
        await expect(page.locator('#wcct-topbar-root')).toHaveCount(0);
    });
});
