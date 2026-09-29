import { test, expect } from '@playwright/test';
import { signIn } from '../helpers/sign-in.js';
import { readFileSync } from 'node:fs';

// #466. Every release and what changed in it, beside Updates, with the
// release this site runs marked.

const CHANGELOG = '/wp-admin/admin.php?page=blueworx-forge-changelog';
const VERSION = JSON.parse(readFileSync(new URL('../../package.json', import.meta.url))).version;

test.beforeEach(async ({ page }) => {
  await signIn(page);
});

test('the Forge menu offers the changelog, after Updates', async ({ page }) => {
  await page.goto('/wp-admin/admin.php?page=blueworx-forge-updates');

  const links = page.locator('#adminmenu a[href*="page=blueworx-forge-"]');
  const hrefs = await links.evaluateAll((all) => all.map((a) => a.getAttribute('href')));
  const updates = hrefs.findIndex((h) => h.endsWith('page=blueworx-forge-updates'));
  expect(hrefs[updates + 1]).toMatch(/page=blueworx-forge-changelog$/);
});

test('it lists the releases, newest first, with this site\'s marked', async ({ page }) => {
  await page.goto(CHANGELOG);

  await expect(page.locator('.bw-admin.bw-page')).toBeVisible();
  const installed = page.locator('[data-bwx-installed-release]');
  await expect(installed).toHaveAttribute('data-bwx-release', VERSION);
  await expect(installed).toContainText('Installed');

  const first = page.locator('[data-bwx-release]').first();
  await expect(first).toHaveAttribute('data-bwx-release', VERSION);
  expect(await page.locator('[data-bwx-release]').count()).toBeGreaterThan(50);
});
