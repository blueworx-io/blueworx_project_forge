import { test, expect } from '@playwright/test';
import { signIn } from '../helpers/sign-in.js';
import { signedIn, makeSite, makeItem } from './helpers/forge.js';

// The session cache. Coming back to a screen shows what it already had
// without asking the server; a background re-check brings in what changed;
// the header says when, and its button asks for everything again.

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'admin';

test('a screen comes back from the cache, then picks up what changed', async ({ browser, baseURL, page }) => {
  test.slow();

  const admin = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const { site } = await makeSite(admin.api, 'Cache Co', RUN_ID);
  const first = (await (await makeItem(admin.api, site.id, { title: `First ${RUN_ID}` })).json()).item;

  await signIn(page, ADMIN_USER, ADMIN_PASS);
  await page.goto('/blueworx-forge/');
  await page.waitForSelector('[data-testid="bwx-board"]');
  await page.selectOption('[data-testid="bwx-site"]', site.id);

  const firstCard = page.locator(`[data-testid="bwx-card"][data-item="${first.id}"]`);
  await expect(firstCard).toBeVisible();
  await expect(page.getByTestId('bwx-refreshed')).toContainText('Refreshed');

  // With the server unreachable, the board still comes back — from the cache.
  await page.route('**/wp-json/blueworx-forge/**', (route) => route.abort());
  await page.getByTestId('bwx-screen-standup').click();
  await page.getByTestId('bwx-screen-work').click();
  await expect(firstCard).toBeVisible();

  // Something changes while the board is not looking; coming back finds it.
  await page.unroute('**/wp-json/blueworx-forge/**');
  const second = (await (await makeItem(admin.api, site.id, { title: `Second ${RUN_ID}` })).json()).item;
  const secondCard = page.locator(`[data-testid="bwx-card"][data-item="${second.id}"]`);

  await page.getByTestId('bwx-screen-standup').click();
  await page.waitForTimeout(5500); // past the re-check throttle
  await page.getByTestId('bwx-screen-work').click();
  await expect(firstCard).toBeVisible();
  await expect(secondCard).toBeVisible({ timeout: 15_000 });

  // The header's button reads everything again, right now.
  const asked = page.waitForRequest((request) => request.url().includes('/work-items') && 'GET' === request.method());
  await page.getByTestId('bwx-refresh-all').click();
  await asked;
  await expect(secondCard).toBeVisible();

  await admin.context.close();
});
