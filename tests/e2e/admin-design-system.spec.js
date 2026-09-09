import { test, expect } from '@playwright/test';

// The design system loads on the studio's own admin screens and on no others.
//
// style-isolation.spec.js covers the front-end app page and says nothing about
// wp-admin, so without this the enqueue is uncovered — and the failure it would
// miss is the loud one: a plugin that repaints the whole of somebody's
// dashboard is a plugin they uninstall.

async function signIn(page) {
  await page.goto('/wp-login.php');
  await page.fill('#user_login', process.env.WP_ADMIN_USER ?? 'admin');
  await page.fill('#user_pass', process.env.WP_ADMIN_PASS ?? 'wptest-admin-pw');
  await page.click('#wp-submit');
  await page.waitForURL((url) => !url.pathname.endsWith('/wp-login.php'));
}

const DESIGN_SYSTEM = 'link#blueworx-admin-design-css';

test('a studio screen loads the design system', async ({ page }) => {
  await signIn(page);
  await page.goto('/wp-admin/admin.php?page=blueworx-forge-sites');

  await expect(page.locator(DESIGN_SYSTEM)).toHaveCount(1);
});

test('a studio screen is built on the shell, not merely loading it', async ({ page }) => {
  // Loading a stylesheet nothing uses is the failure mode this whole piece of
  // work exists to end, so one screen is checked for the shell itself.
  await signIn(page);
  await page.goto('/wp-admin/admin.php?page=blueworx-forge-updates');

  await expect(page.locator('.bw-admin.bw-page')).toBeVisible();
});

test('the dashboard loads none of it', async ({ page }) => {
  await signIn(page);
  await page.goto('/wp-admin/');

  await expect(page.locator(DESIGN_SYSTEM)).toHaveCount(0);
});

test('no admin screen loads the token layer any more', async ({ page }) => {
  // tokens/forge.css keeps shipping — the React application reads it — but the
  // admin screens draw on the foundation's --bw-* variables now, and loading
  // both would be the mapping layer the design ruled out.
  await signIn(page);
  await page.goto('/wp-admin/admin.php?page=blueworx-forge-sites');

  await expect(page.locator('link#blueworx-forge-tokens-css')).toHaveCount(0);
});
