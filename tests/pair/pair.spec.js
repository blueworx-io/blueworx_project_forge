import { test, expect } from '@playwright/test';

// #86: one command yields a studio WordPress and a client WordPress, both
// disposable, both real. The point is not that two servers are listening — it is
// that each one contains only its own half, because every later two-instance
// test (#83's signed requests, #84's read-through) is only meaningful if the
// client site genuinely cannot reach studio code locally.

const CLIENT_URL = process.env.BWX_CLIENT_BASE_URL;

test.beforeAll(() => {
  if (!CLIENT_URL) {
    throw new Error('BWX_CLIENT_BASE_URL must be set — the pair harness sets it.');
  }
});

test('the studio site answers, and it is the studio plugin answering', async ({ request }) => {
  const response = await request.get('/wp-json/blueworx-forge/v1/status');

  expect(response.status()).toBe(200);
  expect((await response.json()).plugin).toBe('blueworx-forge');
});

test('the client site answers, and it is a different site', async ({ request }) => {
  const response = await request.get(`${CLIENT_URL}/`);

  expect(response.status()).toBe(200);
});

test('the client site has the client plugin active', async ({ page }) => {
  await page.goto(`${CLIENT_URL}/wp-login.php`);
  await page.fill('#user_login', process.env.WP_ADMIN_USER);
  await page.fill('#user_pass', process.env.WP_ADMIN_PASS);
  await page.click('#wp-submit');
  await page.waitForURL((url) => !url.pathname.endsWith('/wp-login.php'));

  await page.goto(`${CLIENT_URL}/wp-admin/plugins.php`, { waitUntil: 'domcontentloaded' });

  const row = page.locator('tr[data-slug="blueworx-forge-client"], tr#blueworx-forge-client');
  await expect(row).toHaveCount(1);
  await expect(row).toHaveClass(/(^|\s)active(\s|$)/);
  await expect(page.locator('#message.error, .notice-error:visible')).toHaveCount(0);
});

test('the studio plugin is not installed on the client site', async ({ page }) => {
  // ARCH-1, proven on a running site rather than on a zip listing: the client
  // WordPress does not contain the command centre at all.
  await page.goto(`${CLIENT_URL}/wp-login.php`);
  await page.fill('#user_login', process.env.WP_ADMIN_USER);
  await page.fill('#user_pass', process.env.WP_ADMIN_PASS);
  await page.click('#wp-submit');
  await page.waitForURL((url) => !url.pathname.endsWith('/wp-login.php'));

  await page.goto(`${CLIENT_URL}/wp-admin/plugins.php`, { waitUntil: 'domcontentloaded' });

  await expect(page.locator('tr[data-slug="blueworx-forge"], tr#blueworx-forge')).toHaveCount(0);
});

test('the client site serves no studio REST namespace', async ({ request }) => {
  const response = await request.get(`${CLIENT_URL}/wp-json/blueworx-forge/v1/status`);

  expect(response.status()).toBe(404);
});

test('the two sites are separate installs, not one site on two ports', async ({ request }) => {
  // Same database would make every later isolation test meaningless.
  const studio = await request.get('/wp-json/');
  const client = await request.get(`${CLIENT_URL}/wp-json/`);

  const studioNamespaces = (await studio.json()).namespaces;
  const clientNamespaces = (await client.json()).namespaces;

  expect(studioNamespaces).toContain('blueworx-forge/v1');
  expect(clientNamespaces).not.toContain('blueworx-forge/v1');
});
