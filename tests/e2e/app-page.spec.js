import { test, expect } from '@playwright/test';

// Activation creates the app page and assigns the plugin's full-page template.
// This spec is the one that catches the failure that matters on a live site: the
// page exists but the app never mounts.

test('the app page exists and carries the mount point', async ({ page }) => {
  const response = await page.goto('/blueworx-forge/');
  expect(response?.status()).toBe(200);

  await expect(page.locator('#bwx-forge-app')).toHaveCount(1);
});

test('the app page localises what the front end needs', async ({ page }) => {
  await page.goto('/blueworx-forge/');

  const data = await page.evaluate(() => window.bwxForgeData);
  expect(data, 'bwxForgeData was not localised — the enqueue never ran').toBeTruthy();
  expect(data.restUrl).toContain('/blueworx-forge/v1');
  expect(typeof data.nonce).toBe('string');
  expect(data.nonce.length).toBeGreaterThan(0);
  // A logged-out visitor can read and nothing else. The front end uses this to
  // decide what to render; the REST layer enforces it independently.
  expect(data.canEdit).toBe(false);
});

test('the React app mounts into the page', async ({ page }) => {
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));

  await page.goto('/blueworx-forge/');

  // React renders into the empty mount div, so a child element is proof the
  // bundle loaded and executed rather than 404ing or throwing.
  await expect(page.getByTestId('bwx-forge-ready')).toBeVisible({ timeout: 15_000 });
  expect(errors, `the page raised JavaScript errors:\n${errors.join('\n')}`).toEqual([]);
});
