import { test, expect } from '@playwright/test';
import { signedIn } from './helpers/forge.js';

// #292. A person is somebody who can sign in.
//
// Walked as an administrator walks it, because the whole point of the change is
// that the two screens agree: what happens on the People screen is visible on
// the WordPress Users screen, and the other way round.
//
// Every name and address carries this run with it. The instance is kept between
// runs and nothing is deleted, so a hardcoded address passes once and fails for
// ever after.

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'wptest-admin-pw';

const PEOPLE = '/wp-admin/admin.php?page=blueworx-forge-people';
const PEOPLE_ALL = `${PEOPLE}&status=all`;

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;

/** A fresh login name, unique to this run. */
function loginName(stem) {
  return `${stem}${Date.now()}${Math.floor(Math.random() * 1000)}`;
}

/** The card for one person, found by the name on it. */
function cardFor(page, name) {
  return page.locator(`[data-bwx-person]:has([data-bwx-person-name]:text-is("${name}"))`);
}

/** A WordPress account nobody in Forge holds yet. */
async function makeAccount(request, nonce, login) {
  const response = await request.post('/wp-json/wp/v2/users', {
    headers: { 'X-WP-Nonce': nonce },
    data: {
      username: login,
      name: `Existing ${login}`,
      email: `${login}@example.test`,
      password: 'forge-test-pw-4471',
      roles: ['subscriber'],
    },
  });

  expect(response.status(), await response.text()).toBe(201);

  return response.json();
}

/** One WordPress account, read back as WordPress has it. */
async function readAccount(request, nonce, id) {
  const response = await request.get(`/wp-json/wp/v2/users/${id}?context=edit`, {
    headers: { 'X-WP-Nonce': nonce },
  });

  expect(response.status(), await response.text()).toBe(200);

  return response.json();
}

/** Adds somebody who has no WordPress account yet, so Forge makes them one. */
async function addNewPerson(page, name, email) {
  await page.goto(PEOPLE);
  await page.fill('#bwx-person-name', name);
  await page.fill('#bwx-person-email', email);
  await page.click('form[data-bwx-add-person] input[type="submit"]');
  await expect(cardFor(page, name)).toBeVisible();
}

test('adding somebody new gives them the WordPress account they sign in with', async ({
  browser,
  baseURL,
}) => {
  const { context, nonce } = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const page = await context.newPage();

  const name = `Nadia Okonjo ${RUN_ID}`;
  const email = `nadia.${RUN_ID}@example.test`;

  await addNewPerson(page, name, email);

  // The screen says who they are on WordPress, so an administrator can see the
  // account exists without leaving the page.
  const account = cardFor(page, name).locator('[data-bwx-person-account]');
  await expect(account).toBeVisible();

  const wpUserId = await cardFor(page, name).getAttribute('data-bwx-wp-user');
  expect(Number(wpUserId)).toBeGreaterThan(0);

  const created = await readAccount(context.request, nonce, wpUserId);
  expect(created.email).toBe(email);
  expect(created.roles).toEqual(['subscriber']);

  await context.close();
});

test('somebody who already has a WordPress account is added by picking them', async ({
  browser,
  baseURL,
}) => {
  const { context, nonce } = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const page = await context.newPage();

  const login = loginName('picked');
  const created = await makeAccount(context.request, nonce, login);

  await page.goto(PEOPLE);

  const form = page.locator('form[data-bwx-add-person-from-wp]');
  await form.locator('select[name="wp_user_id"]').selectOption(String(created.id));
  await form.locator('input[type="submit"]').click();

  const card = cardFor(page, `Existing ${login}`);
  await expect(card).toBeVisible();
  await expect(card.locator('[data-bwx-person-email]')).toHaveText(`${login}@example.test`);
  await expect(card).toHaveAttribute('data-bwx-wp-user', String(created.id));

  // And they are gone from the list of accounts to pick, because they are
  // somebody now. Two people on one account would make whoever signed in
  // resolve to whichever row was found first.
  await expect(
    form.locator(`select[name="wp_user_id"] option[value="${created.id}"]`)
  ).toHaveCount(0);

  await context.close();
});

test('editing a person in Forge writes the change to their WordPress account', async ({
  browser,
  baseURL,
}) => {
  const { context, nonce } = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const page = await context.newPage();

  const name = `Priya Raman ${RUN_ID}`;
  await addNewPerson(page, name, `priya.${RUN_ID}@example.test`);

  const card = cardFor(page, name);
  const wpUserId = await card.getAttribute('data-bwx-wp-user');
  const moved = `priya.moved.${RUN_ID}@example.test`;

  await card.locator('[data-bwx-edit-person] summary').click();
  await card.locator('[data-bwx-edit-person] input[name="email"]').fill(moved);
  await card.locator('[data-bwx-edit-person] input[type="submit"]').click();

  await expect(cardFor(page, name).locator('[data-bwx-person-email]')).toHaveText(moved);

  const account = await readAccount(context.request, nonce, wpUserId);
  expect(account.email).toBe(moved);

  await context.close();
});

test('editing the WordPress account writes the change back to the person', async ({
  browser,
  baseURL,
}) => {
  const { context } = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const page = await context.newPage();

  const name = `Tomas Lindqvist ${RUN_ID}`;
  await addNewPerson(page, name, `tomas.${RUN_ID}@example.test`);

  const wpUserId = await cardFor(page, name).getAttribute('data-bwx-wp-user');
  const moved = `tomas.moved.${RUN_ID}@example.test`;

  await page.goto(`/wp-admin/user-edit.php?user_id=${wpUserId}`);
  await page.fill('#email', moved);
  await page.click('#submit');

  await page.goto(PEOPLE);
  await expect(cardFor(page, name).locator('[data-bwx-person-email]')).toHaveText(moved);

  await context.close();
});

test('deleting the WordPress user offboards the person and ends their access', async ({
  browser,
  baseURL,
}) => {
  const { context } = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const page = await context.newPage();

  const name = `Grace Adeyemi ${RUN_ID}`;
  await addNewPerson(page, name, `grace.${RUN_ID}@example.test`);

  const wpUserId = await cardFor(page, name).getAttribute('data-bwx-wp-user');

  // Followed from the Users screen rather than built by hand: the link carries
  // WordPress's own nonce, and a deletion that skips it is not the one an
  // administrator performs.
  await page.goto('/wp-admin/users.php');
  const deleteLink = await page
    .locator(`#user-${wpUserId} a.submitdelete`)
    .getAttribute('href');
  expect(deleteLink, 'no delete link for the account').toBeTruthy();

  await page.goto(new URL(deleteLink, page.url()).toString());
  await page.getByRole('button', { name: 'Confirm Deletion' }).click();

  // Gone from the active view entirely, and offboarded in the one that shows
  // everybody. Their history stays; their access does not.
  await page.goto(PEOPLE);
  await expect(cardFor(page, name)).toHaveCount(0);

  await page.goto(PEOPLE_ALL);
  const card = cardFor(page, name);
  await expect(card).toBeVisible();
  await expect(card.locator('[data-bwx-status]')).toHaveText('Offboarded');

  // And the link is dropped, so an id WordPress hands out again later cannot
  // pick up somebody else's identity.
  await expect(card).toHaveAttribute('data-bwx-wp-user', '0');

  await context.close();
});
