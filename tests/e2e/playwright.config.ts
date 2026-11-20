import { defineConfig, devices } from '@playwright/test';
import { BASE_URL } from './utils/site';

export default defineConfig({
    testDir: './specs',
    outputDir: './test-results',
    globalSetup: './global-setup.ts',
    // One store and one guest session per test, so specs run one at a time.
    fullyParallel: false,
    workers: 1,
    forbidOnly: !!process.env.CI,
    // No retries: a Purchase hit that only shows up sometimes is a failure worth seeing.
    retries: 0,
    timeout: 90_000,
    expect: { timeout: 10_000 },
    reporter: process.env.CI
        ? [['list'], ['github'], ['html', { open: 'never', outputFolder: 'playwright-report' }]]
        : [['list'], ['html', { open: 'never', outputFolder: 'playwright-report' }]],
    use: {
        baseURL: BASE_URL,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
    },
    projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
