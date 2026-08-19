import { test, expect } from '@playwright/test';

const ADMIN_USER = process.env.WP_ADMIN_USER;
const ADMIN_PASS = process.env.WP_ADMIN_PASS;

// A spec that skips itself is not a spec that passed. Fail loudly instead, so a
// missing secret is a broken build rather than a silently smaller suite.
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
  await page.goto('/wp-admin/', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('#adminmenu')).toBeVisible();
}

test('the plugin is installed and active', async ({ page }) => {
  await signIn(page);
  await page.goto('/wp-admin/plugins.php', { waitUntil: 'domcontentloaded' });

  // By plugin file, not by row id: WordPress builds the id from the plugin's
  // display name, so renaming the plugin silently stops the row being found.
  const row = page.locator('tr[data-plugin="blueworx-forge/blueworx-forge.php"]');
  await expect(row).toHaveCount(1);
  await expect(row).toContainText('BlueWorx Labs | Forge Parent Site');
  // Word-boundary match is load-bearing: WordPress renders an inactive row with
  // class="inactive", and the substring "active" inside "inactive" would match
  // /active/ regardless of activation state.
  await expect(row).toHaveClass(/(^|\s)active(\s|$)/);
});

test('activating the plugin raises no PHP error', async ({ page }) => {
  await signIn(page);
  await page.goto('/wp-admin/plugins.php', { waitUntil: 'domcontentloaded' });

  // A fatal on activation surfaces here as an error notice rather than a crash.
  // Filtered to :visible because WP core renders a hidden, empty .notice-error
  // template in every plugin row's auto-updates column.
  await expect(page.locator('#message.error, .notice-error:visible')).toHaveCount(0);
});

test('activation builds the plugin tables', async ({ page }) => {
  await signIn(page);

  // The sites screen predates this branch and touches neither new table, so it
  // proves only that the plugin booted, not that bwx_forge_clients and
  // bwx_forge_client_sites exist. Writing to and reading back a client does:
  // if either table (or a column on it) is missing, the INSERT or the SELECT
  // that renders the list fails, and the client never appears.
  const name = `Activation Ltd ${Date.now()}-${Math.floor(Math.random() * 1e6)}`;

  await page.goto('/wp-admin/admin.php?page=blueworx-forge-clients');
  await expect(page.locator('h1')).toContainText('Forge');

  await page.fill('#bwx-client-name', name);
  await page.click('form[data-bwx-add-client] input[type="submit"]');

  await expect(page.locator(`[data-bwx-client-name]:text-is("${name}")`)).toBeVisible();
});
