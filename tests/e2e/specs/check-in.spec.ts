import { APIRequestContext, Page, Request } from '@playwright/test';
import { test, expect } from '../utils/fixtures';
import { placeGuestOrder } from '../utils/checkout';
import { enabledTags, Tag } from '../utils/platforms';
import { BASE_URL } from '../utils/site';
import { isProActive, loginAsAdmin, openApp } from '../utils/admin';

// Internal platform ID per tag.
const PLATFORM: Record<Tag, string> = { meta: 'facebook', ga4: 'ga4', ads: 'adwords', x: 'twitter' };

let proActive = false;

// With Pro 3.0 active, setup also saves a Microsoft UET tag, which checks in too.
test.beforeAll(async ({ browser }) => {
    const page = await browser.newPage({ baseURL: BASE_URL });

    await loginAsAdmin(page);
    await openApp(page);
    proActive = await isProActive(page);
    await page.close();
});

// Internal IDs of every platform that sends a Purchase on the e2e store.
const purchasePlatforms = (): string[] => [...enabledTags().map(tag => PLATFORM[tag]), ...(proActive ? ['bing_ads'] : [])].sort();

// Platforms whose Purchase waits for marketing consent.
const consentGated = (): string[] => [...enabledTags().filter(tag => tag === 'meta' || tag === 'x').map(tag => PLATFORM[tag]), ...(proActive ? ['bing_ads'] : [])];

interface LogRow {
    platform: string;
    source: string;
    library_loaded: string;
    consent: string;
    reason: string;
    event_id: string;
    sent_at: string | null;
    thankyou_seen_at: string | null;
}

interface OrderLog {
    rows: LogRow[];
    flags: Record<string, boolean>;
    thankyou_seen: number;
}

// Rows and sent flags of an order, from the test-only route in mu-plugins/ct-e2e-helpers.php.
const orderLog = async (request: APIRequestContext, orderId: number): Promise<OrderLog> => {
    const response = await request.get(`/?rest_route=/ct-e2e/v1/order-log/${orderId}`);

    expect(response.ok()).toBe(true);

    return response.json();
};

test('a guest order checks in once per platform within seconds', async ({ page, request }) => {
    const checkIns: string[] = [];

    page.on('request', (req: Request) => {
        if (req.method() === 'POST' && req.url().includes('/wcct/v1/events')) {
            checkIns.push(req.url());
        }
    });

    const order = await placeGuestOrder(page, 'block');
    const expected = purchasePlatforms();

    await expect
        .poll(async () => (await orderLog(request, order.id)).rows.filter(row => row.library_loaded === '1').map(row => row.platform).sort(), {
            message: `one handed-over row per platform for order ${order.id}`,
            timeout: 10_000,
        })
        .toEqual(expected);

    const log = await orderLog(request, order.id);

    expect(log.rows).toHaveLength(expected.length);
    expect(log.thankyou_seen).toBeGreaterThan(0);

    for (const row of log.rows) {
        expect(row.source).toBe('browser');
        expect(row.event_id).toBe(`wcct_${order.id}_purchase`);
        expect(row.consent).toBe('off');
        expect(row.sent_at).not.toBeNull();
        expect(row.thankyou_seen_at).not.toBeNull();
        expect(log.flags[row.platform], `${row.platform} sent flag`).toBe(true);
    }

    // The route is neutral and the old admin-ajax confirmations are gone.
    expect(checkIns.length).toBeGreaterThanOrEqual(expected.length);
    expect(checkIns.every(url => !/track|pixel|conversion|purchase|facebook|google/i.test(new URL(url).pathname + new URL(url).search))).toBe(true);
});

test('a blocked platform script still checks in, as not handed over', async ({ page, request, jsErrors }) => {
    test.skip(!enabledTags().includes('meta'), 'Needs the Meta tag.');
    jsErrors.allow(/ERR_BLOCKED_BY_CLIENT.*facebook\.(net|com)/);

    // Like an ad blocker: fbevents.js never loads, and neither do Meta's request images (Pro's wcfbq confirms on those).
    await page.route(/connect\.facebook\.net|facebook\.com\/tr/, route => route.abort('blockedbyclient'));

    const order = await placeGuestOrder(page, 'block');

    await expect
        .poll(async () => (await orderLog(request, order.id)).rows.find(row => row.platform === 'facebook')?.library_loaded ?? null, {
            message: `Meta check-in for order ${order.id}`,
            timeout: 20_000,
        })
        .toBe('0');

    const log = await orderLog(request, order.id);
    const meta = log.rows.find(row => row.platform === 'facebook')!;

    expect(['timeout', 'hidden']).toContain(meta.reason);
    expect(meta.sent_at).toBeNull();
    expect(log.flags.facebook).toBe(false);

    // The other platforms were handed over as usual.
    for (const platform of purchasePlatforms().filter(platform => platform !== 'facebook')) {
        expect(log.rows.find(row => row.platform === platform)?.library_loaded, platform).toBe('1');
    }
});

test('a host that blocks guest REST still gets the check-in through admin-ajax', async ({ page, request, jsErrors }) => {
    jsErrors.allow(/status of 403/);

    const fallbacks: string[] = [];

    // Like a security plugin that answers 403 to guests on wp-json (mu-plugins/ct-e2e-helpers.php).
    await page.context().addCookies([{ name: 'ct_e2e_block_rest', value: '1', url: BASE_URL }]);
    page.on('request', (req: Request) => {
        if (req.url().includes('admin-ajax.php') && (req.postData() ?? '').startsWith('action=wcct_events&')) {
            fallbacks.push(req.postData() ?? '');
        }
    });

    const order = await placeGuestOrder(page, 'block');
    const expected = purchasePlatforms();

    await expect
        .poll(async () => (await orderLog(request, order.id)).rows.filter(row => row.library_loaded === '1').map(row => row.platform).sort(), {
            message: `rows written through admin-ajax for order ${order.id}`,
            timeout: 10_000,
        })
        .toEqual(expected);

    const log = await orderLog(request, order.id);

    for (const platform of expected) {
        expect(log.flags[platform], `${platform} sent flag`).toBe(true);
    }

    expect(fallbacks.length).toBeGreaterThanOrEqual(expected.length);
});

// "Respect cookie choices" on, opt-in, marketing and statistics denied (mu-plugins/ct-e2e-consent.php); records every check-in body.
const denyConsent = async (page: Page, extra: string[] = []): Promise<string[]> => {
    const checkIns: string[] = [];

    await page.context().addCookies(
        ['ct_e2e_consent=optin', 'wp_consent_marketing=deny', 'wp_consent_statistics=deny', ...extra].map(pair => {
            const [name, value] = pair.split('=');

            return { name, value, url: BASE_URL };
        })
    );
    page.on('request', (req: Request) => {
        if (req.method() === 'POST' && (req.url().includes('/wcct/v1/events') || req.url().includes('admin-ajax.php'))) {
            checkIns.push(req.postData() ?? '');
        }
    });

    return checkIns;
};

// Platforms that wait for consent check in "not handed over" after the timeout, with consent=denied and no flag.
const expectDeniedMisses = async (request: APIRequestContext, orderId: number, platforms: string[], checkIns: string[]): Promise<OrderLog> => {
    await expect
        .poll(async () => (await orderLog(request, orderId)).rows.filter(row => platforms.includes(row.platform)).length, {
            message: `timeout check-ins for order ${orderId}`,
            timeout: 30_000,
        })
        .toBe(platforms.length);

    const log = await orderLog(request, orderId);

    for (const platform of platforms) {
        const row = log.rows.find(r => r.platform === platform)!;

        expect([row.library_loaded, row.consent, row.reason, row.sent_at], platform).toEqual(['0', 'denied', 'timeout', null]);
        expect(log.flags[platform], `${platform} sent flag`).toBe(false);
        expect(checkIns.some(body => body.includes(`&platform=${platform}&loaded=1`)), `${platform} hand-over before consent`).toBe(false);
    }

    return log;
};

test('with cookie choices respected and consent denied, nothing is handed over before consent', async ({ page, request }) => {
    const checkIns = await denyConsent(page);
    const order = await placeGuestOrder(page, 'block');

    // The consent runtime is on the page, backed by the stub consent API, and says marketing is denied.
    expect(await page.evaluate(() => {
        const w = window as unknown as { wcctConsentConfig?: { api: boolean }; wcctConsent?: { allows: (c: string) => boolean } };

        return [!!w.wcctConsentConfig?.api, w.wcctConsent?.allows('marketing')];
    })).toEqual([true, false]);

    const log = await expectDeniedMisses(request, order.id, consentGated(), checkIns);

    // Google consent mode still sends cookieless pings, so Google hands over and reports the denied state.
    for (const tag of enabledTags().filter(tag => tag === 'ga4' || tag === 'ads')) {
        const row = log.rows.find(r => r.platform === PLATFORM[tag]);

        expect([row?.library_loaded, row?.consent], tag).toEqual(['1', 'denied']);
    }
});

test('with consent denied, the held Custom thank-you script still checks in as not handed over', async ({ page, request }) => {
    const checkIns = await denyConsent(page, ['ct_e2e_custom_scripts=1']);
    const order = await placeGuestOrder(page, 'block');

    await expectDeniedMisses(request, order.id, ['custom'], checkIns);
});

test('the setup wizard test link sends one seen ping', async ({ page, request }) => {
    const started = await (await request.post('/?rest_route=/ct-e2e/v1/verify')).json();
    const pings: string[] = [];

    page.on('request', (req: Request) => {
        if (req.method() === 'POST' && req.url().includes('/wcct/v1/events')) {
            pings.push(req.postData() ?? '');
        }
    });

    // Pages without the test link send nothing.
    await page.goto('/');
    await page.waitForTimeout(1_000);
    expect(pings).toHaveLength(0);

    await page.goto(started.url);

    await expect
        .poll(async () => (await (await request.get('/?rest_route=/ct-e2e/v1/verify')).json()).seen_at, { timeout: 15_000 })
        .toBeGreaterThan(0);

    const state = await (await request.get('/?rest_route=/ct-e2e/v1/verify')).json();

    for (const tag of enabledTags()) {
        expect(state.platforms[PLATFORM[tag]], tag).toBe(true);
    }

    // Throttled: a reload within ten seconds sends nothing more.
    await page.reload();
    await page.waitForTimeout(2_000);
    expect(pings.filter(body => body.startsWith('type=seen'))).toHaveLength(1);
});
