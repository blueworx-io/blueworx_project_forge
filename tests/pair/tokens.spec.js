import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

// #85, proven across two real WordPress sites: the studio interface and the
// client interface resolve the same design token to the same value, from one
// file in the repository.
//
// The unit test proves there is only one token file and that both builds are
// wired to it. This proves the wiring actually arrives — that a browser on the
// client's site, running the staged client plugin, resolves the token to what
// the file says. A token layer that ships but never loads looks identical in a
// zip listing and produces an unstyled screen.

const CLIENT_URL = process.env.BWX_CLIENT_BASE_URL;
const ADMIN_USER = process.env.WP_ADMIN_USER;
const ADMIN_PASS = process.env.WP_ADMIN_PASS;

const SCREEN = '/wp-admin/admin.php?page=blueworx-forge-client';

const TOKENS = fileURLToPath(new URL('../../tokens/colors.css', import.meta.url));
const BRAND = readFileSync(TOKENS, 'utf8').match(/--brand-600\s*:\s*(#[0-9a-fA-F]{3,8})/)?.[1];

test.beforeAll(() => {
  if (!CLIENT_URL || !ADMIN_USER || !ADMIN_PASS) {
    throw new Error('BWX_CLIENT_BASE_URL, WP_ADMIN_USER and WP_ADMIN_PASS must be set.');
  }

  if (!BRAND) {
    throw new Error('tokens/colors.css no longer declares --brand-600.');
  }
});

async function resolvedBrand(page) {
  return page.evaluate(() =>
    getComputedStyle(document.documentElement).getPropertyValue('--brand-600').trim()
  );
}

test('the studio interface resolves the brand token', async ({ page }) => {
  await page.goto('/blueworx-forge/');

  expect(await resolvedBrand(page)).toBe(BRAND);
});

test('the client interface resolves the same token to the same value', async ({ browser }) => {
  const context = await browser.newContext({ baseURL: CLIENT_URL });
  const page = await context.newPage();

  await page.goto('/wp-login.php');
  await page.fill('#user_login', ADMIN_USER);
  await page.fill('#user_pass', ADMIN_PASS);
  await page.click('#wp-submit');
  await page.waitForURL((url) => !url.pathname.endsWith('/wp-login.php'));

  await page.goto(SCREEN);

  expect(await resolvedBrand(page)).toBe(BRAND);

  // And it comes from the shared token layer at the top of the plugin, not from
  // a copy tucked inside it. Two copies is how the two interfaces start looking
  // different, and it would pass the test above for as long as they agreed.
  const href = await page.getAttribute('link#blueworx-forge-tokens-css', 'href');
  expect(href).toContain('/blueworx-forge-client/tokens/forge.css');

  await context.close();
});

test('the client plugin styles its own screen and no other', async ({ browser }) => {
  // The client screen lives inside wp-admin and is meant to look like it
  // belongs there, so it keeps WordPress's own styling. What it must not do is
  // take its tokens anywhere else — a plugin that restyles the whole dashboard
  // is the other half of "holds its own styling".
  const context = await browser.newContext({ baseURL: CLIENT_URL });
  const page = await context.newPage();

  await page.goto('/wp-login.php');
  await page.fill('#user_login', ADMIN_USER);
  await page.fill('#user_pass', ADMIN_PASS);
  await page.click('#wp-submit');
  await page.waitForURL((url) => !url.pathname.endsWith('/wp-login.php'));

  await page.goto('/wp-admin/');
  await expect(page.locator('link#blueworx-forge-tokens-css')).toHaveCount(0);

  await page.goto(SCREEN);
  await expect(page.locator('link#blueworx-forge-tokens-css')).toHaveCount(1);

  await context.close();
});
