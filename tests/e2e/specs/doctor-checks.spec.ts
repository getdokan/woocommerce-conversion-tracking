import { BrowserContext, Page } from '@playwright/test';
import { test, expect } from '../utils/fixtures';
import { isProActive, loginAsAdmin, openApp } from '../utils/admin';
import { BASE_URL } from '../utils/site';

// Store states come from cookies read by mu-plugins/ct-e2e/doctor-states.php; nothing is written to the database.
const setCookies = async (context: BrowserContext, cookies: Record<string, string>): Promise<void> => {
    await context.addCookies(Object.entries(cookies).map(([name, value]) => ({ name, value, url: BASE_URL })));
};

const finding = (page: Page, title: string) =>
    page.getByTestId('wcct-finding').filter({ has: page.getByRole('heading', { name: title, exact: true }) });

const LITE_CHECKS = ['tracking-plugins', 'id-formats', 'consent-plugin', 'tag-manager', 'purchase-on', 'purchases-24h', 'consent-off'];

test.describe('Tracking Doctor checks', () => {
    test.beforeEach(async ({ page }) => {
        await loginAsAdmin(page);
        await page.goto('/wp-admin/?ct_e2e_doctor_reset=1');
    });

    test('each check shows its finding with what we found, why it matters and one action', async ({ page, context }) => {
        await setCookies(context, {
            ct_e2e_doctor_state: 'meta-plugin,pixelyoursite,gtm,bad-ids,purchase-off,repeat',
            ct_e2e_consent: 'on',
        });
        await openApp(page, '#/doctor');

        const total = (await isProActive(page)) ? 8 : 7;

        await expect(page.getByTestId('wcct-doctor-summary')).toContainText(`We checked ${total} things.`);

        // Other tracking plugins: one finding per shared platform; sure when we can read the plugin's setting.
        const meta = finding(page, 'Meta for WooCommerce (formerly Facebook for WooCommerce) also sends to Meta');

        await expect(meta).toContainText('Duplicate');
        await expect(meta).toContainText('Double counting');
        await expect(meta).toContainText('If both plugins send purchases to Meta, each sale is counted twice and your ad results double.');
        await expect(meta.getByRole('listitem')).toHaveText([
            'Pick one plugin to send to Meta.',
            'To keep this one, turn off Meta tracking in Meta for WooCommerce (formerly Facebook for WooCommerce), or deactivate it.',
            'To keep Meta for WooCommerce (formerly Facebook for WooCommerce), pause Meta in Platforms.',
            'Then check again.',
        ]);
        await expect(meta.getByRole('button', { name: 'Check again' })).toBeVisible();

        const pys = finding(page, 'PixelYourSite is also active and may send to Google');

        await expect(pys).toContainText('Not sure');
        await expect(pys).toContainText('Not sure it sends purchases');
        await expect(finding(page, 'PixelYourSite is also active and may send to Meta')).toBeVisible();

        // ID formats: a link to the field.
        const pixel = finding(page, 'Your Meta Pixel ID doesn\'t look right');

        await expect(pixel).toContainText('Meta ignores events sent with an ID it doesn\'t know, so they aren\'t counted. Expected: Numbers only, usually 15 or 16 digits.');
        await expect(pixel.getByRole('link', { name: 'Open Meta' })).toHaveAttribute('href', '#/platforms/facebook?field=pixel_id');

        const label = finding(page, 'Your Google Ads conversion label looks incomplete');

        await expect(label).toContainText('Google can\'t count purchases for your campaigns until the Ads conversion label is filled in.');
        await expect(label.getByRole('link', { name: 'Open Google' })).toHaveAttribute('href', '#/platforms/google?field=purchase_label');

        // Cookie choices on without a consent plugin; with no WP Consent API nothing holds the tags.
        const consent = finding(page, 'Cookie choices are on, but no consent plugin is connected');

        await expect(consent).toContainText('Nothing tells your tags what visitors chose, so they run as if everyone agreed.');
        await expect(consent.getByRole('link', { name: /See compatible plugins/ })).toHaveAttribute('href', 'https://wordpress.org/plugins/wp-consent-api/');

        // A Tag Manager plugin: "we can't see inside".
        const gtm = finding(page, 'A Google Tag Manager plugin is active');

        await expect(gtm).toContainText('Not sure');
        await expect(gtm).toContainText('Can\'t see inside');
        await expect(gtm).toContainText('GTM4WP');
        await expect(gtm).toContainText('If your container also has a purchase tag, Google counts each sale twice.');
        await expect(gtm.getByRole('link', { name: /How to check/ })).toHaveAttribute('target', '_blank');

        // Purchase off for a connected platform.
        const purchase = finding(page, 'Purchase is off for X');

        await expect(purchase).toContainText('X doesn\'t hear about your sales, so it can\'t count conversions or optimize your ads for purchases.');
        await expect(purchase.getByRole('link', { name: 'Open X' })).toHaveAttribute('href', '#/platforms/twitter?field=events');

        // The last 24 hours: a purchase sent twice.
        const twice = finding(page, '1 purchase was sent twice in the last 24 hours');

        await expect(twice).toContainText('The thank-you page sent the same order to Meta twice');
        await expect(twice.getByRole('link', { name: 'Open the Activity log' })).toHaveAttribute('href', '#/log');

        // The cookie-choices note is off while the switch is on.
        await expect(page.locator('[data-testid="wcct-finding"][data-check="consent-off"]')).toHaveCount(0);
    });

    test('a clean store passes the checks and lists them as Working', async ({ page, context }) => {
        await setCookies(context, {
            ct_e2e_doctor_checks: LITE_CHECKS.filter(check => check !== 'purchases-24h').join(','),
            // ct-e2e-consent.php: consent mode on with an opt-in WP Consent API.
            ct_e2e_consent: 'optin',
        });
        await openApp(page, '#/doctor');

        for (const check of LITE_CHECKS) {
            await expect(page.locator(`[data-testid="wcct-finding"][data-check="${check}"]`)).toHaveCount(0);
        }

        const working = page.getByTestId('wcct-doctor-working');

        await working.locator('summary').click();

        for (const line of [
            'No other tracking plugin sends to your platforms',
            'Your platform IDs have the right format',
            'Cookie choices reach your tags through your consent plugin',
            'No Google Tag Manager plugin is active',
            'Purchase is on for every connected platform',
            'Respect cookie choices is on',
        ]) {
            await expect(working).toContainText(line);
        }
    });
});
