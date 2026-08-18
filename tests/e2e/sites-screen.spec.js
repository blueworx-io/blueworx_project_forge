import { test, expect } from '@playwright/test';

// #195. Connecting a client site is an administrator's job done in a browser,
// not a developer's job done with a signed API call.
//
// These specs walk the screen the way a person does, because the failures that
// matter here are all in the walking: a key that is still on screen after a
// refresh, a "cut off" button that does nothing because its confirmation was
// dismissed, an action that works for someone who is not logged in.

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'wptest-admin-pw';

const SCREEN = '/wp-admin/admin.php?page=blueworx-forge-sites';

async function signIn(page) {
  await page.goto('/wp-login.php');
  await page.fill('#user_login', ADMIN_USER);
  await page.fill('#user_pass', ADMIN_PASS);
  await page.click('#wp-submit');
  await page.waitForURL((url) => !url.pathname.endsWith('/wp-login.php'));
}

async function registerSite(page, name) {
  await page.goto(SCREEN);
  await page.fill('#bwx-site-name', name);
  await page.fill('#bwx-site-url', 'https://example.test');
  await page.click('form[data-bwx-register] input[type="submit"]');

  const issued = page.locator('[data-bwx-issued-key]');
  await expect(issued).toBeVisible();

  return {
    siteId: (await issued.locator('[data-bwx-site-id]').innerText()).trim(),
    key: (await issued.locator('[data-bwx-key]').innerText()).trim(),
  };
}

test('an administrator can register a client site and is given its key', async ({ page }) => {
  await signIn(page);

  const site = await registerSite(page, 'Acme Ltd');

  expect(site.siteId).not.toEqual('');
  // 32 bytes, hex — long enough that seeing a short one means something is wrong
  // with how it was made, not with how it was displayed.
  expect(site.key).toMatch(/^[0-9a-f]{64}$/);

  await expect(page.locator(`[data-bwx-site="${site.siteId}"] [data-bwx-status]`)).toHaveText('active');
});

test('the key is shown once and never again', async ({ page }) => {
  await signIn(page);

  await registerSite(page, 'Once Ltd');

  await page.goto(SCREEN);

  await expect(page.locator('[data-bwx-issued-key]')).toHaveCount(0);
  // And it is not merely hidden: nothing on the page carries it.
  await expect(page.locator('body')).not.toContainText(/^[0-9a-f]{64}$/);
});

test('issuing a new key gives a different one', async ({ page }) => {
  await signIn(page);

  const first = await registerSite(page, 'Rotating Ltd');

  await page.click(`[data-bwx-site="${first.siteId}"] [data-bwx-action="bwx_forge_rotate_site"]`);

  const issued = page.locator('[data-bwx-issued-key]');
  await expect(issued).toBeVisible();

  const second = (await issued.locator('[data-bwx-key]').innerText()).trim();
  expect(second).not.toEqual(first.key);
});

test('cutting a site off asks first, then shows it as revoked', async ({ page }) => {
  await signIn(page);

  const site = await registerSite(page, 'Revoked Ltd');

  // Playwright dismisses dialogs by default, so without this the button does
  // nothing and the spec would pass a screen that never revoked anything.
  page.once('dialog', (dialog) => dialog.accept());
  await page.click(`[data-bwx-site="${site.siteId}"] [data-bwx-action="bwx_forge_revoke_site"]`);

  await expect(page.locator(`[data-bwx-site="${site.siteId}"] [data-bwx-status]`)).toHaveText('revoked');

  // A site already cut off cannot be handed a fresh key by mistake.
  await page.click(`[data-bwx-site="${site.siteId}"] [data-bwx-action="bwx_forge_rotate_site"]`);
  await expect(page.locator('[data-bwx-result="unknown"]')).toBeVisible();
  await expect(page.locator('[data-bwx-issued-key]')).toHaveCount(0);
});

test('a stranger can neither see the screen nor use its actions', async ({ page, request }) => {
  await page.goto(SCREEN);
  await expect(page).toHaveURL(/wp-login\.php/);

  // The form's action, posted directly. WordPress answers an unauthenticated
  // admin-post with a redirect to the login screen; what must not happen is a
  // site being registered.
  const posted = await request.post('/wp-admin/admin-post.php', {
    form: { action: 'bwx_forge_register_site', name: 'Stranger Ltd', url: 'https://evil.test' },
    maxRedirects: 0,
  });

  expect(posted.status()).toBeGreaterThanOrEqual(300);
  expect(posted.status()).toBeLessThan(500);

  await signIn(page);
  await page.goto(SCREEN);
  await expect(page.locator('body')).not.toContainText('Stranger Ltd');
});
