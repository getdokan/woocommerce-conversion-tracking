import { readFileSync } from 'fs';
import { join } from 'path';

export type Platform = 'meta' | 'google' | 'x' | 'microsoft';

// Purchase checks the specs can run, one per tag that has a test ID in config/test-ids.json.
export type Tag = 'meta' | 'ga4' | 'ads' | 'x';

// Hosts each platform's tags and hits go to (the list from the order-key task, plus the script hosts).
export const PLATFORM_HOSTS: Record<Platform, string[]> = {
    meta: ['facebook.com', 'facebook.net'],
    google: ['google-analytics.com', 'googletagmanager.com', 'googleadservices.com', 'doubleclick.net'],
    x: ['ads-twitter.com', 't.co', 'twitter.com', 'x.com'],
    microsoft: ['bat.bing.com'],
};

// Test IDs seeded by setup/seed.php. Only tags listed here are enabled on the e2e store.
export const TEST_IDS: Partial<Record<Tag, Record<string, string>>> = JSON.parse(
    readFileSync(join(__dirname, '..', 'config', 'test-ids.json'), 'utf8')
);

export const enabledTags = (): Tag[] => Object.keys(TEST_IDS) as Tag[];

export const platformOf = (url: string): Platform | null => {
    let host: string;

    try {
        host = new URL(url).hostname;
    } catch {
        return null;
    }

    for (const [platform, hosts] of Object.entries(PLATFORM_HOSTS) as [Platform, string[]][]) {
        if (hosts.some(h => host === h || host.endsWith(`.${h}`))) {
            return platform;
        }
    }

    return null;
};
