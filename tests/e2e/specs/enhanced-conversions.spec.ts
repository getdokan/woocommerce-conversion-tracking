import { createHash } from 'crypto';
import { Page } from '@playwright/test';
import { test, expect } from '../utils/fixtures';
import { CapturedRequest, NetworkTracker } from '../utils/network';
import { GUEST, addProductToCart, fillBlockCheckout, placeGuestOrder } from '../utils/checkout';
import { isProActive, loginAsAdmin, openApp } from '../utils/admin';
import { BASE_URL } from '../utils/site';
import { TEST_IDS } from '../utils/platforms';

// Google Ads enhanced conversions (#97, Pro): the conversion on the verified thank-you page carries the hashed email and phone.
const sha256 = (value: string) => createHash('sha256').update(value).digest('hex');

// gtag.js's em parameter as the stub (utils/stubs/gtag.js) sends it; the store is in the US, so the phone gets +1.
const EXPECTED_EM = `tv.1~em.${sha256(GUEST.email)}~pn.${sha256(`+1${GUEST.phone}`)}`;

let proActive = false;

test.beforeAll(async ({ browser }) => {
    const page = await browser.newPage({ baseURL: BASE_URL });

    await loginAsAdmin(page);
    await openApp(page);
    proActive = await isProActive(page);
    await page.close();
});

test.beforeEach(() => {
    test.skip(!proActive, 'Enhanced conversions are Pro; no Pro 3.0 is mapped into this store');
    test.skip(!TEST_IDS.ads?.conversionId, 'Google Ads needs test IDs in config/test-ids.json');
});

// Enhanced conversions on for this browser (mu-plugins/ct-e2e-helpers.php), plus the given cookies.
const setCookies = async (page: Page, pairs: string[]) => {
    await page.context().addCookies(
        ['ct_e2e_enhanced_conversions=1', ...pairs].map(pair => {
            const [name, value] = pair.split('=');

            return { name, value, url: BASE_URL };
        })
    );
};

// "Respect cookie choices" on with an opt-in banner (mu-plugins/ct-e2e-consent.php); the shopper accepted or refused marketing.
const consent = (marketing: 'allow' | 'deny') => ['ct_e2e_consent=optin', `wp_consent_marketing=${marketing}`, 'wp_consent_statistics=allow'];

const conversionOf = async (tracker: NetworkTracker, orderId: number): Promise<CapturedRequest> => {
    const hits = async () => (await tracker.purchaseHits('ads')).filter(r => r.params.some(p => p.get('oid') === String(orderId)));

    await expect.poll(async () => (await hits()).length, { message: `Ads conversion for order ${orderId}`, timeout: 15_000 }).toBe(1);

    return (await hits())[0];
};

const emOf = (hit: CapturedRequest) => hit.params.map(p => p.get('em')).find(value => value !== null) ?? null;

// Order of the user_data and conversion calls in the page's dataLayer, -1 when missing.
const callOrder = (page: Page) =>
    page.evaluate(() => {
        const layer = Array.from(((window as unknown as { dataLayer?: IArguments[] }).dataLayer || []) as ArrayLike<IArguments>);
        const index = (match: (args: IArguments) => boolean) => layer.findIndex(args => !!args && match(args));

        return {
            userData: index(args => args[0] === 'set' && args[1] === 'user_data'),
            conversion: index(args => args[0] === 'event' && args[1] === 'conversion'),
        };
    });

// Text of the page's tracking scripts.
const trackingScripts = (page: Page) =>
    page.evaluate(() =>
        Array.from(document.querySelectorAll('script'))
            .map(script => script.textContent || '')
            .filter(text => text.includes('wcct'))
            .join('\n')
    );

test('with marketing consent the verified thank-you page sets hashed user_data before the conversion', async ({ page, tracker }) => {
    await setCookies(page, consent('allow'));

    const order = await placeGuestOrder(page, 'block');
    const hit = await conversionOf(tracker, order.id);

    expect(emOf(hit), 'hashed email and phone on the conversion hit').toBe(EXPECTED_EM);

    const adsConfig = await page.evaluate(
        id =>
            Array.from(((window as unknown as { dataLayer?: IArguments[] }).dataLayer || []) as ArrayLike<IArguments>)
                .filter(args => !!args && args[0] === 'config' && args[1] === id)
                .map(args => (args[2] || {}) as Record<string, unknown>),
        TEST_IDS.ads!.conversionId
    );

    expect(adsConfig.map(config => config.allow_enhanced_conversions), 'the AW- config allows enhanced conversions').toEqual([true]);

    const calls = await callOrder(page);

    expect(calls.userData).toBeGreaterThan(-1);
    expect(calls.userData).toBeLessThan(calls.conversion);

    const scripts = await trackingScripts(page);

    // Meta's advanced matching hashes the same email, so look for Google's E.164 phone hash.
    expect(scripts).toContain(sha256(`+1${GUEST.phone}`));
    expect(scripts).not.toContain(GUEST.email);
    expect(scripts).not.toContain(GUEST.phone);
});

test('with marketing consent refused the conversion goes without user_data', async ({ page, tracker }) => {
    await setCookies(page, consent('deny'));

    const order = await placeGuestOrder(page, 'block');
    const hit = await conversionOf(tracker, order.id);

    expect(emOf(hit)).toBeNull();
    expect((await callOrder(page)).userData).toBe(-1);
});

test('with cookie choices off user_data is set without a consent check, as Meta matching', async ({ page, tracker }) => {
    await setCookies(page, []);

    const order = await placeGuestOrder(page, 'block');
    const hit = await conversionOf(tracker, order.id);

    expect(emOf(hit)).toBe(EXPECTED_EM);
    expect(await trackingScripts(page)).not.toContain('wcctConsent.allows("marketing")) { (window.wcctGtag');
});

test('a funnel thank-you page, without the order key check, sends the conversion without user_data', async ({ page, tracker, jsErrors }) => {
    jsErrors.allow(/ERR_ABORTED|net::ERR_FAILED/);
    await setCookies(page, consent('allow'));

    // The order received page never loads, so the Purchase is still due.
    await addProductToCart(page);
    await page.route(/\/order-received\//, route => route.abort('aborted'));

    const failed = page.waitForEvent('requestfailed', { predicate: request => /\/order-received\/\d+\//.test(request.url()), timeout: 30_000 });

    await fillBlockCheckout(page);

    const id = Number(new URL((await failed).url()).pathname.match(/\/order-received\/(\d+)/)![1]);

    await page.unroute(/\/order-received\//);
    await page.goto(`/?ct_e2e_thankyou=${id}`);
    await page.waitForLoadState('networkidle');

    const hit = await conversionOf(tracker, id);

    expect(emOf(hit)).toBeNull();
    expect((await callOrder(page)).userData).toBe(-1);
    expect(await trackingScripts(page)).not.toContain(sha256(`+1${GUEST.phone}`));
});
