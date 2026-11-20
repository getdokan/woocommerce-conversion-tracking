import { Browser, Page } from '@playwright/test';
import { test, expect } from '../utils/fixtures';
import { NetworkTracker } from '../utils/network';
import { BASE_URL } from '../utils/site';
import { SHOP_MANAGER, login, loginAsAdmin, openApp } from '../utils/admin';
import { placeGuestOrder } from '../utils/checkout';

// The Custom script page (#106): three placements, the placeholders and the customer details switch.

const ROUTE = '#/platforms/custom';
const SETTINGS_ROUTE = /wcct(\/|%2F)v1(\/|%2F)settings(\?|&|$)/;

const hit = (placement: string, query = '') =>
    `<script>(new Image()).src = 'https://custom.ct-e2e.test/${placement}?${query}page=' + encodeURIComponent(location.pathname);</script>`;

const RESET = {
    thankyou_enabled: false,
    thankyou_script: '',
    header_enabled: false,
    header_script: '',
    signup_enabled: false,
    signup_script: '',
    customer_details: false,
};

const customHits = (tracker: NetworkTracker, placement: string): URL[] =>
    tracker.external.map(url => new URL(url)).filter(url => url.hostname === 'custom.ct-e2e.test' && url.pathname === `/${placement}`);

const proVersion = (page: Page): Promise<string> =>
    page.evaluate(() => (window as unknown as { wcctAdmin?: { proVersion: string } }).wcctAdmin?.proVersion ?? '');

// Posts to the settings route as the logged-in user.
const postSettings = (page: Page, custom: object): Promise<{ ok: boolean; status?: number; code?: string; fields?: string[] }> =>
    page.evaluate(async data => {
        const wp = (window as unknown as { wp: { apiFetch: (options: object) => Promise<unknown> } }).wp;

        try {
            await wp.apiFetch({ path: '/wcct/v1/settings', method: 'POST', data: { platforms: { custom: data } } });

            return { ok: true };
        } catch (error) {
            const { code, data: info } = error as { code?: string; data?: { status?: number; fields?: string[] } };

            return { ok: false, code, status: info?.status, fields: info?.fields };
        }
    }, custom);

const postAccess = (page: Page, roles: string[]): Promise<unknown> =>
    page.evaluate(async data => {
        const wp = (window as unknown as { wp: { apiFetch: (options: object) => Promise<unknown> } }).wp;

        return wp.apiFetch({ path: '/wcct/v1/access', method: 'POST', data: { roles: data } });
    }, roles);

const resetStore = async (browser: Browser): Promise<void> => {
    const page = await browser.newPage({ baseURL: BASE_URL });

    await loginAsAdmin(page);
    await openApp(page, '#/dashboard');
    expect(await postSettings(page, RESET)).toEqual({ ok: true });

    if (await proVersion(page)) {
        await postAccess(page, []);
    }

    await page.close();
};

test.describe.serial('Custom script page', () => {
    test.beforeAll(async ({ browser }) => resetStore(browser));
    test.afterAll(async ({ browser }) => resetStore(browser));

    test('shows three placements, the placeholders and customer details off', async ({ page }) => {
        await loginAsAdmin(page);
        await openApp(page, ROUTE);

        const detail = page.getByTestId('wcct-custom-script');

        await expect(page.locator('#wcct-app-root h1')).toHaveText('Platforms');
        await expect(page.locator('nav[aria-labelledby="wcct-platform-list-label"] a[aria-current="page"]')).toContainText('Custom script');
        await expect(detail.getByRole('heading', { name: 'Custom script', exact: true })).toBeVisible();
        await expect(detail).toContainText('Only administrators can add or change custom scripts');

        for (const name of ['Thank-you page', 'Every page, in the header', 'Sign-up']) {
            await expect(detail.getByRole('switch', { name })).not.toBeChecked();
        }

        // The code boxes open with their switch.
        await expect(detail.getByLabel('Script for thank-you page')).toHaveCount(0);

        const placeholders = page.getByTestId('wcct-custom-placeholders');

        await expect(placeholders.getByRole('button', { name: /^Insert / })).toHaveText([
            '{order_number}',
            '{order_total}',
            '{order_subtotal}',
            '{order_discount}',
            '{order_shipping}',
            '{currency}',
            '{payment_method}',
            '{customer_id}',
            '{customer_email}',
            '{customer_first_name}',
            '{customer_last_name}',
        ]);
        await expect(placeholders).toContainText('{order_id} is the same as {order_number}, and {used_coupons} gives the coupon codes');
        await expect(placeholders.getByRole('button', { name: /^Insert / }).first()).toBeDisabled();
        await expect(placeholders).toContainText('Turn on a placement');

        const customer = detail.getByRole('switch', { name: 'Include customer details in scripts' });

        await expect(customer).not.toBeChecked();
        await expect(detail).toContainText("Shares the shopper's ID, email and name with your scripts on a verified order");
        await expect(detail).toContainText('wait for consent when Respect cookie choices is on');
        await expect(detail).toContainText('Only administrators can edit scripts.');
        await expect(page.getByTestId('wcct-custom-save')).toBeDisabled();
    });

    test('an administrator edits each placement, inserts placeholders at the cursor and saves', async ({ page }) => {
        await loginAsAdmin(page);
        await openApp(page, ROUTE);

        const detail = page.getByTestId('wcct-custom-script');
        const placeholders = page.getByTestId('wcct-custom-placeholders');

        await detail.getByRole('switch', { name: 'Thank-you page' }).click();

        const thankyou = detail.getByLabel('Script for thank-you page');
        const prefix = "<script>(new Image()).src = 'https://custom.ct-e2e.test/thankyou?order=";
        const rest = "&total={order_total}&page=' + encodeURIComponent(location.pathname);</script>";

        await thankyou.fill(prefix + rest);
        // Put the caret right after "order=" and insert from the list.
        await thankyou.evaluate((element: HTMLTextAreaElement, at) => {
            element.focus();
            element.setSelectionRange(at, at);
        }, prefix.length);
        await expect(placeholders).toContainText('put it in the "Thank-you page" script at the cursor');
        await placeholders.getByRole('button', { name: 'Insert {order_number}' }).click();
        await expect(thankyou).toHaveValue(`${prefix}{order_number}${rest}`);
        await expect(thankyou).toBeFocused();

        await detail.getByRole('switch', { name: 'Every page, in the header' }).click();
        await detail.getByLabel('Script for every page, in the header').fill(hit('header'));
        await detail.getByRole('switch', { name: 'Sign-up' }).click();
        await detail.getByLabel('Script for sign-up').fill(hit('signup'));

        await expect(page.getByTestId('wcct-custom-note')).toHaveText('You have unsaved changes.');

        const saved = page.waitForResponse(response => SETTINGS_ROUTE.test(response.url()) && response.request().method() === 'POST');

        await page.getByTestId('wcct-custom-save').click();
        expect((await saved).status()).toBe(200);
        await expect(page.getByTestId('wcct-custom-note')).toHaveText('Saved.');
        await expect(page.getByTestId('wcct-custom-save')).toHaveText('Saved');
        await expect(detail.getByText('On', { exact: true }).first()).toBeVisible();

        await page.reload();
        await expect(page.getByTestId('wcct-custom-script')).toBeVisible({ timeout: 30_000 });
        await expect(detail.getByRole('switch', { name: 'Sign-up' })).toBeChecked();
        await expect(detail.getByLabel('Script for thank-you page')).toHaveValue(`${prefix}{order_number}${rest}`);
        await expect(detail.getByLabel('Script for every page, in the header')).toHaveValue(hit('header'));
    });

    test('the storefront runs each saved placement', async ({ page, tracker }) => {
        await page.goto('/');
        await expect.poll(() => customHits(tracker, 'header').length, { message: 'header hit' }).toBe(1);

        const order = await placeGuestOrder(page, 'block');

        await expect.poll(() => customHits(tracker, 'thankyou').length, { message: 'thank-you hit' }).toBe(1);
        expect(customHits(tracker, 'thankyou')[0].searchParams.get('order')).toBe(String(order.id));
        expect(Number(customHits(tracker, 'thankyou')[0].searchParams.get('total'))).toBeGreaterThan(0);

        await page.context().clearCookies();
        await page.goto('/my-account/');
        await page.locator('#reg_email').fill(`ct-custom-page-${Date.now()}@example.com`);
        await page.locator('button[name="register"]').click();
        await expect(page.locator('.woocommerce-MyAccount-navigation')).toBeVisible();
        await expect.poll(() => customHits(tracker, 'signup').length, { message: 'sign-up hit' }).toBe(1);
    });

    test('validation, placeholder hints, the unsaved changes guard and a pending migration', async ({ page, jsErrors }) => {
        // The browser logs the stubbed 409.
        jsErrors.allow(/status of 409/);
        await loginAsAdmin(page);
        await openApp(page, ROUTE);

        const detail = page.getByTestId('wcct-custom-script');
        const header = detail.getByLabel('Script for every page, in the header');

        await header.fill('');
        await page.getByTestId('wcct-custom-save').click();
        await expect(detail).toContainText('Paste a script, or turn this placement off.');
        await expect(header).toBeFocused();
        await expect(header).toHaveAttribute('aria-invalid', 'true');

        await header.fill("<script>t('{order_total}', '{customer_email}', '{order_totl}');</script>");
        await expect(header).not.toHaveAttribute('aria-invalid', 'true');

        const hints = page.getByTestId('wcct-custom-header-hints');

        await expect(hints).toContainText("{order_totl} isn't a placeholder");
        await expect(hints).toContainText('{order_total} stays empty here. Order placeholders fill in only on the thank-you page.');
        await expect(hints).toContainText('{customer_email} stays empty here.');

        // Leaving with unsaved changes asks first; dismissing stays on the page.
        page.once('dialog', dialog => dialog.dismiss());
        await page.locator('#wcct-topbar-root nav a', { hasText: 'Dashboard' }).click();
        await expect(page).toHaveURL(/#\/platforms\/custom$/);
        await expect(header).toHaveValue(/order_totl/);

        // A save while the migration runs keeps the draft and offers a retry.
        await page.route(SETTINGS_ROUTE, route =>
            route.request().method() === 'POST'
                ? route.fulfill({ status: 409, json: { code: 'wcct_rest_migration_pending', message: 'Pending', data: { status: 409 } } })
                : route.fallback()
        );
        await page.getByTestId('wcct-custom-save').click();
        await expect(page.getByTestId('wcct-custom-migration-pending')).toContainText('still being updated to 3.0');
        await expect(page.getByTestId('wcct-custom-save')).toHaveText('Save changes');

        await page.unroute(SETTINGS_ROUTE);
        await header.fill(hit('header'));
        await page.getByTestId('wcct-custom-migration-pending').getByRole('button', { name: 'Try again' }).click();
        await expect(page.getByTestId('wcct-custom-note')).toHaveText('Saved.');
        await expect(page.getByTestId('wcct-custom-migration-pending')).toHaveCount(0);
    });

    test('without unfiltered_html the page is read-only and the server refuses a save', async ({ page, context, jsErrors }) => {
        jsErrors.allow(/status of 403/);
        await context.addCookies([{ name: 'ct_e2e_no_unfiltered_html', value: '1', url: BASE_URL }]);
        await loginAsAdmin(page);
        await openApp(page, ROUTE);

        const detail = page.getByTestId('wcct-custom-script');

        await expect(page.getByTestId('wcct-custom-read-only')).toContainText('You can see these scripts but not change them.');
        await expect(page.getByTestId('wcct-custom-read-only')).toContainText('Only administrators can add or change custom scripts');

        for (const name of ['Thank-you page', 'Every page, in the header', 'Sign-up', 'Include customer details in scripts']) {
            await expect(detail.getByRole('switch', { name })).toBeDisabled();
        }

        await expect(detail.getByLabel('Script for every page, in the header')).toHaveValue(hit('header'));
        await expect(detail.getByLabel('Script for every page, in the header')).toHaveAttribute('readonly', '');
        await expect(page.getByTestId('wcct-custom-placeholders').getByRole('button', { name: /^Insert / }).first()).toBeDisabled();
        await expect(page.getByTestId('wcct-custom-save')).toHaveCount(0);

        expect(await postSettings(page, { header_script: '<script>evil()</script>', customer_details: true })).toEqual({
            ok: false,
            code: 'wcct_rest_forbidden_fields',
            status: 403,
            fields: ['platforms.custom.header_script', 'platforms.custom.customer_details'],
        });
    });

    test('a granted shop manager (Pro) sees the scripts read-only', async ({ page, jsErrors }) => {
        jsErrors.allow(/status of 403/);
        await loginAsAdmin(page);
        await openApp(page, '#/dashboard');
        test.skip(!(await proVersion(page)), 'Pro is not mapped into this store');
        await postAccess(page, ['shop_manager']);

        await page.context().clearCookies();
        await login(page, SHOP_MANAGER.user, SHOP_MANAGER.password);
        await openApp(page, ROUTE);

        await expect(page.getByTestId('wcct-custom-read-only')).toBeVisible();
        await expect(page.getByTestId('wcct-custom-script').getByRole('switch', { name: 'Sign-up' })).toBeDisabled();
        expect((await postSettings(page, { signup_enabled: false })).status).toBe(403);
    });
});
