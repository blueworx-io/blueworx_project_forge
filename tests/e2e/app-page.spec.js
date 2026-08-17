import { test, expect } from '@playwright/test';

// Activation generates the app page, assigns the plugin's full-page template and
// seeds sample data. This spec is the one that would catch the failure that
// matters on a live site: the page exists but the app never mounts, because the
// built bundle in assets/ is stale, missing, or throws on boot.

test('the generated app page mounts the Forge app', async ({ page }) => {
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));

  // The generated page is created with this slug on activation. Following the
  // permalink rather than a guessed URL would need wp-admin; the slug is part of
  // the plugin's own behaviour, so asserting on it directly is the point.
  const response = await page.goto('/forge-project-management/');
  expect(response?.status()).toBe(200);

  const root = page.locator('#forge-pm-app');
  await expect(root).toHaveCount(1);

  // The bundle localises this before it boots. Missing means the enqueue never
  // ran — the page rendered, and the app had nothing to talk to.
  const apiUrl = await page.evaluate(() => window.forgePMData && window.forgePMData.apiUrl);
  expect(apiUrl).toContain('/forge/v1');

  // React renders into the empty root div, so a child element is proof the
  // bundle loaded and executed rather than 404ing or throwing.
  await expect(root.locator('> *').first()).toBeVisible({ timeout: 15_000 });

  expect(errors, `the page raised JavaScript errors:\n${errors.join('\n')}`).toEqual([]);
});

test('the public items endpoint answers without a login', async ({ request }) => {
  const response = await request.get('/wp-json/forge/v1/items');
  expect(response.status()).toBe(200);

  const body = await response.json();
  // The endpoint is deliberately public (it backs the read-only view for
  // visitors) and returns the item collection keyed by type. A shape change here
  // breaks the app's first load, which no PHP-only check would notice.
  for (const key of ['features', 'subitems', 'bugs', 'feedback', 'releases', 'companyDates']) {
    expect(Array.isArray(body[key]), `expected an array at "${key}"`).toBe(true);
  }
});
