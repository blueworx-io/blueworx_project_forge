import { test, expect } from '@playwright/test';

// #160 walked as the studio walks it: publish a checklist, give it to a site,
// and watch it become that site's own — fixed at the version they were given.
//
// Nothing is ever deleted and the instance is kept between runs, so every name
// carries a run id or the spec passes once and fails for ever after.

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'wptest-admin-pw';

const TEMPLATE = '/wp-admin/admin.php?page=blueworx-forge-onboarding-template';
const CLIENTS = '/wp-admin/admin.php?page=blueworx-forge-clients';

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;

async function signIn(page) {
  await page.goto('/wp-login.php');
  await page.fill('#user_login', ADMIN_USER);
  await page.fill('#user_pass', ADMIN_PASS);
  await page.click('#wp-submit');
  await page.waitForURL((url) => !url.pathname.endsWith('/wp-login.php'));
}

/** Makes sure there is a published checklist with at least one step in it. */
async function publishAChecklist(page) {
  await page.goto(TEMPLATE);

  const start = page.locator('[data-bwx-start-draft="1"]');

  if (await start.count()) {
    await page.fill('#bwx-template-name', `Assignable ${RUN_ID}`);
    await start.locator('input[type="submit"]').click();
  } else {
    await page.locator('[data-bwx-copy-template="1"] input[type="submit"]').first().click();
  }

  await page.fill('#bwx-step-title', `Delegate the registrar ${RUN_ID}`);
  await page.check('#bwx-step-launch-critical');
  await page.locator('[data-bwx-add-step="1"] input[type="submit"]').click();
  await expect(page.locator('[data-bwx-result="step-added"]')).toBeVisible();

  await page.locator('[data-bwx-publish-template="1"] input[type="submit"]').click();
  await expect(page.locator('[data-bwx-result="published"]')).toBeVisible();
}

/** Adds a client with one site, and returns the site's id. */
async function makeClientWithSite(page, suffix) {
  const clientName = `Onboarding client ${RUN_ID}${suffix}`;
  const siteName = `Onboarding site ${RUN_ID}${suffix}`;

  await page.goto(CLIENTS);
  await page.fill('#bwx-client-name', clientName);
  await page.locator('form[data-bwx-add-client] input[type="submit"]').click();
  await expect(page.locator('[data-bwx-notice="added"]')).toBeVisible();

  const client = page.locator(`li[data-bwx-client]:has([data-bwx-client-name]:text-is("${clientName}"))`).first();

  await client.locator('form[data-bwx-add-site] input[name="name"]').fill(siteName);
  await client.locator('form[data-bwx-add-site] input[type="submit"]').click();
  await expect(page.locator('[data-bwx-notice="added"]')).toBeVisible();

  const site = page
    .locator(`li[data-bwx-site]:has([data-bwx-site-name]:text-is("${siteName}"))`)
    .first();

  return site.getAttribute('data-bwx-site');
}

test('a site is given the checklist once, and it is theirs from then on', async ({ page }) => {
  test.setTimeout(180_000);

  await signIn(page);
  await publishAChecklist(page);

  const siteId = await makeClientWithSite(page, "a");

  expect(siteId, 'the site was created').toBeTruthy();

  // Before: offered the current version.
  const assign = page.locator(`li[data-bwx-site="${siteId}"] [data-bwx-assign-onboarding="1"]`);

  await expect(assign).toBeVisible();

  page.once('dialog', (dialog) => dialog.accept());
  await assign.locator('button').click();
  await expect(page.locator('[data-bwx-notice="onboarding-started"]')).toBeVisible();

  // After: it says where they are, and offers no way to give them another.
  const state = page.locator(`li[data-bwx-site="${siteId}"] [data-bwx-onboarding="${siteId}"]`);

  await expect(state).toBeVisible();
  await expect(state).toContainText('% done');

  await expect(
    page.locator(`li[data-bwx-site="${siteId}"] [data-bwx-assign-onboarding="1"]`)
  ).toHaveCount(0);
});

test('a brand new checklist is nought per cent done and not ready to launch', async ({ page }) => {
  test.setTimeout(180_000);

  await signIn(page);
  await publishAChecklist(page);

  const siteId = await makeClientWithSite(page, "b");
  const assign = page.locator(`li[data-bwx-site="${siteId}"] [data-bwx-assign-onboarding="1"]`);

  page.once('dialog', (dialog) => dialog.accept());
  await assign.locator('button').click();
  await expect(page.locator('[data-bwx-notice="onboarding-started"]')).toBeVisible();

  const state = page.locator(`li[data-bwx-site="${siteId}"] [data-bwx-onboarding="${siteId}"]`);

  // Nought of nought is nought, not a hundred — a checklist nobody has started
  // must not read as finished.
  await expect(state).toContainText('0% done');
  await expect(state).toHaveAttribute('data-bwx-onboarding-ready', 'no');

  // And the launch-critical step is named as standing in the way.
  await expect(
    page.locator(`li[data-bwx-site="${siteId}"] [data-bwx-onboarding-blocking]`)
  ).toContainText('still needed to launch');
});
