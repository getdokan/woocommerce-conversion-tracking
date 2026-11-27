import { test as base, expect, Page } from '@playwright/test';
import { NetworkTracker } from './network';

export class JsErrors {
    readonly errors: string[] = [];
    private allowed: RegExp[] = [];

    // Lets a spec accept an error it expects.
    allow(pattern: RegExp): void {
        this.allowed.push(pattern);
    }

    watch(page: Page): void {
        page.on('pageerror', error => this.add(`pageerror on ${page.url()}: ${error.message}`));
        page.on('console', message => {
            if (message.type() === 'error') {
                const source = message.location().url;

                this.add(`console.error on ${page.url()}: ${message.text()}${source ? ` (${source})` : ''}`);
            }
        });
    }

    private add(error: string): void {
        if (!this.allowed.some(pattern => pattern.test(error))) {
            this.errors.push(error);
        }
    }
}

// Every spec gets both: the tracker keeps tests off the real ad platforms, and any JS error fails the test.
export const test = base.extend<{ tracker: NetworkTracker; jsErrors: JsErrors }>({
    tracker: [
        async ({ context, baseURL }, use, testInfo) => {
            const tracker = new NetworkTracker(baseURL!);

            await tracker.attach(context);
            await use(tracker);
            await testInfo.attach('platform-requests.json', {
                body: JSON.stringify(
                    (await tracker.platformRequests()).map(({ params, ...request }) => request),
                    null,
                    2
                ),
                contentType: 'application/json',
            });
        },
        { auto: true },
    ],
    jsErrors: [
        async ({ context }, use) => {
            const jsErrors = new JsErrors();

            context.pages().forEach(page => jsErrors.watch(page));
            context.on('page', page => jsErrors.watch(page));
            await use(jsErrors);
            expect(jsErrors.errors, 'JavaScript errors on the pages').toEqual([]);
        },
        { auto: true },
    ],
});

export { expect };
