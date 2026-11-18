import { defineConfig } from '@playwright/test';

// Point the suite at any running copy of the app:
//   E2E_BASE_URL=https://your-domain npm run e2e
// Tests tagged @live call the real Pixelforge API (they use provider quota), so they only run
// with `npm run e2e:live`; plain `npm run e2e` skips them.
export default defineConfig({
    testDir: './e2e',
    timeout: 60_000,
    expect: { timeout: 10_000 },
    // Tests share one database and the provider's concurrency limit (3), so run one at a time.
    fullyParallel: false,
    workers: 1,
    retries: 0,
    reporter: [['list'], ['html', { open: 'never', outputFolder: 'e2e-report' }]],
    outputDir: 'e2e-results',
    use: {
        baseURL: process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8000',
        // Uses the Chrome already installed on the machine, so no browser download is needed.
        channel: process.env.E2E_BROWSER_CHANNEL ?? 'chrome',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
});
