import { test, expect } from '@playwright/test';

// #195 and #197 together: setting a client site up is done in a browser, by an
// administrator, with no file editing and no API calls.
//
// The other pair specs drive the REST routes directly because they are testing
// what the routes do. This one clicks through both dashboards exactly as a
// person would, because the thing under test is whether that is possible at
// all — and it is the rehearsal for doing it on a real staging pair.

const CLIENT_URL = process.env.BWX_CLIENT_BASE_URL;
const STUDIO_URL = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8892';
const ADMIN_USER = process.env.WP_ADMIN_USER;
const ADMIN_PASS = process.env.WP_ADMIN_PASS;

const STUDIO_SITES = '/wp-admin/admin.php?page=blueworx-forge-sites';
const CLIENT_CONNECTION = '/wp-admin/admin.php?page=blueworx-forge-client-connection';
const CLIENT_WORKSPACE = '/wp-admin/admin.php?page=blueworx-forge-client';

test.beforeAll(() => {
  if (!CLIENT_URL || !ADMIN_USER || !ADMIN_PASS) {
    throw new Error('BWX_CLIENT_BASE_URL, WP_ADMIN_USER and WP_ADMIN_PASS must be set.');
  }
});

async function dashboard(browser, baseURL) {
  const context = await browser.newContext({ baseURL });
  const page = await context.newPage();

  await page.goto('/wp-login.php');
  await page.fill('#user_login', ADMIN_USER);
  await page.fill('#user_pass', ADMIN_PASS);
  await page.click('#wp-submit');
  await page.waitForURL((url) => !url.pathname.endsWith('/wp-login.php'));

  return { context, page };
}

test('a client site is connected end to end, in the browser', async ({ browser }) => {
  const studio = await dashboard(browser, STUDIO_URL);
  const client = await dashboard(browser, CLIENT_URL);

  // The studio issues the credentials.
  await studio.page.goto(STUDIO_SITES);
  await studio.page.fill('#bwx-site-name', 'Acme Ltd');
  await studio.page.fill('#bwx-site-url', CLIENT_URL);
  await studio.page.click('form[data-bwx-register] input[type="submit"]');

  const issued = studio.page.locator('[data-bwx-issued-key]');
  await expect(issued).toBeVisible();

  const siteId = (await issued.locator('[data-bwx-site-id]').innerText()).trim();
  const key = (await issued.locator('[data-bwx-key]').innerText()).trim();

  // The client site is told who it is.
  await client.page.goto(CLIENT_CONNECTION);
  await client.page.fill('#bwx-studio-url', STUDIO_URL);
  await client.page.fill('#bwx-site-id', siteId);
  await client.page.fill('#bwx-key', key);
  await client.page.click('form[data-bwx-connect] input[type="submit"]');

  await expect(client.page.locator('[data-bwx-connection="ok"]')).toBeVisible();
  await expect(client.page.locator('[data-bwx-client-name]')).toHaveText('Acme Ltd');

  // And its workspace shows what the studio holds for it.
  await client.page.goto(CLIENT_WORKSPACE);
  await expect(client.page.locator('[data-bwx-workspace]')).toContainText('Acme Ltd');

  await studio.context.close();
  await client.context.close();
});

test('the key is never rendered back on the client site', async ({ browser }) => {
  const studio = await dashboard(browser, STUDIO_URL);
  const client = await dashboard(browser, CLIENT_URL);

  await studio.page.goto(STUDIO_SITES);
  await studio.page.fill('#bwx-site-name', 'Quiet Ltd');
  await studio.page.fill('#bwx-site-url', CLIENT_URL);
  await studio.page.click('form[data-bwx-register] input[type="submit"]');

  const key = (
    await studio.page.locator('[data-bwx-issued-key] [data-bwx-key]').innerText()
  ).trim();
  const siteId = (
    await studio.page.locator('[data-bwx-issued-key] [data-bwx-site-id]').innerText()
  ).trim();

  await client.page.goto(CLIENT_CONNECTION);
  await client.page.fill('#bwx-studio-url', STUDIO_URL);
  await client.page.fill('#bwx-site-id', siteId);
  await client.page.fill('#bwx-key', key);
  await client.page.click('form[data-bwx-connect] input[type="submit"]');

  // The screen that stores the key must not be a screen that displays it: the
  // page source of an admin screen is not a safe place for a shared secret.
  expect(await client.page.content()).not.toContain(key);

  await studio.context.close();
  await client.context.close();
});

test('a site cut off from the studio is told so on its own screen', async ({ browser }) => {
  const studio = await dashboard(browser, STUDIO_URL);
  const client = await dashboard(browser, CLIENT_URL);

  await studio.page.goto(STUDIO_SITES);
  await studio.page.fill('#bwx-site-name', 'Cutoff Ltd');
  await studio.page.fill('#bwx-site-url', CLIENT_URL);
  await studio.page.click('form[data-bwx-register] input[type="submit"]');

  const issued = studio.page.locator('[data-bwx-issued-key]');
  const siteId = (await issued.locator('[data-bwx-site-id]').innerText()).trim();
  const key = (await issued.locator('[data-bwx-key]').innerText()).trim();

  await client.page.goto(CLIENT_CONNECTION);
  await client.page.fill('#bwx-studio-url', STUDIO_URL);
  await client.page.fill('#bwx-site-id', siteId);
  await client.page.fill('#bwx-key', key);
  await client.page.click('form[data-bwx-connect] input[type="submit"]');
  await expect(client.page.locator('[data-bwx-connection="ok"]')).toBeVisible();

  studio.page.once('dialog', (dialog) => dialog.accept());
  await studio.page.click(`[data-bwx-site="${siteId}"] [data-bwx-action="bwx_forge_revoke_site"]`);
  await expect(studio.page.locator(`[data-bwx-site="${siteId}"] [data-bwx-status]`)).toHaveText('revoked');

  // The client still holds its credentials and is now refused. The screen has
  // to say that plainly, because "connected" and "holding a key that no longer
  // works" look identical from here otherwise.
  await client.page.goto(CLIENT_CONNECTION);
  await expect(client.page.locator('[data-bwx-connection="refused"]')).toBeVisible();

  // Disconnecting is the client's own choice, and separate from being cut off.
  client.page.once('dialog', (dialog) => dialog.accept());
  await client.page.click('[data-bwx-action="bwx_forge_client_disconnect"]');
  await expect(client.page.locator('[data-bwx-connection="not_configured"]')).toBeVisible();

  await studio.context.close();
  await client.context.close();
});
