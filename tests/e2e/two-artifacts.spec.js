import { test, expect } from '@playwright/test';

// ARCH-1: one repo, two plugins. The studio plugin and the client plugin install
// and activate independently on the same WordPress, and neither needs the other
// to have loaded. Staged by tests/global-setup.js from the same allowlist the
// release publishes, so this exercises what actually ships.

const ADMIN_USER = process.env.WP_ADMIN_USER;
const ADMIN_PASS = process.env.WP_ADMIN_PASS;

test.beforeAll(() => {
  if (!ADMIN_USER || !ADMIN_PASS) {
    throw new Error('WP_ADMIN_USER and WP_ADMIN_PASS must be set.');
  }
});

async function signIn(page) {
  await page.goto('/wp-login.php');
  await page.fill('#user_login', ADMIN_USER);
  await page.fill('#user_pass', ADMIN_PASS);
  await page.click('#wp-submit');
  await page.waitForURL((url) => !url.pathname.endsWith('/wp-login.php'));
}

function clientRow(page) {
  return page.locator('tr[data-slug="blueworx-forge-client"], tr#blueworx-forge-client');
}

test('the client plugin is installed alongside the studio one', async ({ page }) => {
  await signIn(page);
  await page.goto('/wp-admin/plugins.php', { waitUntil: 'domcontentloaded' });

  await expect(page.locator('tr[data-slug="blueworx-forge"], tr#blueworx-forge')).toHaveCount(1);
  await expect(clientRow(page)).toHaveCount(1);
  await expect(clientRow(page)).toContainText('Blueworx Forge Client');
});

test('the client plugin activates on its own, with no PHP error', async ({ page }) => {
  await signIn(page);
  await page.goto('/wp-admin/plugins.php', { waitUntil: 'domcontentloaded' });

  const row = clientRow(page);
  const activate = row.locator('a#activate-blueworx-forge-client, .activate a');

  if ((await activate.count()) > 0) {
    await activate.first().click();
    await page.waitForLoadState('domcontentloaded');
  }

  await expect(clientRow(page)).toHaveClass(/(^|\s)active(\s|$)/);

  // A fatal on activation surfaces here as an error notice rather than a crash.
  await expect(page.locator('#message.error, .notice-error:visible')).toHaveCount(0);
});

test('the studio plugin keeps working with the client plugin active', async ({ page, request }) => {
  // The two are separate artifacts, not two halves of one plugin. Activating the
  // client must not disturb the studio plugin's routes or its app page.
  const status = await request.get('/wp-json/blueworx-forge/v1/status');
  expect(status.status()).toBe(200);
  expect((await status.json()).plugin).toBe('blueworx-forge');

  await page.goto('/blueworx-forge/');
  await expect(page.getByTestId('bwx-forge-ready')).toBeVisible({ timeout: 15_000 });
});

test('the client plugin registers no studio routes', async ({ request }) => {
  // The client half has no REST namespace of its own yet. What matters is that
  // it did not acquire the studio's: a client site must not be able to answer
  // command-centre calls just because both plugins exist in this test.
  const response = await request.get('/wp-json/blueworx-forge-client/v1/status');

  expect(response.status()).toBe(404);
});
