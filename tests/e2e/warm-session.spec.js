import { test, expect } from '@playwright/test';
import { signIn } from '../helpers/sign-in.js';

// #489. After sign-in the app quietly reads what every screen needs, one
// request at a time, so the first visit to a screen is as quick as the
// second. A change forgets everything, and the warming starts again.

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'admin';

test('once warm, Capacity and Clients open without a wait, and a change warms again', async ({ page }) => {
  test.setTimeout(600_000);

  await signIn(page, ADMIN_USER, ADMIN_PASS);
  await page.goto('/blueworx-forge/');
  await expect(page.getByTestId('bwx-forge-ready')).toBeVisible({ timeout: 60_000 });

  // The warm-up runs after the first screen, and says when it is done.
  await expect(page.locator('html')).toHaveAttribute('data-bwx-warm', 'done', { timeout: 480_000 });

  for (const [screen, ready] of [['capacity', 'bwx-capacity-grid'], ['clients', 'bwx-clients-list']]) {
    const started = Date.now();

    await page.getByTestId(`bwx-screen-${screen}`).click();
    await expect(page.getByTestId(ready)).toBeVisible({ timeout: 10_000 });

    expect(Date.now() - started, `${screen} showed without a wait`).toBeLessThan(2_000);
  }

  // A change forgets what was kept, so the warming goes round again.
  await page.getByTestId('bwx-screen-leads').click();
  await page.getByTestId('bwx-leads-add').click();
  await page.getByTestId('bwx-leads-form-name').fill(`Warm lead ${Date.now()}`);
  await page.getByTestId('bwx-leads-form-save').click();
  await expect(page.locator('html')).toHaveAttribute('data-bwx-warm', 'warming', { timeout: 60_000 });
  await expect(page.locator('html')).toHaveAttribute('data-bwx-warm', 'done', { timeout: 480_000 });
});
