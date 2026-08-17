// @ts-check
import { defineConfig, devices } from '@playwright/test';

// The suite runs against a disposable real WordPress the run provisions itself
// (PHP + SQLite, no Docker), never a hosted staging site. Locally:
//
//   npm run wp:up
//   npm test
//   npm run wp:down
//
// `npm test` runs plain `playwright test` (no --reporter override), so the
// reporter list below is what governs, and json's outputFile defaults to
// playwright-report.json in the repo root. A CLI `--reporter=...` flag would
// replace this list wholesale and silence outputFile, so don't add one.
//
// In CI the foundation's ci-wordpress.yml provisions the instance and sets
// PLAYWRIGHT_BASE_URL, WP_ADMIN_USER, WP_ADMIN_PASS and its own
// PLAYWRIGHT_JSON_OUTPUT_NAME.
// 8892, not the foundation's default 8881: every project's harness would
// otherwise want the same port, and a run that finds another project's
// WordPress there fails in a thoroughly confusing way — the site answers, so
// nothing looks broken until every spec fails at once. CI passes
// PLAYWRIGHT_BASE_URL explicitly and is unaffected.
const baseURL = process.env.PLAYWRIGHT_BASE_URL || process.env.BASE_URL || 'http://127.0.0.1:8892';

export default defineConfig({
  testDir: './tests/e2e',
  // Puts the test WordPress offline (see the file for why) before anything runs.
  globalSetup: './tests/global-setup.js',
  fullyParallel: false,
  // Playwright's 30s default assumes a warm application server. This suite talks
  // to a WordPress installed seconds earlier, over PHP's single-threaded built-in
  // server, and the first wp-admin screen of a run routinely takes most of that
  // budget on its own. 60s costs nothing when things are healthy.
  timeout: 60_000,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  // Keep the json reporter. CI reads its output to prove tests actually ran —
  // `playwright test` exits 0 when everything skips, so without it a suite that
  // skips itself reports green having asserted nothing.
  reporter: [
    ['list'],
    ['json', { outputFile: process.env.PLAYWRIGHT_JSON_OUTPUT_NAME || 'playwright-report.json' }],
  ],
  // The specs sign in and read site-wide state, so parallel workers against one
  // site make one spec's "off" another spec's "on".
  workers: 1,
  use: {
    baseURL,
    trace: 'on-first-retry',
  },
  projects: [
    {
      name: 'wordpress',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
});
