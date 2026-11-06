import { BrowserContext, Request, Route, expect } from '@playwright/test';
import { readFileSync } from 'fs';
import { join } from 'path';
import { Platform, Tag, TEST_IDS, platformOf } from './platforms';

export interface CapturedRequest {
    platform: Platform;
    url: string;
    method: string;
    resourceType: string;
    postData: string;
    // Referer header as sent, filled in once Playwright reports all headers.
    referer: string;
    // URL of the page that made the request, at the time it was made.
    pageUrl: string;
    // One set per hit: the query string merged with each line of the body (GA4 batches events by line).
    params: URLSearchParams[];
}

// Platform libraries served from utils/stubs, so CI never loads them from the network.
const STUBS: { match: RegExp; file: string }[] = [
    { match: /^https:\/\/connect\.facebook\.net\/[^/]+\/fbevents\.js/, file: 'fbevents.js' },
    { match: /^https:\/\/www\.googletagmanager\.com\/gtag\/js/, file: 'gtag.js' },
    { match: /^https:\/\/static\.ads-twitter\.com\/uwt\.js/, file: 'uwt.js' },
    { match: /^https?:\/\/bat\.bing\.com\/bat\.js/, file: 'bat.js' },
];

const PIXEL = Buffer.from('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', 'base64');

const PURCHASE: Record<Tag, (request: CapturedRequest, ids: Record<string, string>) => boolean> = {
    meta: (request, ids) =>
        request.platform === 'meta' &&
        /\/tr\/?$/.test(new URL(request.url).pathname) &&
        request.params.some(p => p.get('ev') === 'Purchase' && p.get('id') === ids.pixelId),
    ga4: (request, ids) =>
        request.platform === 'google' &&
        new URL(request.url).pathname.endsWith('/g/collect') &&
        request.params.some(p => p.get('en') === 'purchase' && p.get('tid') === ids.measurementId),
    ads: (request, ids) =>
        request.platform === 'google' &&
        new URL(request.url).pathname === `/pagead/conversion/${ids.conversionId.replace(/^AW-/, '')}/` &&
        request.params.some(p => p.get('label') === ids.purchaseLabel),
    x: (request, ids) =>
        request.platform === 'x' &&
        new URL(request.url).pathname.endsWith('/i/adsct') &&
        request.params.some(p => p.get('txn_id') === ids.purchaseEventId),
};

// X names an event by its event ID, Google Ads by its conversion label.
const EVENT: Record<Tag, (request: CapturedRequest, ids: Record<string, string>, event: string) => boolean> = {
    meta: (request, ids, event) =>
        request.platform === 'meta' &&
        /\/tr\/?$/.test(new URL(request.url).pathname) &&
        request.params.some(p => p.get('ev') === event && p.get('id') === ids.pixelId),
    ga4: (request, ids, event) =>
        request.platform === 'google' &&
        new URL(request.url).pathname.endsWith('/g/collect') &&
        request.params.some(p => p.get('en') === event && p.get('tid') === ids.measurementId),
    ads: (request, ids, event) =>
        request.platform === 'google' &&
        new URL(request.url).pathname === `/pagead/conversion/${ids.conversionId.replace(/^AW-/, '')}/` &&
        request.params.some(p => p.get('label') === event),
    x: (request, _ids, event) =>
        request.platform === 'x' && new URL(request.url).pathname.endsWith('/i/adsct') && request.params.some(p => p.get('txn_id') === event),
};

const paramsOf = (request: Request): URLSearchParams[] => {
    const query = new URL(request.url()).searchParams;
    const lines = (request.postData() ?? '').split(/\r?\n/).filter(Boolean);

    if (!lines.length) {
        return [query];
    }

    return lines.map(line => new URLSearchParams([...query, ...new URLSearchParams(line)]));
};

const emptyBody = (route: Route) => {
    switch (route.request().resourceType()) {
        case 'script':
            return route.fulfill({ contentType: 'application/javascript', body: '' });
        case 'stylesheet':
            return route.fulfill({ contentType: 'text/css', body: '' });
        case 'document':
            return route.fulfill({ contentType: 'text/html', body: '' });
        default:
            return route.fulfill({ contentType: 'image/gif', body: PIXEL });
    }
};

// Captures every request to a platform host and keeps the browser off the network outside the test site.
export class NetworkTracker {
    readonly requests: CapturedRequest[] = [];
    // Other outside URLs the pages asked for, answered with an empty body.
    readonly external: string[] = [];
    private pending: Promise<unknown>[] = [];

    constructor(
        private readonly baseURL: string,
        // CT_E2E_LIVE_TAGS=1 loads the real platform libraries; their hits are still answered locally.
        private readonly liveTags = process.env.CT_E2E_LIVE_TAGS === '1'
    ) {}

    async attach(context: BrowserContext): Promise<void> {
        const origin = new URL(this.baseURL).origin;

        context.on('request', request => this.capture(request));
        await context.route(
            url => /^https?:$/.test(url.protocol) && url.origin !== origin,
            route => this.handle(route)
        );
    }

    // Platform requests seen so far, optionally for one platform.
    async platformRequests(platform?: Platform): Promise<CapturedRequest[]> {
        await Promise.all(this.pending);

        return this.requests.filter(r => !platform || r.platform === platform);
    }

    // Purchase hits for a tag's test ID.
    async purchaseHits(tag: Tag): Promise<CapturedRequest[]> {
        const ids = TEST_IDS[tag] ?? {};

        return (await this.platformRequests()).filter(r => PURCHASE[tag](r, ids));
    }

    // Hits of one event for a tag's test ID, by the platform's own event name (Meta "AddToCart", GA4 "add_to_cart").
    async eventHits(tag: Tag, event: string): Promise<CapturedRequest[]> {
        const ids = TEST_IDS[tag] ?? {};

        return (await this.platformRequests()).filter(r => EVENT[tag](r, ids, event));
    }

    // Fails unless the tag sent a Purchase hit for its test ID; returns the hits.
    async expectPurchase(tag: Tag, timeout = 15_000): Promise<CapturedRequest[]> {
        await expect
            .poll(async () => (await this.purchaseHits(tag)).length, {
                message: `${tag} Purchase hit for ${JSON.stringify(TEST_IDS[tag])}`,
                timeout,
            })
            .toBeGreaterThan(0);

        return this.purchaseHits(tag);
    }

    private capture(request: Request): void {
        const platform = platformOf(request.url());

        if (!platform) {
            return;
        }

        let pageUrl = '';

        try {
            pageUrl = request.frame().url();
        } catch {
            // Service worker requests have no frame.
        }

        const captured: CapturedRequest = {
            platform,
            url: request.url(),
            method: request.method(),
            resourceType: request.resourceType(),
            postData: request.postData() ?? '',
            referer: '',
            pageUrl,
            params: paramsOf(request),
        };

        this.requests.push(captured);
        this.pending.push(
            request
                .allHeaders()
                .then(headers => (captured.referer = headers.referer ?? ''))
                .catch(() => undefined)
        );
    }

    private async handle(route: Route): Promise<void> {
        const request = route.request();
        const url = request.url();
        const platform = platformOf(url);

        if (!platform) {
            this.external.push(url);

            return emptyBody(route);
        }

        if (this.liveTags && request.resourceType() === 'script') {
            return route.continue();
        }

        const stub = STUBS.find(s => s.match.test(url));

        if (stub) {
            return route.fulfill({
                contentType: 'application/javascript',
                body: readFileSync(join(__dirname, 'stubs', stub.file)),
            });
        }

        return emptyBody(route);
    }
}
