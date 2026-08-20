import { test, expect } from '@playwright/test';

// #90 walked as a person walks it. Adding somebody and giving them access
// should be an administrator's job in a browser, not a signed API call.
//
// Every name and address carries this run with it: nothing is ever deleted, the
// instance is kept between runs, and an email address is unique — so a
// hardcoded one passes once and fails for ever after.

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'wptest-admin-pw';

const PEOPLE = '/wp-admin/admin.php?page=blueworx-forge-people';
const CLIENTS = '/wp-admin/admin.php?page=blueworx-forge-clients';

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;

async function signIn(page) {
  await page.goto('/wp-login.php');
  await page.fill('#user_login', ADMIN_USER);
  await page.fill('#user_pass', ADMIN_PASS);
  await page.click('#wp-submit');
  await page.waitForURL((url) => !url.pathname.endsWith('/wp-login.php'));
}

async function addPerson(page, name) {
  await page.goto(PEOPLE);
  await page.fill('#bwx-person-name', name);
  await page.fill('#bwx-person-email', `${name.toLowerCase().replace(/[^a-z0-9]+/g, '.')}@example.test`);
  await page.click('form[data-bwx-add-person] input[type="submit"]');
  await expect(page.locator(`[data-bwx-person-name]:text-is("${name}")`)).toBeVisible();
}

async function addClient(page, name) {
  await page.goto(CLIENTS);
  await page.fill('#bwx-client-name', name);
  await page.click('form[data-bwx-add-client] input[type="submit"]');
  await expect(page.locator(`[data-bwx-client-name]:text-is("${name}")`)).toBeVisible();

  return page
    .locator(`[data-bwx-client]:has([data-bwx-client-name]:text-is("${name}"))`)
    .getAttribute('data-bwx-client');
}

async function giveAccess(page, clientId, personName, role) {
  const form = page.locator(`[data-bwx-client="${clientId}"] form[data-bwx-add-membership]`);

  await form.locator('select[name="user_id"]').selectOption({ label: personName });
  await form.locator('select[name="role"]').selectOption(role);
  await form.locator('input[type="submit"]').click();
}

test('an administrator can add a person and give them access to a client', async ({ page }) => {
  await signIn(page);

  const name = `Sam Patel ${RUN_ID}`;
  await addPerson(page, name);

  const clientId = await addClient(page, `Access Co ${RUN_ID}`);
  await giveAccess(page, clientId, name, 'staff');

  const membership = page.locator(`[data-bwx-client="${clientId}"] [data-bwx-membership]`);
  await expect(membership).toHaveCount(1);
  await expect(membership.locator('[data-bwx-membership-person]')).toHaveText(name);
  await expect(membership).toHaveAttribute('data-bwx-membership-role', 'staff');
});

test('the same person holds two different roles on two clients, as one person', async ({ page }) => {
  await signIn(page);

  const name = `Alex Two Hats ${RUN_ID}`;
  await addPerson(page, name);

  const first = await addClient(page, `Hat One ${RUN_ID}`);
  await giveAccess(page, first, name, 'staff');

  const second = await addClient(page, `Hat Two ${RUN_ID}`);
  await giveAccess(page, second, name, 'internal_viewer');

  await page.goto(PEOPLE);

  // One row for the person, whatever number of clients they work with — this
  // is what AUTH-6 is for, and the screen is where a duplicate would show.
  const person = page.locator(`[data-bwx-person]:has([data-bwx-person-name]:text-is("${name}"))`);
  await expect(person).toHaveCount(1);

  const held = person.locator('[data-bwx-membership]');
  await expect(held).toHaveCount(2);
  await expect(person.locator('[data-bwx-membership-role-label]').first()).toBeVisible();
});

test('offboarding somebody ends their access everywhere, and keeps the record', async ({ page }) => {
  await signIn(page);

  const name = `Leaver ${RUN_ID}`;
  await addPerson(page, name);

  const clientId = await addClient(page, `Leaving Co ${RUN_ID}`);
  await giveAccess(page, clientId, name, 'staff');

  await page.goto(PEOPLE);
  const person = () =>
    page.locator(`[data-bwx-person]:has([data-bwx-person-name]:text-is("${name}"))`);

  page.once('dialog', (dialog) => dialog.accept());
  await person().locator('[data-bwx-offboard]').click();

  // Gone from the default view along with their access...
  await expect(person()).toHaveCount(0);

  // ...and still there, with the access marked ended rather than deleted, so
  // everything they ever did still resolves.
  await page.goto(`${PEOPLE}&status=all`);
  await expect(person()).toHaveCount(1);
  await expect(person().locator('[data-bwx-membership] [data-bwx-status]').first()).toHaveText(
    'Ended',
  );
});

test('the people screen is not reachable without the capability', async ({ browser, baseURL }) => {
  const context = await browser.newContext({ baseURL });
  const page = await context.newPage();

  await page.goto(PEOPLE);

  // A logged-out visitor is sent to the login screen rather than shown the list.
  await expect(page.locator('[data-bwx-people]')).toHaveCount(0);
  await expect(page.locator('form[data-bwx-add-person]')).toHaveCount(0);

  await context.close();
});
