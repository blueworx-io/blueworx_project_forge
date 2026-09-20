import { test, expect } from '@playwright/test';
import { signIn } from '../helpers/sign-in.js';

// #200 and #340, walked as an administrator walks it. Releases come from a
// public repository now, so the screen takes nothing — it says whether this
// site can see them, and which version it runs. A site that cannot fetch its
// updates says so rather than looking exactly like a site that is up to date.

const UPDATES = '/wp-admin/admin.php?page=blueworx-forge-updates';

test.beforeEach(async ({ page }) => {
  await signIn(page);
});

test('the Forge menu offers the updates screen', async ({ page }) => {
  await page.goto('/wp-admin/admin.php?page=blueworx-forge-sites');

  await expect(page.locator(`#adminmenu a[href$="page=blueworx-forge-updates"]`)).toHaveCount(1);
});

test('the screen reports whether updates can be fetched, and asks for nothing', async ({ page }) => {
  await page.goto(UPDATES);

  const status = page.locator('[data-bwx-updates]');

  // Whichever answer GitHub gives from here — a release, none published yet,
  // the anonymous limit used up, or no way out — it is one of the named
  // states, never silence, and never "no token".
  await expect(status).toHaveAttribute('data-bwx-updates', /^(ok|missing|limited|unreachable)$/);
  await expect(status).not.toContainText('token');

  // Nothing to type: there is no token field and no form on this screen.
  await expect(page.locator('#bwx-update-token')).toHaveCount(0);
  await expect(page.locator('form[data-bwx-update-token]')).toHaveCount(0);

  // And it says which version this site runs.
  await expect(page.locator('[data-bwx-installed]')).toHaveAttribute('data-bwx-installed', /^\d+\.\d+\.\d+$/);
});
