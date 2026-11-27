import { APIRequestContext, Page } from '@playwright/test';
import { test, expect } from '../utils/fixtures';
import { addProductToCart, fillBlockCheckout, placeGuestOrder } from '../utils/checkout';
import { enabledTags } from '../utils/platforms';
import { loginAsAdmin, openApp } from '../utils/admin';

// Past the 10-minute settle time (Log\Statuses::SETTLE).
const PAST_SETTLE = 660;

const METRICS_ROUTE = /wcct(\/|%2F)v1(\/|%2F)metrics/;

const MENU_BADGE = '#adminmenu a[href$="page=conversion-tracking"] .wcct-alert-badge';

type Metrics = {
    purchases: number;
    sent: number;
    not_confirmed: number;
    not_confirmed_by: Record<string, number>;
    no_checkin: number;
    waiting: number;
    platforms_working: number;
    platforms_connected: number;
    platforms: { id: string; state: string; not_confirmed: number }[];
    not_confirmed_orders: { id: number; number: string; platforms: string[] }[];
    alerts: Record<string, boolean>;
    next_steps: { id: string }[];
};

interface LogRow {
    platform: string;
    library_loaded: string;
}

const logRows = async (request: APIRequestContext, orderId: number): Promise<LogRow[]> =>
    (await (await request.get(`/?rest_route=/ct-e2e/v1/order-log/${orderId}`)).json()).rows;

// Moves an order and its log rows back in time and drops the cached lists (mu-plugins/ct-e2e-helpers.php).
const age = async (request: APIRequestContext, orderId: number, seconds = PAST_SETTLE): Promise<void> => {
    expect((await request.post(`/?rest_route=/ct-e2e/v1/order-log/${orderId}/age`, { data: { seconds } })).ok()).toBe(true);
};

// A guest order whose thank-you page never loads.
const placeOrderWithoutThankYou = async (page: Page): Promise<number> => {
    await addProductToCart(page);
    await page.route(/\/order-received\//, route => route.abort('aborted'));

    const failed = page.waitForEvent('requestfailed', { predicate: request => /\/order-received\/\d+\//.test(request.url()), timeout: 30_000 });

    await fillBlockCheckout(page);

    const id = Number(new URL((await failed).url()).pathname.match(/\/order-received\/(\d+)/)![1]);

    await page.unroute(/\/order-received\//);

    return id;
};

// Opens the dashboard and returns what the metrics route answered.
const openDashboard = async (page: Page): Promise<Metrics> => {
    const response = page.waitForResponse(r => METRICS_ROUTE.test(r.url()) && r.request().method() === 'GET');

    await openApp(page, '#/dashboard');

    const metrics = (await (await response).json()) as Metrics;

    await expect(page.getByTestId('wcct-stat-purchases')).toBeVisible();

    return metrics;
};

// Settings > Alerts: set a switch and save; returns whether anything changed.
const setAlert = async (page: Page, name: string, on: boolean): Promise<boolean> => {
    await openApp(page, '#/settings/alerts');

    const toggle = page.getByRole('switch', { name });

    if ((await toggle.getAttribute('aria-checked')) === String(on)) {
        return false;
    }

    await toggle.click();
    await expect(toggle).toHaveAttribute('aria-checked', String(on));

    const saved = page.waitForResponse(r => /wcct(\/|%2F)v1(\/|%2F)settings/.test(r.url()) && r.request().method() === 'POST');

    await page.getByTestId('wcct-alerts-save').click();
    expect((await saved).ok()).toBe(true);
    await expect(page.getByText('Alert settings saved.').last()).toBeVisible();

    return true;
};

const NOT_CONFIRMED_SWITCH = 'Not confirmed purchases, on the dashboard';
const DOCTOR_SWITCH = 'Doctor found something new';

test.describe('Dashboard', () => {
    test('numbers, chart, card, badge, platform cards and next steps from real orders', async ({ page, request, jsErrors }) => {
        test.skip(!enabledTags().includes('meta'), 'Needs the Meta tag.');
        jsErrors.allow(/ERR_BLOCKED_BY_CLIENT.*facebook\.(net|com)/);
        jsErrors.allow(/ERR_ABORTED|net::ERR_FAILED/);

        await loginAsAdmin(page);

        const before = await openDashboard(page);

        // Counted once on every platform, by a guest.
        await page.context().clearCookies();

        const counted = await placeGuestOrder(page, 'block');

        // Stay on the thank-you page until every tag handed over (Pro's Microsoft tag too), or a slow one reads as hidden.
        const tags = enabledTags().length + ((await page.evaluate(() => 'uetq' in window)) ? 1 : 0);

        await expect
            .poll(async () => (await logRows(request, counted.id)).filter(r => r.library_loaded === '1').length, { timeout: 20_000 })
            .toBeGreaterThanOrEqual(tags);

        // Meta's script blocked: Meta checks in as not handed over.
        await page.context().clearCookies();
        await page.route(/connect\.facebook\.net|facebook\.com\/tr/, route => route.abort('blockedbyclient'));

        const missed = await placeGuestOrder(page, 'block');

        await expect
            .poll(async () => (await logRows(request, missed.id)).find(r => r.platform === 'facebook')?.library_loaded ?? null, { timeout: 20_000 })
            .toBe('0');
        await page.unroute(/connect\.facebook\.net|facebook\.com\/tr/);

        // No thank-you page visit: the quiet line, never Not confirmed.
        await page.context().clearCookies();

        const noCheckIn = await placeOrderWithoutThankYou(page);

        for (const id of [counted.id, missed.id, noCheckIn]) {
            await age(request, id);
        }

        await loginAsAdmin(page);
        await setAlert(page, NOT_CONFIRMED_SWITCH, true);

        const metrics = await openDashboard(page);

        // The two checked-in orders add to Purchases tracked; the order without a thank-you page doesn't.
        // Orders other specs left settling (waiting) may have settled meanwhile, so they can add to each count.
        const grew = (now: number, was: number, by: number) => {
            expect(now).toBeGreaterThanOrEqual(was + by);
            expect(now).toBeLessThanOrEqual(was + by + before.waiting);
        };

        grew(metrics.purchases, before.purchases, 2);
        grew(metrics.not_confirmed, before.not_confirmed, 1);
        grew(metrics.no_checkin, before.no_checkin, 1);
        grew(metrics.not_confirmed_by.facebook ?? 0, before.not_confirmed_by.facebook ?? 0, 1);
        expect(metrics.not_confirmed_orders.find(order => order.id === missed.id)?.platforms).toEqual(['facebook']);
        expect(metrics.not_confirmed_orders.map(order => order.id)).not.toContain(noCheckIn);

        // Stat cards and the chart render from the route.
        await expect(page.getByTestId('wcct-stat-purchases')).toContainText(`${metrics.purchases}`);
        await expect(page.getByTestId('wcct-stat-purchases')).toContainText('Purchases tracked');
        await expect(page.getByTestId('wcct-stat-not-confirmed')).toContainText(`${metrics.not_confirmed}`);
        await expect(page.getByTestId('wcct-stat-working')).toContainText(`${metrics.platforms_working} of ${metrics.platforms_connected}`);
        // The chart's numbers for screen readers: a caption and a row per hour.
        await expect(page.getByTestId('wcct-hourly-chart').locator('table caption')).toHaveText(
            new RegExp(`^Bar chart: ${metrics.purchases} purchases? in the last 24 hours; ${metrics.sent} sent to every connected platform, ${metrics.not_confirmed} not confirmed on a platform\\.$`)
        );
        await expect(page.getByTestId('wcct-hourly-chart').locator('table tbody tr')).toHaveCount(24);
        await expect(page.getByTestId('wcct-hourly-chart').locator('path.recharts-rectangle').first()).toBeVisible();
        await expect(page.getByTestId('wcct-no-checkin-note')).toContainText(
            new RegExp(`^${metrics.no_checkin} orders? couldn't be checked: no thank-you page visit`)
        );

        // The Not confirmed card names the order and Meta, never a cause.
        const card = page.getByTestId('wcct-not-confirmed-card');

        await expect(card).toContainText('Worth a look');
        await expect(card).toContainText(`#${missed.id}`);
        await expect(card).toContainText(/We couldn't confirm that \d+ purchases? (was|were) sent to Meta/);
        await expect(card.getByText('Open the orders in the Activity log')).toHaveAttribute('href', '#/log?tab=not-confirmed');
        await expect(card.getByText('Check cookie choices')).toHaveAttribute('href', '#/platforms?section=cookie-choices');

        // Meta's card needs attention; the filter chips follow the states.
        const meta = page.getByTestId('wcct-platform-card-facebook');

        await expect(meta).toHaveAttribute('data-state', 'attention');
        await expect(meta).toContainText('Needs attention');
        await expect(page.getByTestId('wcct-platform-note-facebook')).toContainText(/We couldn't confirm \d+ purchases? in the last 24 hours\./);
        await page.getByRole('radio', { name: 'Needs attention' }).or(page.getByRole('button', { name: 'Needs attention' })).first().click();
        await expect(meta).toBeVisible();

        for (const platform of metrics.platforms.filter(p => p.state === 'working')) {
            await expect(page.getByTestId(`wcct-platform-card-${platform.id}`)).toHaveCount(0);
        }

        await page.getByRole('radio', { name: 'All' }).or(page.getByRole('button', { name: 'All', exact: true })).first().click();

        // What to do next lists Meta's not confirmed purchases.
        await expect(page.getByTestId('wcct-next-step-not-confirmed-facebook')).toContainText("See which purchases Meta didn't confirm");
        await expect(page.getByTestId('wcct-next-step-cookie-choices').getByRole('link', { name: 'Check cookie choices' })).toHaveAttribute('href', '#/platforms?section=cookie-choices');

        // The menu badge counts the same orders, on every admin page.
        await page.goto('/wp-admin/index.php');
        await expect(page.locator(MENU_BADGE)).toContainText(`${metrics.not_confirmed}`);
        await expect(page.locator(`${MENU_BADGE} .screen-reader-text`)).toHaveText(new RegExp(`^${metrics.not_confirmed} purchases? not confirmed$`));
    });

    test('the alert switches save and hide the badge, the card and "Doctor found something new" without losing the status', async ({ page }) => {
        await loginAsAdmin(page);

        try {
            // Both on to start with, as on a new store.
            await setAlert(page, NOT_CONFIRMED_SWITCH, true);
            await setAlert(page, DOCTOR_SWITCH, true);
            await page.reload();
            await expect(page.locator('#wcct-app-root h1')).toBeVisible();

            const section = page.getByTestId('wcct-settings-alerts');

            await expect(section).toContainText("We tell you when we couldn't confirm a sale was sent to a platform. Every alert ends in something you can do.");
            await expect(section).toContainText("A badge and a card when we couldn't confirm a paid order was sent to a connected platform within 10 minutes.");
            await expect(section).toContainText('After a plugin is activated, a quiet re-check. Dashboard only, never a popup.');
            await expect(page.getByTestId('wcct-alerts-save')).toBeDisabled();

            // An unsaved switch asks before leaving.
            await page.getByRole('switch', { name: NOT_CONFIRMED_SWITCH }).click();
            await expect(section).toContainText('Unsaved changes');
            page.once('dialog', dialog => dialog.dismiss());
            await page.getByRole('link', { name: 'Setup' }).click();
            await expect(page).toHaveURL(/#\/settings\/alerts$/);
            await page.getByRole('switch', { name: NOT_CONFIRMED_SWITCH }).click();
            await expect(page.getByTestId('wcct-alerts-save')).toBeDisabled();

            expect(await setAlert(page, NOT_CONFIRMED_SWITCH, false)).toBe(true);
            expect(await setAlert(page, DOCTOR_SWITCH, false)).toBe(true);

            // Saved: both stay off after a reload.
            await page.reload();
            await expect(page.getByRole('switch', { name: NOT_CONFIRMED_SWITCH })).toHaveAttribute('aria-checked', 'false');
            await expect(page.getByRole('switch', { name: DOCTOR_SWITCH })).toHaveAttribute('aria-checked', 'false');

            const metrics = await openDashboard(page);

            expect(metrics.alerts).toEqual({ not_confirmed: false, doctor_new: false });
            expect(metrics.not_confirmed_orders).toEqual([]);
            await expect(page.getByTestId('wcct-not-confirmed-card')).toHaveCount(0);
            await expect(page.getByTestId('wcct-doctor-new')).toHaveCount(0);
            await expect(page.locator(MENU_BADGE)).toHaveCount(0);

            // The data is still recorded: the stat card and the log keep the status.
            await expect(page.getByTestId('wcct-stat-not-confirmed')).toContainText(`${metrics.not_confirmed}`);

            if (metrics.not_confirmed > 0) {
                await openApp(page, '#/log?tab=not-confirmed');
                await expect(page.getByTestId('wcct-log').locator('tbody tr').first()).toContainText('Not confirmed on');
            }
        } finally {
            await setAlert(page, NOT_CONFIRMED_SWITCH, true);
            await setAlert(page, DOCTOR_SWITCH, true);
        }
    });

    test('each platform card state renders from the route', async ({ page }) => {
        await loginAsAdmin(page);

        // Stubbed: every state at once, and nothing amber in what to do next.
        await page.route(METRICS_ROUTE, async route => {
            const response = await route.fetch();
            const real = (await response.json()) as Metrics & { platforms: Record<string, unknown>[] };
            const notes: Record<string, { state: string; note: { text: string; link: string; link_label: string } | null }> = {
                facebook: { state: 'working', note: { text: 'Last purchase 2 minutes ago, #1312', link: '', link_label: '' } },
                google: { state: 'attention', note: { text: 'The Ads conversion label looks incomplete.', link: '#/platforms/google?field=ads_label', link_label: 'Fix it' } },
                twitter: { state: 'waiting', note: { text: 'Connected 5 minutes ago. This updates when the first purchase checks in. Place a test order to see it now.', link: '', link_label: '' } },
            };

            await route.fulfill({
                response,
                json: {
                    ...real,
                    purchases: 0,
                    sent: 0,
                    not_confirmed: 0,
                    not_confirmed_by: {},
                    no_checkin: 0,
                    not_confirmed_orders: [],
                    next_steps: [],
                    platforms: real.platforms.map(platform => ({ ...platform, ...( notes[platform.id as string] ?? {} ) })),
                },
            });
        });

        await openDashboard(page);

        const states = { facebook: ['working', 'Working'], google: ['attention', 'Needs attention'], twitter: ['waiting', 'Waiting for first event'] };

        for (const [id, [state, pill]] of Object.entries(states)) {
            const card = page.getByTestId(`wcct-platform-card-${id}`);

            if (!(await card.count())) {
                continue;
            }

            await expect(card).toHaveAttribute('data-state', state);
            await expect(card).toContainText(pill);
        }

        await expect(page.getByTestId('wcct-platform-note-google').getByRole('link', { name: 'Fix it' })).toHaveAttribute('href', '#/platforms/google?field=ads_label');
        await expect(page.getByTestId('wcct-platform-note-twitter')).toContainText('Connected 5 minutes ago.');
        await expect(page.getByTestId('wcct-not-confirmed-card')).toHaveCount(0);
        await expect(page.getByTestId('wcct-no-checkin-note')).toHaveCount(0);
        await expect(page.getByTestId('wcct-next-steps')).toContainText('Nothing to do right now.');
        await expect(page.getByText('Purchases show here, hour by hour, once your store tracks them.')).toBeVisible();
    });
});
