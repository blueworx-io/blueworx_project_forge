// @ts-check
import { defineConfig, devices } from '@playwright/test';

// The two-instance suite (#86). Separate from playwright.config.js on purpose:
// the single-instance suite runs in the shared foundation's CI job, which
// provisions one WordPress and knows nothing about a second. Folding both into
// one config would have that job try to run specs against a site it never
// created — and a spec that cannot reach its site is the kind that gets quietly
// marked skipped.
//
//   npm run wp:pair:up
//   npm run test:pair
//   npm run wp:pair:down

const baseURL = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8892';

export default defineConfig({
  testDir: './tests/pair',
  fullyParallel: false,
  // Same reasoning as the single-instance config: a WordPress installed seconds
  // ago, on PHP's single-threaded built-in server, and now two of them.
  timeout: 60_000,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  // Keep the json reporter: bin/check-tests-ran.mjs reads it to prove the suite
  // actually executed something. `playwright test` exits 0 when everything
  // skips, so without that check a suite that skips itself reports green.
  reporter: [
    ['list'],
    ['json', { outputFile: process.env.PLAYWRIGHT_JSON_OUTPUT_NAME || 'playwright-pair-report.json' }],
  ],
  // Both sites are shared, site-wide state. Parallel workers would make one
  // spec's login another spec's logout.
  workers: 1,
  use: {
    baseURL,
    trace: 'on-first-retry',
  },
  projects: [
    {
      name: 'pair',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
});
