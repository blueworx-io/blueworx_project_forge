import { test, expect } from '@playwright/test';

// #200 walked as an administrator walks it. The point of the screen is that a
// site which cannot fetch its updates says so, rather than looking exactly like
// a site that is up to date — so the assertions below are about what the screen
// states, not about whether an option was written.

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'wptest-admin-pw';

const UPDATES = '/wp-admin/admin.php?page=blueworx-forge-updates';

async function signIn(page) {
  await page.goto('/wp-login.php');
  await page.fill('#user_login', ADMIN_USER);
  await page.fill('#user_pass', ADMIN_PASS);
  await page.click('#wp-submit');
  await page.waitForURL((url) => !url.pathname.endsWith('/wp-login.php'));
}

async function removeStoredToken(page) {
  await page.goto(UPDATES);

  const remove = page.locator('[data-bwx-action="bwx_forge_forget_update_token"]');

  if (await remove.count()) {
    await remove.click();
    await expect(page.locator('[data-bwx-result="forgotten"]')).toBeVisible({ timeout: 30_000 });
  }
}

test.describe.configure({ mode: 'serial' });

test.beforeEach(async ({ page }) => {
  await signIn(page);
  // The token is site-wide state, and a run that left one behind would have the
  // first assertion below pass for the wrong reason.
  await removeStoredToken(page);
});

test.afterAll(async ({ browser }) => {
  const page = await browser.newPage();
  await signIn(page);
  await removeStoredToken(page);
  await page.close();
});

test('the Forge menu offers the updates screen', async ({ page }) => {
  await page.goto('/wp-admin/admin.php?page=blueworx-forge-sites');

  await expect(page.locator(`#adminmenu a[href$="page=blueworx-forge-updates"]`)).toHaveCount(1);
});

test('a site with no token is told its updates are invisible, not that it is current', async ({ page }) => {
  await page.goto(UPDATES);

  const status = page.locator('[data-bwx-updates]');

  await expect(status).toHaveAttribute('data-bwx-updates', 'none');
  await expect(status).toContainText('Updates cannot be fetched');
  await expect(status).toContainText('no token');
});

test('a token can be set in the dashboard, and the screen stops saying there is none', async ({ page }) => {
  await page.goto(UPDATES);
  await page.fill('#bwx-update-token', 'github_pat_not_a_real_token');
  await page.click('form[data-bwx-update-token] input[type="submit"]');

  await expect(page.locator('[data-bwx-result="saved"]')).toBeVisible({ timeout: 30_000 });

  // Whether GitHub accepts this one is not the point and cannot be asserted
  // from here — that it is now the token being used is. "none" means the site
  // never asked; anything else means it did.
  await expect(page.locator('[data-bwx-updates]')).not.toHaveAttribute('data-bwx-updates', 'none', { timeout: 30_000 });

  // And the field says a token is held without ever printing it back.
  await expect(page.locator('#bwx-update-token')).toHaveValue('');
  await expect(page.locator('form[data-bwx-update-token]')).toContainText('A token is stored');
  await expect(page.content()).resolves.not.toContain('github_pat_not_a_real_token');
});

test('a stored token can be removed again', async ({ page }) => {
  await page.goto(UPDATES);
  await page.fill('#bwx-update-token', 'github_pat_not_a_real_token');
  await page.click('form[data-bwx-update-token] input[type="submit"]');
  await expect(page.locator('[data-bwx-result="saved"]')).toBeVisible({ timeout: 30_000 });

  await page.click('[data-bwx-action="bwx_forge_forget_update_token"]');

  await expect(page.locator('[data-bwx-result="forgotten"]')).toBeVisible({ timeout: 30_000 });
  await expect(page.locator('[data-bwx-updates]')).toHaveAttribute('data-bwx-updates', 'none');
});
