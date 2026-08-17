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

  const row = page.locator('tr[data-slug="blueworx-forge"], tr#blueworx-forge');
  await expect(row).toHaveCount(1);
  await expect(row).toContainText('Blueworx Forge');
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
