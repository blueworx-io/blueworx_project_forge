import { test, expect } from '@playwright/test';
import { signIn } from '../helpers/sign-in.js';
import { signedIn, makeSite, makePerson, PASSWORD } from './helpers/forge.js';

// The People screen in the app (PR 3 of spec 2026-09-16): everyone on a card,
// with everywhere they work beneath their name. Add somebody new, add
// somebody who already has an account, edit them, give them an account, give
// them access to a client, hand out grants, end access, offboard, delete.
// Names carry a run id because the instance is reused between runs.
const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'admin';
const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;
const STAMP = RUN_ID.replace('-', '');

test.describe.configure({ mode: 'serial' });

let admin;
let where;
let person;
let made = 0;

/** A WordPress account nobody in Forge holds, made the way makePerson makes one. */
async function makeAccount(label) {
  const login = `${label}${STAMP}${made++}`;
  const email = `${login}@example.test`;

  const wp = await admin.api.request.post('/wp-json/wp/v2/users', {
    headers: admin.api.headers,
    data: { username: login, email, password: PASSWORD, roles: ['subscriber'] },
  });
  expect(wp.status(), await wp.text()).toBe(201);

  return { id: (await wp.json()).id, login, email };
}

test.beforeAll(async ({ browser, baseURL }) => {
  admin = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  where = await makeSite(admin.api, 'People app', RUN_ID);
  person = await makePerson(admin.api, where.client.id, 'staff', `staff${STAMP}`);
});

test.afterAll(async () => {
  await admin?.context.close();
});

test.beforeEach(async ({ page }) => {
  await signIn(page, ADMIN_USER, ADMIN_PASS);
  await page.goto('/blueworx-forge/');
  await page.getByTestId('bwx-screen-people').click();
  await expect(page.getByTestId('bwx-people')).toBeVisible({ timeout: 30_000 });
  await expect(page.getByTestId('bwx-people-count')).toBeVisible({ timeout: 30_000 });
});

/** The card for one person, by id. */
function cardFor(page, id) {
  return page.locator(`[data-testid="bwx-people-card"][data-person="${id}"]`);
}

/** The card for one person, by what it says. */
function cardSaying(page, text) {
  return page.getByTestId('bwx-people-card').filter({ hasText: text });
}

// ---- Task 3: cards, add, add from an account, edit ---------------------

test('the rail offers People under Team, and a person shows as a card with their client and role', async ({ page }) => {
  await expect(page.locator('h1')).toHaveText('People');

  const card = cardFor(page, person.id);
  await expect(card).toBeVisible();
  await expect(card).toContainText(`staff${STAMP}`);
  await expect(card.getByTestId('bwx-people-card-account')).toContainText(`Signs in as ${person.login}`);

  const row = card.getByTestId('bwx-people-memberships').locator('tbody tr');
  await expect(row).toHaveCount(1);
  await expect(row).toContainText(where.client.display_name);
  await expect(row).toContainText('Staff');
  await expect(row).toContainText('every site');
});

test('somebody new is added from a form, and gets an account to sign in with', async ({ page }) => {
  const name = `Newbie ${RUN_ID}`;

  await page.getByTestId('bwx-people-add').click();
  const form = page.getByTestId('bwx-people-form');
  await expect(form).toBeVisible();

  await form.getByTestId('bwx-people-form-name').fill(name);
  await form.getByTestId('bwx-people-form-email').fill(`newbie.${STAMP}@example.test`);
  await form.getByTestId('bwx-people-form-save').click();

  await expect(form).toBeHidden();
  const card = cardSaying(page, name);
  await expect(card).toBeVisible();
  await expect(card.getByTestId('bwx-people-card-account')).toContainText('Signs in as');
  await expect(card.getByTestId('bwx-people-memberships')).toContainText('No client access yet');
});

test('somebody who already has an account is added from it, and signs in as that account', async ({ page }) => {
  const account = await makeAccount('free');

  await page.getByTestId('bwx-people-add-from-account').click();
  const form = page.getByTestId('bwx-people-account-form');
  await expect(form).toBeVisible();

  await form.getByTestId('bwx-people-account-pick').selectOption(String(account.id));
  await form.getByTestId('bwx-people-account-save').click();

  await expect(form).toBeHidden();
  const card = cardSaying(page, account.login);
  await expect(card).toBeVisible();
  await expect(card.getByTestId('bwx-people-card-account')).toContainText(`Signs in as ${account.login}`);
});

test('a person is renamed in place, and an address somebody else holds is refused in the form', async ({ page }) => {
  const card = cardFor(page, person.id);

  await card.getByTestId('bwx-people-edit').click();
  const form = page.getByTestId('bwx-people-edit-form');
  await expect(form).toBeVisible();
  await expect(form.getByTestId('bwx-people-edit-name')).toHaveValue(`staff${STAMP}`);

  await form.getByTestId('bwx-people-edit-name').fill(`Renamed ${RUN_ID}`);
  await form.getByTestId('bwx-people-edit-save').click();

  await expect(form).toBeHidden();
  await expect(card).toContainText(`Renamed ${RUN_ID}`);

  // The address the second test gave somebody else.
  await card.getByTestId('bwx-people-edit').click();
  await form.getByTestId('bwx-people-edit-email').fill(`newbie.${STAMP}@example.test`);
  await form.getByTestId('bwx-people-edit-save').click();

  await expect(form.getByTestId('bwx-people-edit-notice')).toContainText('already has that email');
  await form.getByTestId('bwx-people-edit-cancel').click();
  await expect(form).toBeHidden();
});

test('a link with the screen and person in the hash lands on them, with their panel open', async ({ page }) => {
  await page.goto(`/blueworx-forge/#screen=people&person=${person.id}`);
  // The beforeEach opened the app without a hash, so this is a fragment
  // change rather than a navigation; a link from outside arrives as a fresh
  // load, so force one to match.
  await page.reload();

  await expect(page.getByTestId('bwx-people')).toBeVisible({ timeout: 30_000 });
  const form = page.getByTestId('bwx-people-edit-form');
  await expect(form).toBeVisible({ timeout: 30_000 });
  await expect(form.getByTestId('bwx-people-edit-name')).toHaveValue(`Renamed ${RUN_ID}`);
  // Read once and cleared, so a reload is a plain reload.
  expect(new URL(page.url()).hash).toBe('');
});
