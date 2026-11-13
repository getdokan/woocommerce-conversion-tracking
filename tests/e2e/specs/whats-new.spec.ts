import { readFile } from 'node:fs/promises';
import { Page } from '@playwright/test';
import { test, expect } from '../utils/fixtures';
import { APP_PATH, loginAsAdmin, openApp, wcctAdminData } from '../utils/admin';

// What's new (#124): a store upgraded from 2.x by the real migration, seeded through ct-e2e/v1/state (mu-plugins/ct-e2e-helpers.php).

type SiteState = { options: Record<string, unknown>; active: string[] };

// 2.x settings: the Ads label pasted into the Ads ID, X Purchase without an event ID, a Custom script filling the customer's email, Meta switched off.
const NEEDS_ATTENTION = {
    facebook: { enabled: false, 0: { pixel_id: '123456789012345', events: { Purchase: 'on' } } },
    adwords: { enabled: true, 0: { account_id: 'AW-123456789/AbC-dEf_12', events: { Purchase: 'on', 'Purchase-label': '' } } },
    twitter: { enabled: true, 0: { events: { Purchase: 'on', universal_tag_id: 'o8x2k' } } },
    custom: { enabled: true, checkout: "<script>ct('{order_number}', '{customer_email}');</script>", registration: '' },
};

// 2.x settings that carry over as they were.
const CARRIED_OVER = {
    facebook: { enabled: true, 0: { pixel_id: '123456789012345', events: { Purchase: 'on', AddToCart: 'on' } } },
    ga4: { enabled: true, 0: { measurement_id: 'G-ABCDE12345', events: { purchase: 'on' } } },
};

// The 1.1.x feed login kept its password in plain text; the download must never contain it. Switched off, so Pro's migration adds no feed login flag.
const FEED = { types: ['grouped'], authenticate: { 'wcct-authenticate-enable': 'no', username: 'meta', password: 'hunter2' } };

const MIGRATION_OPTIONS = [
    'wcct_settings_v3',
    'wcct_pre3_snapshot',
    'wcct_migration_flags',
    'wcct_migration_done',
    'wcct_migration_lock',
    'wcct_migration_error',
    'wcct_paused_platforms',
    'wcct_onboarding_redirect',
    'wcct_whats_new',
    'wcct_pro_migration_done',
];

async function siteState(page: Page, body?: object): Promise<SiteState> {
    const nonce = (await (await page.request.get('/wp-admin/admin-ajax.php?action=rest-nonce')).text()).trim();
    const response = body
        ? await page.request.post('/wp-json/ct-e2e/v1/state', { headers: { 'X-WP-Nonce': nonce }, data: body })
        : await page.request.get('/wp-json/ct-e2e/v1/state', { headers: { 'X-WP-Nonce': nonce } });

    expect(response.ok(), await response.text()).toBe(true);

    return response.json();
}

// Turn the store into a 2.x store; the next request runs the migration, as the first one after the update does.
async function upgradeFrom2x(page: Page, v1: object, more: Record<string, unknown> = {}): Promise<void> {
    // No admin page open, so its background requests (heartbeat) can't run the migration halfway through the writes.
    await page.goto('about:blank');
    await siteState(page, {
        // Written in this order: the 3.0 settings go last, so a request running meanwhile (heartbeat) can't migrate the old 2.x settings.
        options: {
            wcct_settings: v1,
            wcct_install_type: 'upgrade',
            wcct_consent_mode: null,
            wcct_data_feed: FEED,
            ...Object.fromEntries(MIGRATION_OPTIONS.map(option => [option, null])),
            ...more,
            wcct_settings_v3: null,
        },
    });
}

const page$ = (page: Page) => page.getByTestId('wcct-whats-new');
const banner = (page: Page) => page.getByTestId('wcct-updated-banner');

async function openHelpMenu(page: Page) {
    await page.locator('#wcct-topbar-root').getByRole('button', { name: 'Help' }).click();
}

test.describe("What's new", () => {
    let saved: SiteState;

    test.beforeEach(async ({ page }) => {
        await loginAsAdmin(page);
        saved = await siteState(page);
    });

    test.afterEach(async ({ page }) => {
        await siteState(page, { options: saved.options });
    });

    test('an upgraded store opens it once, lists what needs attention and links to each field', async ({ page }) => {
        await upgradeFrom2x(page, NEEDS_ATTENTION);

        // Any request runs the migration, which queues the redirect for the next admin page.
        await expect.poll(async () => (await siteState(page)).options.wcct_onboarding_redirect).toBe('whats_new');

        // The first admin page after the update sends the merchant here instead of the wizard.
        await page.goto('/wp-admin/index.php');
        await expect(page).toHaveURL(/page=conversion-tracking#\/whats-new$/, { timeout: 30_000 });
        await expect(page.getByRole('heading', { level: 1 })).toHaveText('Your tracking is still running. Here’s what changed.');
        await expect(page).toHaveTitle(/^What.s new ‹ Conversion Tracking ‹ /);
        await expect(page.locator('#wcct-topbar-root nav a[aria-current="page"]')).toHaveText('Dashboard');
        await expect(page$(page)).toContainText('Updated to 3.0');

        const attention = page.getByTestId('wcct-whats-new-attention');

        await expect(attention).toContainText('2 settings couldn’t carry over as they were');
        await expect(page.getByTestId('wcct-whats-new-done')).toHaveCount(0);

        const google = page.getByTestId('wcct-whats-new-item-google_ads_id_split');
        const x = page.getByTestId('wcct-whats-new-item-twitter_event_ids');

        await expect(google).toContainText('We split your Google Ads ID into a conversion ID and a label. Check it looks right.');
        await expect(google.getByRole('link', { name: 'Check Google' })).toHaveAttribute('href', '#/platforms/google?field=ads_id');
        await expect(x).toContainText('X retired the old event codes. Purchases need a new event ID.');

        // The Custom script still fills {customer_email}, so there is no customer item.
        await expect(attention).not.toContainText('customer');

        // Meta was switched off in 2.x: still paused, and said so.
        await expect(page.getByTestId('wcct-whats-new-paused-facebook')).toContainText('Meta is paused.');
        await expect(page.getByTestId('wcct-whats-new-paused-facebook').getByRole('link', { name: 'Open Meta' })).toHaveAttribute('href', '#/platforms/facebook');

        await expect(page.getByTestId('wcct-whats-new-backup')).toContainText('version 2.1 uses your settings from before the update: anything you change in 3.0 is lost.');
        await expect(page.getByTestId('wcct-whats-new-fixed')).toContainText('Purchases are counted once.');
        await expect(page.getByTestId('wcct-whats-new-try-consent')).toContainText('Respect cookie choices.');

        // The previous settings download as a file, without the feed password.
        const download = page.waitForEvent('download');

        await page.getByRole('button', { name: 'Download your previous settings (JSON)' }).click();

        const file = await download;

        expect(file.suggestedFilename()).toMatch(/^conversion-tracking-settings-before-3\.0-\d{4}-\d{2}-\d{2}\.json$/);

        const text = await readFile((await file.path())!, 'utf8');
        const json = JSON.parse(text);

        expect(json.source).toBe('snapshot');
        expect(json.options.wcct_settings).toEqual(NEEDS_ATTENTION);
        expect(json.options.wcct_data_feed.authenticate).toEqual({ 'wcct-authenticate-enable': 'no', username: 'meta' });
        expect(text).not.toContain('hunter2');
        await expect(page.getByText(`Downloaded ${file.suggestedFilename()}.`)).toBeVisible();

        // The split was right: "Looks right" closes it; X has to be fixed, so it has no such button.
        await expect(x.getByRole('button', { name: 'Looks right' })).toHaveCount(0);
        await google.getByRole('button', { name: 'Looks right' }).click();
        await expect(page.getByText('Marked as checked.')).toBeVisible();
        await expect(google).toHaveCount(0);
        await expect(attention).toContainText('1 setting couldn’t carry over as it was');
        await expect(page.locator('#wcct-whats-new-attention-title')).toBeFocused();

        // Set up X opens X's page at the event ID.
        await x.getByRole('link', { name: 'Set up X' }).click();
        await expect(page).toHaveURL(/#\/platforms\/twitter\?field=purchase_event_id$/);
        await expect(page.locator('#wcct-app-root h1')).toHaveText('Platforms');

        // Only once: the next admin page stays where it is.
        await page.goto('/wp-admin/index.php');
        await expect(page).toHaveURL(/\/wp-admin\/index\.php$/);
    });

    test('the dashboard banner leads here until "Done, show me the dashboard"', async ({ page, jsErrors }) => {
        // The one-time redirect is covered above; here it's used up already.
        await upgradeFrom2x(page, NEEDS_ATTENTION, { wcct_onboarding_redirect: 'done' });
        await openApp(page, '#/dashboard');

        await expect(banner(page)).toContainText('Updated to 3.0. Your settings carried over.');
        await expect(banner(page)).toContainText('2 settings need a quick check.');

        await openHelpMenu(page);
        await expect(page.getByRole('menuitem', { name: /What.s new in 3\.0/ })).toHaveAttribute('href', '#/whats-new');
        await page.keyboard.press('Escape');

        await banner(page).getByRole('link', { name: /See what.s new/ }).click();
        await expect(page).toHaveURL(/#\/whats-new$/);
        await expect(page$(page)).toBeVisible();

        // A failed dismissal keeps the page and the button.
        const doneButton = page.getByRole('button', { name: 'Done, show me the dashboard' });

        jsErrors.allow(/status of 500/);
        await page.route(/whats-new%2Fdismiss|whats-new\/dismiss/, route =>
            route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ code: 'ct_e2e', message: 'Dismiss failed for the test.' }) })
        );
        await doneButton.click();
        await expect(page.getByText('Dismiss failed for the test.')).toBeVisible();
        await expect(page).toHaveURL(/#\/whats-new$/);
        await expect(doneButton).toBeEnabled();
        await page.unrouteAll();

        await doneButton.click();
        await expect(page).toHaveURL(/#\/dashboard$/);
        await expect(page.locator('#wcct-app-root h1')).toHaveText('Dashboard');
        await expect(banner(page)).toHaveCount(0);

        // Dismissed for good; the page itself stays reachable.
        await page.reload();
        await expect(page.locator('#wcct-app-root h1')).toHaveText('Dashboard');
        await expect(page.getByTestId('wcct-dashboard')).toBeVisible();
        await expect(banner(page)).toHaveCount(0);
        await openApp(page, '#/whats-new');
        await expect(page$(page)).toBeVisible();
    });

    test('a clean upgrade shows "Everything carried over"', async ({ page }) => {
        await upgradeFrom2x(page, CARRIED_OVER, { wcct_consent_mode: 'on', wcct_onboarding_redirect: 'done' });
        await openApp(page, '#/whats-new');

        await expect(page.getByTestId('wcct-whats-new-done')).toContainText('Everything carried over');
        await expect(page.getByTestId('wcct-whats-new-done')).toContainText('Nothing needs your attention.');
        await expect(page.getByTestId('wcct-whats-new-attention')).toHaveCount(0);
        await expect(page.getByTestId('wcct-whats-new-paused')).toHaveCount(0);
        // Cookie choices are already respected.
        await expect(page.getByTestId('wcct-whats-new-try-consent')).toHaveCount(0);
        await expect(page.getByTestId('wcct-whats-new-try-doctor').getByRole('link', { name: 'Start' })).toHaveAttribute('href', '#/doctor');
        await expect(page.getByTestId('wcct-whats-new-try-skip-roles')).toContainText('Skip your own visits. Your store tracked every visitor before, so nothing changed.');
        await expect(page.getByTestId('wcct-whats-new-try-skip-roles').getByRole('link', { name: 'Review' })).toHaveAttribute('href', '#/platforms/facebook?field=skip_roles');
    });

    test('Pro: the old-Pro banner while Pro is older than 3.0, the variations note with Pro 3.0', async ({ page }) => {
        await upgradeFrom2x(page, CARRIED_OVER, { wcct_onboarding_redirect: 'done' });
        await openApp(page, '#/whats-new');

        const data = await wcctAdminData(page);
        const oldPro = page.getByTestId('wcct-whats-new-old-pro');
        const note = page.getByTestId('wcct-whats-new-note-pro');

        await expect(page.getByTestId('wcct-whats-new-done')).toBeVisible();

        if (data.proVersion && !data.proActive) {
            await expect(oldPro).toContainText(`Conversion Tracking Pro ${data.proVersion} is installed but paused.`);
            await expect(oldPro).toContainText('Update Pro to 3.0 to get your Pro features back.');
            await expect(oldPro.getByRole('link', { name: 'Meta catalog feed' })).toHaveAttribute('href', '#/catalog');
            await expect(oldPro.getByRole('link', { name: 'Update Pro' })).toHaveAttribute('href', /plugins\.php\?plugin_status=upgrade$/);
            await expect(note).toHaveCount(0);

            return;
        }

        await expect(oldPro).toHaveCount(0);

        if (data.proActive) {
            await expect(note).toContainText('Pro 3.0 is active. Your catalog feed and role access carried over.');
            await expect(note).toContainText('The feed now lists each variation of a variable product, grouped under its parent');
            await expect(note.getByRole('link', { name: 'catalog feed' })).toHaveAttribute('href', '#/catalog');

            // Pro's own migration flags (#123), seeded: Pro describes them; the feed login one links to the Catalog page.
            await siteState(page, {
                options: {
                    wcct_migration_flags: { catalog_feed_login: { platform: 'catalog', fields: ['login'] } },
                    wcct_feed_login: null,
                },
            });
            await page.reload();

            const login = page.getByTestId('wcct-whats-new-item-catalog_feed_login');

            await expect(login).toContainText('Your catalog feed login is off.');
            await expect(login.getByRole('link', { name: 'Check the catalog' })).toHaveAttribute('href', '#/catalog');
        } else {
            await expect(note).toHaveCount(0);

            // A Pro flag without Pro 3.0 to describe it isn't shown.
            await siteState(page, { options: { wcct_migration_flags: { catalog_feed_login: { platform: 'catalog', fields: ['login'] } } } });
            await page.reload();
            await expect(page.getByTestId('wcct-whats-new-done')).toBeVisible();
        }
    });

    test('a store that started on 3.0 has no What\'s new', async ({ page }) => {
        await siteState(page, { options: { wcct_install_type: 'new', wcct_pre3_snapshot: null, wcct_onboarding_redirect: null } });

        await page.goto(`${APP_PATH}#/whats-new`);
        await expect(page).toHaveURL(/#\/dashboard$/, { timeout: 30_000 });
        await expect(page.locator('#wcct-app-root h1')).toHaveText('Dashboard');
        await expect(banner(page)).toHaveCount(0);

        await openHelpMenu(page);
        await expect(page.getByRole('menuitem', { name: 'Docs' })).toBeVisible();
        await expect(page.getByRole('menuitem', { name: /What.s new/ })).toHaveCount(0);
        await page.keyboard.press('Escape');

        const nonce = (await (await page.request.get('/wp-admin/admin-ajax.php?action=rest-nonce')).text()).trim();
        const response = await page.request.get('/wp-json/wcct/v1/whats-new', { headers: { 'X-WP-Nonce': nonce } });

        expect(response.status()).toBe(404);
        expect((await response.json()).code).toBe('wcct_rest_no_whats_new');
    });
});
