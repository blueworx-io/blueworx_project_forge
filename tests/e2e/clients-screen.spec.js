import { test, expect } from '@playwright/test';

// Adding a client should be an administrator's job in a browser, not a
// developer's job with a signed API call — the same reason the sites screen
// exists. Walked as a person walks it, because that is where the failures are.
//
// Nothing here is ever deleted, and the WordPress instance these specs run
// against is shared and kept between runs — so every created record carries
// a name unique to this run. A hardcoded name collides with whatever an
// earlier run left behind and breaks the exact-match locators below.

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'wptest-admin-pw';

const SCREEN = '/wp-admin/admin.php?page=blueworx-forge-clients';

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;

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

async function addSite(page, clientId, name) {
  await page.fill(`[data-bwx-client="${clientId}"] input[name="name"]`, name);
  await page.click(`[data-bwx-client="${clientId}"] form[data-bwx-add-site] input[type="submit"]`);
  await expect(page.locator(`[data-bwx-site-name]:text-is("${name}")`)).toBeVisible();
}

test('an administrator can add a client with two sites', async ({ page }) => {
  await signIn(page);
  const clientId = await addClient(page, `Acme Ltd ${RUN_ID}`);

  for (const site of [`Acme Main ${RUN_ID}`, `Acme Shop ${RUN_ID}`]) {
    await addSite(page, clientId, site);
  }

  const sites = page.locator(`[data-bwx-client="${clientId}"] [data-bwx-site]`);
  await expect(sites).toHaveCount(2);
});

test('deactivating a client hides it and its sites from the default list', async ({ page }) => {
  await signIn(page);
  const clientId = await addClient(page, `Closing Ltd ${RUN_ID}`);
  await addSite(page, clientId, `Closing Main ${RUN_ID}`);

  page.once('dialog', (dialog) => dialog.accept());
  await page.click(`[data-bwx-client="${clientId}"] [data-bwx-deactivate-client]`);

  await expect(page.locator(`[data-bwx-client="${clientId}"]`)).toHaveCount(0);

  await page.goto(`${SCREEN}&status=all`);
  // The client's own status, not the site's nested one directly below it.
  await expect(page.locator(`[data-bwx-client="${clientId}"] > [data-bwx-status]`)).toContainText(
    'Inactive',
  );
  // Deactivating a client deactivates every site under it too.
  await expect(
    page.locator(`[data-bwx-client="${clientId}"] [data-bwx-site] [data-bwx-status]`),
  ).toContainText('Inactive');
});

test('the screen is not reachable without the capability', async ({ page }) => {
  await page.goto(SCREEN);
  await expect(page.locator('body')).not.toContainText('Add a client');
});
