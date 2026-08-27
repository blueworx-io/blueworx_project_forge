// @ts-check
import { defineConfig, devices } from '@playwright/test';

import { STUDIO, CLIENT } from './bin/wp-pair.mjs';

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

// The pair's own addresses and login, defaulted here rather than left to
// whoever runs it (#225).
//
// These are not configuration: bin/wp-pair.mjs fixes both ports, and the
// harness creates the same throwaway admin on both sites. But only CI was
// passing them, so the two commands this repo documents — `npm run wp:pair:up`
// then `npm run test:pair` — could not run the suite at all on a developer's
// machine. Every spec threw in beforeAll, which reads as a run that started and
// then died partway through.
//
// Imported from wp-pair.mjs so the ports cannot drift from the sites it builds.
// Anything already set in the environment still wins, so CI keeps saying it.
process.env.PLAYWRIGHT_BASE_URL ??= `http://127.0.0.1:${STUDIO.port}`;
process.env.BWX_CLIENT_BASE_URL ??= `http://127.0.0.1:${CLIENT.port}`;
process.env.WP_ADMIN_USER ??= 'admin';
process.env.WP_ADMIN_PASS ??= 'wptest-admin-pw';

const baseURL = process.env.PLAYWRIGHT_BASE_URL;

export default defineConfig({
  testDir: './tests/pair',
  fullyParallel: false,
  // Three times the single-instance config's 60s, because this suite pays for
  // two cold WordPress installs rather than one — signing in to both, on PHP's
  // single-threaded built-in server, before it can assert anything. The first
  // spec of a fresh run was landing just the wrong side of 60s.
  //
  // Raised from 120s for #225. Measured on Windows, a spec in this suite takes
  // 20–70 seconds, and one was seen at 2.0 minutes — so the old limit was not
  // catching a hung run, it was cutting a working one off partway through, and
  // which spec it landed on varied. The headroom is deliberate: this is a
  // ceiling for a run that has genuinely stopped, not a target.
  timeout: 180_000,
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
