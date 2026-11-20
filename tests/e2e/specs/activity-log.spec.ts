import { APIRequestContext, Locator, Page } from '@playwright/test';
import { test, expect } from '../utils/fixtures';
import { addProductToCart, fillBlockCheckout, placeGuestOrder } from '../utils/checkout';
import { enabledTags } from '../utils/platforms';
import { loginAsAdmin, openApp } from '../utils/admin';

// Past the 10-minute settle time (Log\Statuses::SETTLE).
const PAST_SETTLE = 660;

const LOG_ROUTE = /wcct(\/|%2F)v1(\/|%2F)log/;

interface LogRow {
    platform: string;
    library_loaded: string;
}

const logRows = async (request: APIRequestContext, orderId: number): Promise<LogRow[]> =>
    (await (await request.get(`/?rest_route=/ct-e2e/v1/order-log/${orderId}`)).json()).rows;

// Moves an order, its thank-you flag and its log rows back in time (mu-plugins/ct-e2e-helpers.php).
const age = async (request: APIRequestContext, orderId: number, seconds = PAST_SETTLE): Promise<void> => {
    expect((await request.post(`/?rest_route=/ct-e2e/v1/order-log/${orderId}/age`, { data: { seconds } })).ok()).toBe(true);
};

// A guest order whose thank-you page never loads, like an off-site payment that never comes back.
const placeOrderWithoutThankYou = async (page: Page): Promise<number> => {
    await addProductToCart(page);
    await page.route(/\/order-received\//, route => route.abort('aborted'));

    // Wait until the redirect has failed, so the route still holds when it is handled.
    const failed = page.waitForEvent('requestfailed', { predicate: request => /\/order-received\/\d+\//.test(request.url()), timeout: 30_000 });

    await fillBlockCheckout(page);

    const id = Number(new URL((await failed).url()).pathname.match(/\/order-received\/(\d+)/)![1]);

    await page.unroute(/\/order-received\//);

    return id;
};

// The block checkout remembers a guest's address and folds the form; a new session gets the empty form back.
const newGuest = async (page: Page): Promise<void> => {
    await page.context().clearCookies();
};

const row = (page: Page, orderId: number): Locator => page.getByTestId('wcct-log').locator('tbody tr', { hasText: new RegExp(`#${orderId}(?!\\d)`) });

test.describe('Activity log', () => {
    test('shows Counted once, Not confirmed on Meta and No check-in from real orders, and never an admin order', async ({ page, request, jsErrors }) => {
        test.skip(!enabledTags().includes('meta'), 'Needs the Meta tag.');
        jsErrors.allow(/ERR_BLOCKED_BY_CLIENT.*facebook\.(net|com)/);
        jsErrors.allow(/ERR_ABORTED|net::ERR_FAILED/);

        // Counted once: every platform hands over on the thank-you page.
        const counted = await placeGuestOrder(page, 'block');

        // Stay on the thank-you page until every tag handed over (Pro's Microsoft tag too), or a slow one reads as hidden.
        const tags = enabledTags().length + ((await page.evaluate(() => 'uetq' in window)) ? 1 : 0);

        await expect
            .poll(async () => (await logRows(request, counted.id)).filter(r => r.library_loaded === '1').length, { timeout: 20_000 })
            .toBeGreaterThanOrEqual(tags);

        // Not confirmed on Meta: an ad blocker stops fbevents.js, so Meta's check-in says not handed over.
        await page.route(/connect\.facebook\.net|facebook\.com\/tr/, route => route.abort('blockedbyclient'));
        await newGuest(page);

        const missed = await placeGuestOrder(page, 'block');

        await expect
            .poll(async () => (await logRows(request, missed.id)).find(r => r.platform === 'facebook')?.library_loaded ?? null, { timeout: 20_000 })
            .toBe('0');
        await page.unroute(/connect\.facebook\.net|facebook\.com\/tr/);

        // No check-in: the thank-you page never loads.
        await newGuest(page);

        const noCheckIn = await placeOrderWithoutThankYou(page);

        // An order created in admin never shows.
        const adminOrder = (await (await request.post('/?rest_route=/ct-e2e/v1/admin-order')).json()).id as number;

        for (const id of [counted.id, missed.id, noCheckIn, adminOrder]) {
            await age(request, id);
        }

        await loginAsAdmin(page);
        await openApp(page, '#/log');

        await expect(page.getByRole('heading', { level: 1, name: 'Activity log' })).toBeVisible();
        await expect(page.getByText('Last 24 hours', { exact: true })).toBeVisible();
        await expect(row(page, counted.id)).toContainText('Counted once');
        await expect(row(page, missed.id)).toContainText('Not confirmed on Meta');
        await expect(row(page, noCheckIn)).toContainText('No check-in');
        await expect(row(page, noCheckIn)).toContainText('Not checked: no thank-you page visit');
        await expect(row(page, adminOrder)).toHaveCount(0);

        // Order numbers and amounts only.
        await expect(page.getByTestId('wcct-log')).not.toContainText('guest@example.com');
        await expect(page.getByTestId('wcct-log')).not.toContainText('Casey');

        // The detail of the not confirmed order names Meta without claiming a cause.
        await row(page, missed.id).getByRole('button', { name: `View order #${missed.id}` }).click();

        const detail = page.getByTestId('wcct-log-detail-pane');

        await expect(detail.getByRole('heading', { name: `Order #${missed.id}` })).toBeVisible();
        await expect(page.getByTestId('wcct-log-live')).toHaveText(`Showing order #${missed.id}: Not confirmed on Meta`);
        await expect(detail).toContainText('Order created');
        await expect(detail).toContainText('Thank-you page seen');
        await expect(detail.locator('[data-platform="facebook"]')).toContainText('We couldn’t confirm this purchase was sent');
        await expect(detail.locator('[data-platform="facebook"]')).toHaveAttribute('data-status', 'not_confirmed');
        await expect(detail.locator('[data-platform="google"]')).toContainText('Reported to the log');

        // Clicking the row (not only View) opens it too.
        await row(page, noCheckIn).locator('td').first().click();
        await expect(detail.getByRole('heading', { name: `Order #${noCheckIn}` })).toBeVisible();
        await expect(detail).toContainText('The shopper didn’t reach the thank-you page, so it never checked in');

        // Tabs: No check-in shows under All only.
        await page.getByRole('tab', { name: 'Not confirmed' }).click();
        await expect(page).toHaveURL(/#\/log\?tab=not-confirmed$/);
        await expect(row(page, missed.id)).toBeVisible();
        await expect(row(page, counted.id)).toHaveCount(0);
        await expect(row(page, noCheckIn)).toHaveCount(0);

        await page.getByRole('tab', { name: 'Tracked' }).click();
        await expect(row(page, counted.id)).toBeVisible();
        await expect(row(page, missed.id)).toHaveCount(0);
        await expect(row(page, noCheckIn)).toHaveCount(0);

        await page.getByRole('tab', { name: 'All' }).click();
        await expect(page).toHaveURL(/#\/log$/);
        await expect(row(page, noCheckIn)).toBeVisible();
        await expect(page.getByTestId('wcct-log-count')).toContainText(/\d+ orders? in the last 24 hours/);

        // A link with ?tab= opens that tab (the dashboard's Not confirmed card).
        await openApp(page, '#/log?tab=not-confirmed');
        await expect(page.getByRole('tab', { name: 'Not confirmed' })).toHaveAttribute('aria-selected', 'true');
        await expect(row(page, missed.id)).toBeVisible();

        // Below 1100 px the detail opens in a dialog.
        await page.setViewportSize({ width: 1000, height: 900 });
        await expect(page.getByTestId('wcct-log-detail-pane')).toHaveCount(0);
        await row(page, missed.id).getByRole('button', { name: `View order #${missed.id}` }).click();

        const dialog = page.getByRole('dialog', { name: 'Order details' });

        await expect(dialog).toBeVisible();
        await expect(dialog.getByRole('heading', { name: `Order #${missed.id}` })).toBeVisible();
        await expect(dialog).toContainText('We couldn’t confirm this purchase was sent');
        await page.keyboard.press('Escape');
        await expect(dialog).toHaveCount(0);
    });

    test('a not handed over check-in waits 10 minutes before it says Not confirmed', async ({ page, request, jsErrors }) => {
        test.skip(!enabledTags().includes('meta'), 'Needs the Meta tag.');
        jsErrors.allow(/ERR_BLOCKED_BY_CLIENT.*facebook\.(net|com)/);

        await page.route(/connect\.facebook\.net|facebook\.com\/tr/, route => route.abort('blockedbyclient'));

        const order = await placeGuestOrder(page, 'block');

        await expect
            .poll(async () => (await logRows(request, order.id)).find(r => r.platform === 'facebook')?.library_loaded ?? null, { timeout: 20_000 })
            .toBe('0');

        await loginAsAdmin(page);
        await openApp(page, '#/log');
        await expect(row(page, order.id)).toContainText('Checking');

        await row(page, order.id).getByRole('button', { name: `View order #${order.id}` }).click();
        await expect(page.getByTestId('wcct-log-detail-pane').locator('[data-platform="facebook"]')).toContainText('Not confirmed yet');

        await age(request, order.id);
        await page.reload();
        await expect(row(page, order.id)).toContainText('Not confirmed on Meta');
    });

    test('the empty state and a load error', async ({ page, jsErrors }) => {
        jsErrors.allow(/status of 500/);

        await loginAsAdmin(page);

        await page.route(LOG_ROUTE, route =>
            route.fulfill({
                json: { orders: [], counts: { all: 0, tracked: 0, 'not-confirmed': 0, pending: 0 }, total: 0, pages: 0, page: 1, per_page: 20, since: '', settle: 600 },
            })
        );
        await openApp(page, '#/log');
        await expect(page.getByTestId('wcct-log-empty')).toContainText('Waiting for your first event');
        await expect(page.getByTestId('wcct-log-empty')).toContainText('No events yet. Place a test order and come back in a minute.');

        await page.unroute(LOG_ROUTE);
        await page.route(LOG_ROUTE, route => route.fulfill({ status: 500, json: { code: 'ct_e2e_error', message: 'Something broke.' } }));
        await page.reload();
        const alert = page.getByRole('alert').filter({ hasText: 'Could not load the Activity log.' });

        await expect(alert).toContainText('Something broke.');

        // Try again reads the log again.
        await page.unroute(LOG_ROUTE);
        await alert.getByRole('button', { name: 'Try again' }).click();
        await expect(page.getByTestId('wcct-log').or(page.getByTestId('wcct-log-empty'))).toBeVisible();
    });
});
