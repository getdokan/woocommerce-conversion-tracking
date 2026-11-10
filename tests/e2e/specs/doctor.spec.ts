import { BrowserContext, Page } from '@playwright/test';
import { test, expect } from '../utils/fixtures';
import { APP_PATH, isProActive, loginAsAdmin, openApp } from '../utils/admin';
import { BASE_URL } from '../utils/site';

// The sample checks in mu-plugins/ct-e2e/doctor-checks.php read these cookies.
const setCookies = async (context: BrowserContext, cookies: Record<string, string>): Promise<void> => {
    await context.addCookies(Object.entries(cookies).map(([name, value]) => ({ name, value, url: BASE_URL })));
};

// Forgets every Doctor run, dismissal and badge (ct-e2e-helpers.php).
const resetDoctor = async (page: Page): Promise<void> => {
    await page.goto('/wp-admin/?ct_e2e_doctor_reset=1');
};

const finding = (page: Page, text: string) => page.getByTestId('wcct-finding').filter({ hasText: text });

test.describe('Tracking Doctor', () => {
    test.beforeEach(async ({ page, context }) => {
        await loginAsAdmin(page);
        await resetDoctor(page);
        // The framework with Lite's consent note only; doctor-checks.spec.ts covers the other checks.
        await setCookies(context, { ct_e2e_doctor_checks: 'consent-off' });
    });

    test('the first visit runs the checks and shows "Everything looks good" with the Working list collapsed', async ({ page, context }) => {
        await setCookies(context, { ct_e2e_consent: 'on' });
        await openApp(page, '#/doctor');

        const summary = page.getByTestId('wcct-doctor-summary');
        // Only a running Pro 3.0 adds its check; a paused Pro 1.x adds none.
        const checks = (await isProActive(page)) ? 2 : 1;

        await expect(summary).toContainText('Everything looks good');
        await expect(summary).toContainText(`We checked ${checks} thing${checks > 1 ? 's' : ''}.`);
        await expect(page.getByText(/Check finished\. We checked/)).toBeVisible();
        await expect(page.getByTestId('wcct-doctor-last-check')).toHaveText(/^Last check: today /);
        await expect(page.getByTestId('wcct-finding')).toHaveCount(0);

        const working = page.getByTestId('wcct-doctor-working');
        const passed = working.getByText('Respect cookie choices is on');

        await expect(working.locator('details')).not.toHaveAttribute('open', '');
        await expect(passed).toBeHidden();
        await working.locator('summary').click();
        await expect(passed).toBeVisible();

        // A second visit shows the stored run instead of running again.
        const runs: string[] = [];

        page.on('request', request => {
            if (request.method() === 'POST' && /wcct(\/|%2F)v1(\/|%2F)doctor/.test(request.url())) {
                runs.push(request.url());
            }
        });
        await page.reload();
        await expect(page.getByTestId('wcct-doctor-summary')).toContainText('Everything looks good');
        expect(runs).toEqual([]);
    });

    test('findings come first, Dismiss offers Undo, and a changed finding comes back', async ({ page, context }) => {
        await setCookies(context, { ct_e2e_doctor: 'Pixel Plugin A', ct_e2e_consent: 'off' });
        await openApp(page, '#/doctor');

        const checks = (await isProActive(page)) ? 4 : 3;
        const summary = page.getByTestId('wcct-doctor-summary');

        await expect(summary).toContainText(`We checked ${checks} things.`);
        await expect(summary).toContainText('1 is worth a look and 1 thing we can’t be sure about.');

        // Worth a look (sure, then unsure), then Good to know, then the Working list.
        const headings = page.locator('#wcct-app-root h2, #wcct-app-root h3');

        await expect(headings).toHaveText([
            `We checked ${checks} things.`,
            'Worth a look',
            'Pixel Plugin A is also active',
            'An e2e tag manager is active',
            'Good to know',
            'Consent mode is off',
        ]);

        const duplicate = finding(page, 'Pixel Plugin A is also active');

        await expect(duplicate).toContainText('Duplicate');
        await expect(duplicate).toContainText('If its pixel is on, each sale reaches Meta twice.');
        await expect(duplicate.getByRole('listitem')).toHaveText(['Pick one plugin to send to Meta.', 'Then check again.']);
        await expect(finding(page, 'An e2e tag manager is active')).toContainText('Not sure');
        await expect(finding(page, 'Consent mode is off').getByRole('link', { name: 'Open the setting' })).toHaveAttribute('href', '#/platforms');

        await duplicate.getByRole('button', { name: 'Dismiss' }).click();
        await expect(page.getByText('Dismissed. It won’t show again unless something changes.')).toBeVisible();
        await expect(duplicate).toHaveCount(0);
        // Focus moves to the next finding's Dismiss.
        await expect(finding(page, 'An e2e tag manager is active').getByRole('button', { name: 'Dismiss' })).toBeFocused();
        await page.getByRole('button', { name: 'Undo' }).click();
        await expect(duplicate).toBeVisible();
        // Let the Undo toast leave before the next Dismiss (it never covers a Dismiss button at this viewport).
        await expect(page.locator('[data-sonner-toast]')).toHaveCount(0);

        await duplicate.getByRole('button', { name: 'Dismiss' }).click();
        await expect(duplicate).toHaveCount(0);
        await finding(page, 'Consent mode is off').getByRole('button', { name: 'Dismiss' }).click();
        await finding(page, 'An e2e tag manager is active').getByRole('button', { name: 'Dismiss' }).click();
        await expect(page.getByTestId('wcct-doctor-all-dismissed')).toContainText('Nothing left to look at');
        // After the last one, focus goes to the summary heading.
        await expect(page.locator('#wcct-doctor-summary-title')).toBeFocused();

        // Dismissals are stored, so they survive a reload and another run.
        await page.reload();
        await expect(page.getByTestId('wcct-doctor-all-dismissed')).toBeVisible();
        await page.getByRole('button', { name: 'Check my store' }).click();
        await expect(page.getByText(/Check finished\./)).toBeVisible();
        await expect(page.getByTestId('wcct-doctor-all-dismissed')).toBeVisible();

        // Another plugin is new evidence: the finding is back.
        await setCookies(context, { ct_e2e_doctor: 'Pixel Plugin B' });
        await page.getByRole('button', { name: 'Check my store' }).click();
        await expect(finding(page, 'Pixel Plugin B is also active')).toBeVisible();
        await expect(page.getByTestId('wcct-doctor-all-dismissed')).toHaveCount(0);
    });

    test('a first run that fails says "Not checked yet", never "Everything looks good"', async ({ page, context, jsErrors }) => {
        await setCookies(context, { ct_e2e_consent: 'on' });
        jsErrors.allow(/Failed to load resource/);
        await page.route(/wcct(\/|%2F)v1(\/|%2F)doctor/, async (route, request) => {
            if (request.method() === 'POST') {
                return route.fulfill({ status: 500, json: { code: 'internal_server_error', message: 'Broken on purpose.' } });
            }

            return route.continue();
        });
        await openApp(page, '#/doctor');

        await expect(page.getByTestId('wcct-doctor-summary')).toContainText('Not checked yet');
        await expect(page.getByText('Broken on purpose.')).toBeVisible();
        await expect(page.getByText('Everything looks good')).toHaveCount(0);
        await expect(page.getByTestId('wcct-doctor-live')).toHaveAttribute('role', 'status');

        // The button stays enabled and focus moves to the summary after a run.
        await page.unroute(/wcct(\/|%2F)v1(\/|%2F)doctor/);
        await page.getByRole('button', { name: 'Check my store' }).click();
        await expect(page.getByTestId('wcct-doctor-summary')).toContainText('Everything looks good');
        await expect(page.locator('#wcct-doctor-summary-title')).toBeFocused();
    });

    test('Check again re-runs one check', async ({ page, context }) => {
        await setCookies(context, { ct_e2e_doctor: 'Pixel Plugin A', ct_e2e_consent: 'on' });
        await openApp(page, '#/doctor');

        const duplicate = finding(page, 'Pixel Plugin A is also active');

        await expect(duplicate).toBeVisible();
        await setCookies(context, { ct_e2e_doctor: 'none' });

        const request = page.waitForRequest(req => req.method() === 'POST' && /doctor/.test(req.url()));

        await duplicate.getByRole('button', { name: 'Check again' }).click();
        expect((await request).postDataJSON()).toEqual({ checks: ['e2e-duplicate'] });
        await expect(page.getByText('Checked again. It’s fixed.')).toBeVisible();
        await expect(duplicate).toHaveCount(0);
        await page.getByTestId('wcct-doctor-working').locator('summary').click();
        await expect(page.getByTestId('wcct-doctor-working')).toContainText('No e2e pixel plugin is active');
    });

    test('an action that opens another plugin asks first', async ({ page, context }) => {
        await setCookies(context, { ct_e2e_doctor: 'none', ct_e2e_consent: 'on' });
        await openApp(page, '#/doctor');

        const url = page.url();

        await finding(page, 'An e2e tag manager is active').getByRole('button', { name: 'Open its settings' }).click();

        const dialog = page.getByRole('alertdialog');

        await expect(dialog).toContainText('This opens another plugin. Nothing changes until you save there.');
        await dialog.getByRole('button', { name: 'Cancel' }).click();
        await expect(dialog).toHaveCount(0);
        expect(page.url()).toBe(url);

        await finding(page, 'An e2e tag manager is active').getByRole('button', { name: 'Open its settings' }).click();
        await page.getByRole('alertdialog').getByRole('button', { name: 'Continue' }).click();
        await expect(page).toHaveURL(/\/wp-admin\/plugins\.php$/);
    });

    test('activating a plugin re-checks quietly and the dashboard shows "Doctor found something new"', async ({ page, context }) => {
        await setCookies(context, { ct_e2e_doctor: 'Pixel Plugin A', ct_e2e_consent: 'on' });
        await openApp(page, '#/doctor');
        await expect(finding(page, 'Pixel Plugin A is also active')).toBeVisible();

        await openApp(page, '#/dashboard');
        await expect(page.getByTestId('wcct-doctor-card')).toContainText('2 things worth a look');
        await expect(page.getByTestId('wcct-doctor-new')).toHaveCount(0);

        // A new plugin shows up, then any plugin is activated from the Plugins screen.
        await setCookies(context, { ct_e2e_doctor: 'Pixel Manager' });
        await page.goto('/wp-admin/plugins.php');

        try {
            await page.locator('#activate-hello-dolly').click();
            await expect(page.locator('#message')).toContainText('activated');

            await openApp(page, '#/dashboard');
            await expect(page.getByTestId('wcct-doctor-new')).toHaveText('Doctor found something new');
            await expect(page.getByTestId('wcct-doctor-card')).toContainText('Pixel Manager is also active');

            // No popup anywhere: the badge waits on the dashboard. Opening the Doctor clears it.
            const seen = page.waitForResponse(response => /doctor(\/|%2F)seen/.test(response.url()));

            await openApp(page, '#/doctor');
            await expect(finding(page, 'Pixel Manager is also active')).toContainText('New');
            expect((await seen).ok()).toBe(true);
            await openApp(page, '#/dashboard');
            await expect(page.getByTestId('wcct-doctor-card')).toContainText('See the checklist');
            await expect(page.getByTestId('wcct-doctor-new')).toHaveCount(0);
        } finally {
            await page.goto('/wp-admin/plugins.php');

            const deactivate = page.locator('#deactivate-hello-dolly');

            if (await deactivate.count()) {
                await deactivate.click();
            }
        }
    });

    test('the dashboard card links to the Doctor before the first run', async ({ page }) => {
        await page.goto(`${APP_PATH}#/dashboard`);

        const card = page.getByTestId('wcct-doctor-card');

        await expect(card).toContainText('Want us to check your store');
        await expect(card.getByText('Check my store')).toHaveAttribute('href', '#/doctor');
        await expect(page.getByLabel('Last 24 hours at a glance')).toContainText('Not yet');
    });
});
