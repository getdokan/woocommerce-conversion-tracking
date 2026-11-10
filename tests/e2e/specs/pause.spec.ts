import { Browser, Page } from '@playwright/test';
import { test, expect } from '../utils/fixtures';
import { NetworkTracker } from '../utils/network';
import { loginAsAdmin, openApp } from '../utils/admin';
import { PRODUCT_PATH } from '../utils/checkout';
import { Platform, TEST_IDS } from '../utils/platforms';

// Pause and resume go through the REST route the app uses; also the clean-up when a test fails half way.
const setPaused = async (page: Page, platform: string, paused: boolean) =>
    page.evaluate(
        ([id, value]) =>
            (window as unknown as { wp: { apiFetch: (options: object) => Promise<unknown> } }).wp.apiFetch({
                path: '/wcct/v1/settings/pause',
                method: 'POST',
                data: { platform: id, paused: value },
            }),
        [platform, paused] as const
    );

// A platform's entry in the list beside the open one.
const listItem = (page: Page, id: string) => page.locator(`nav[aria-labelledby="wcct-platform-list-label"] a[href="#/platforms/${id}"]`);

// A logged-out shopper behind the e2e page cache, with their own tracker.
const shopper = async (browser: Browser, baseURL: string) => {
    const context = await browser.newContext();
    const tracker = new NetworkTracker(baseURL);

    await tracker.attach(context);
    await context.addCookies([{ name: 'ct_e2e_page_cache', value: '1', url: baseURL }]);

    const page = await context.newPage();

    // Loads the product page and returns the cache header and the requests each platform made on it.
    const view = async (platforms: Platform[]) => {
        const before = await Promise.all(platforms.map(async p => (await tracker.platformRequests(p)).length));
        const response = await page.goto(PRODUCT_PATH);

        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(500);

        const after = await Promise.all(platforms.map(async p => (await tracker.platformRequests(p)).length));

        return {
            cache: response?.headers()['x-ct-cache'],
            requests: Object.fromEntries(platforms.map((p, i) => [p, after[i] - before[i]])) as Record<Platform, number>,
        };
    };

    return { context, page, view };
};

const CASES = [
    { id: 'facebook', name: 'Meta', target: 'Meta', platforms: ['meta'] as Platform[], others: ['google'] as Platform[], ids: !!TEST_IDS.meta?.pixelId },
    {
        id: 'google',
        name: 'Google',
        target: 'Google',
        platforms: ['google'] as Platform[],
        others: ['meta'] as Platform[],
        ids: !!TEST_IDS.ga4?.measurementId && !!TEST_IDS.ads?.conversionId,
    },
];

for (const platform of CASES) {
    test(`pausing ${platform.name} asks first, stops its tags at once behind a page cache, and Resume brings them back`, async ({ page, browser, baseURL }) => {
        test.skip(!platform.ids, `${platform.name} needs test IDs in config/test-ids.json`);

        const visitor = await shopper(browser, baseURL!);

        try {
            // A page cached while the platform is on.
            await visitor.page.goto('/?ct_e2e_page_cache_flush=1');

            const primed = await visitor.view([...platform.platforms, ...platform.others]);

            expect(primed.cache).toBe('MISS');
            expect(primed.requests[platform.platforms[0]], `${platform.name} requests before the pause`).toBeGreaterThan(0);
            expect((await visitor.view(platform.platforms)).cache, 'the page is cached').toBe('HIT');

            await loginAsAdmin(page);
            await openApp(page, `#/platforms/${platform.id}`);

            const card = page.getByTestId('wcct-platform-status');
            const pauseButton = card.getByRole('button', { name: 'Pause tracking' });
            const dialog = page.getByRole('alertdialog');

            await expect(card.getByTestId('wcct-platform-pill')).toHaveText(/Waiting for first event|Working/);

            // Keep tracking and Escape leave it on.
            await pauseButton.click();
            await expect(dialog).toContainText(`Pause tracking on ${platform.name}?`);
            await expect(dialog).toContainText(`Nothing is sent to ${platform.id === 'google' ? 'Analytics or Ads' : platform.target} until you resume.`);
            await dialog.getByRole('button', { name: 'Keep tracking' }).click();
            await expect(dialog).toBeHidden();
            await expect(pauseButton).toBeFocused();
            await pauseButton.click();
            await page.keyboard.press('Escape');
            await expect(dialog).toBeHidden();
            await expect(card.getByTestId('wcct-platform-pill')).toHaveText(/Waiting for first event|Working/);

            await pauseButton.click();
            await dialog.getByRole('button', { name: 'Pause tracking' }).click();
            await expect(dialog).toBeHidden();
            await expect(card).toContainText('Paused');
            await expect(card).toContainText(`Tracking paused. Nothing is sent to ${platform.target} until you resume.`);
            await expect(card.getByRole('button', { name: 'Resume tracking' })).toBeFocused();
            await expect(page.getByTestId(`wcct-pause-${platform.id}-status`)).toHaveText(`Tracking paused on ${platform.name}. Your settings are kept.`);
            await expect(page.locator('[data-sonner-toast]').filter({ hasText: `Tracking paused on ${platform.name}. Your settings are kept.` })).toBeVisible();
            // The list beside the page says Paused too.
            await expect(listItem(page, platform.id)).toContainText('Paused');

            // It stays paused, and the dashboard says so.
            await openApp(page, '#/dashboard');
            await expect(page.getByTestId(`wcct-platform-card-${platform.id}`)).toContainText('Paused');

            // The pause purged the cache: the shopper gets a fresh page without the platform's tags; the others keep sending.
            const paused = await visitor.view([...platform.platforms, ...platform.others]);

            expect(paused.cache, 'the pause purged the page cache').toBe('MISS');
            expect(paused.requests[platform.platforms[0]], `${platform.name} requests while paused`).toBe(0);
            expect(paused.requests[platform.others[0]], 'the other platform keeps sending').toBeGreaterThan(0);
            expect(await visitor.page.content()).not.toContain(platform.id === 'google' ? TEST_IDS.ga4!.measurementId : TEST_IDS.meta!.pixelId);

            await openApp(page, `#/platforms/${platform.id}`);
            await card.getByRole('button', { name: 'Resume tracking' }).click();
            await expect(card.getByTestId('wcct-platform-pill')).toHaveText(/Waiting for first event|Working/);
            await expect(pauseButton).toBeFocused();
            await expect(page.getByTestId(`wcct-pause-${platform.id}-status`)).toHaveText(`Tracking resumed on ${platform.name}.`);
            await expect(listItem(page, platform.id)).toContainText('On');

            const resumed = await visitor.view(platform.platforms);

            expect(resumed.cache, 'the resume purged the page cache').toBe('MISS');
            expect(resumed.requests[platform.platforms[0]], `${platform.name} requests after Resume`).toBeGreaterThan(0);
        } finally {
            if (page.url().includes('page=conversion-tracking')) {
                await setPaused(page, platform.id, false).catch(() => undefined);
            }

            await visitor.page.goto('/?ct_e2e_page_cache_flush=1').catch(() => undefined);
            await visitor.context.close();
        }
    });
}

test('the Custom script page has no Pause tracking, as in the prototype', async ({ page }) => {
    await loginAsAdmin(page);
    await openApp(page, '#/platforms/custom');
    await expect(page.getByTestId('wcct-custom-script')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Pause tracking' })).toHaveCount(0);
});
