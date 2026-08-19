import { test, expect } from '@playwright/test';

// Adding a client should be an administrator's job in a browser, not a
// developer's job with a signed API call — the same reason the sites screen
// exists. Walked as a person walks it, because that is where the failures are.

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'wptest-admin-pw';

const SCREEN = '/wp-admin/admin.php?page=blueworx-forge-clients';

async function signIn(page) {
  await page.goto('/wp-login.php');
  await page.fill('#user_login', ADMIN_USER);
  await page.fill('#user_pass', ADMIN_PASS);
  await page.click('#wp-submit');
  await page.waitForURL((url) => !url.pathname.endsWith('/wp-login.php'));
}

async function addClient(page, name) {
  await page.goto(SCREEN);
  await page.fill('#bwx-client-name', name);
  await page.click('form[data-bwx-add-client] input[type="submit"]');
  await expect(page.locator(`[data-bwx-client-name]:text-is("${name}")`)).toBeVisible();

  return page
    .locator(`[data-bwx-client]:has([data-bwx-client-name]:text-is("${name}"))`)
    .getAttribute('data-bwx-client');
}

test('an administrator can add a client with two sites', async ({ page }) => {
  await signIn(page);
  const clientId = await addClient(page, 'Acme Ltd');

  for (const site of ['Acme Main', 'Acme Shop']) {
    await page.fill(`[data-bwx-client="${clientId}"] input[name="name"]`, site);
    await page.click(`[data-bwx-client="${clientId}"] form[data-bwx-add-site] input[type="submit"]`);
    await expect(page.locator(`[data-bwx-site-name]:text-is("${site}")`)).toBeVisible();
  }

  const sites = page.locator(`[data-bwx-client="${clientId}"] [data-bwx-site]`);
  await expect(sites).toHaveCount(2);
});

test('deactivating a client hides it and its sites from the default list', async ({ page }) => {
  await signIn(page);
  const clientId = await addClient(page, 'Closing Ltd');

  page.once('dialog', (dialog) => dialog.accept());
  await page.click(`[data-bwx-client="${clientId}"] [data-bwx-deactivate-client]`);

  await expect(page.locator(`[data-bwx-client="${clientId}"]`)).toHaveCount(0);

  await page.goto(`${SCREEN}&status=all`);
  await expect(page.locator(`[data-bwx-client="${clientId}"] [data-bwx-status]`)).toContainText(
    'Inactive',
  );
});

test('the screen is not reachable without the capability', async ({ page }) => {
  await page.goto(SCREEN);
  await expect(page.locator('body')).not.toContainText('Add a client');
});
