import { test, expect } from '@playwright/test';
import { signedIn, makeSite, makeItem } from './helpers/forge.js';

// #292. A person is somebody who can sign in.
//
// What is proved here is that the two sides agree: what Forge does to a person
// is visible on the WordPress Users screen, and what WordPress does to the
// account comes back to the person. Forge's side is reached over REST — the
// routes the People screen in the app is drawn from — and WordPress's side is
// walked on its own admin screens, because a deletion that skips WordPress's
// nonce is not the one an administrator performs.
//
// Every name and address carries this run with it. The instance is kept between
// runs and nothing is deleted, so a hardcoded address passes once and fails for
// ever after.

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'admin';

const BASE = '/wp-json/blueworx-forge/v1';

const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;

/** A fresh login name, unique to this run. */
function loginName(stem) {
  return `${stem}${Date.now()}${Math.floor(Math.random() * 1000)}`;
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
async function addNewPerson(api, name, email) {
  const added = await api.post('/users', { display_name: name, email, make_account: true });
  expect(added.status(), await added.text()).toBe(200);

  return (await added.json()).user;
}

/** One person, read back as Forge has them. */
async function readPerson(api, id) {
  const answer = await api.get(`/users/${id}`);
  expect(answer.ok, JSON.stringify(answer)).toBe(true);

  return answer.user;
}

/** The person as the list shows them — active only, or everybody. */
async function listed(api, id, everyone = false) {
  const answer = await api.get(everyone ? '/users?status=all' : '/users');

  return answer.users.find((one) => one.id === id) ?? null;
}

/** The card for one person on the app's People screen. */
function cardFor(page, id) {
  return page.locator(`[data-testid="bwx-people-card"][data-person="${id}"]`);
}

test('adding somebody new gives them the WordPress account they sign in with', async ({
  browser,
  baseURL,
}) => {
  const { context, nonce, api } = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);

  const name = `Nadia Okonjo ${RUN_ID}`;
  const email = `nadia.${RUN_ID}@example.test`;

  const person = await addNewPerson(api, name, email);

  // The answer says who they are on WordPress, so the screen can show the
  // account exists without a second read.
  expect(person.account).toBeTruthy();
  expect(person.account.login).toBeTruthy();

  const wpUserId = person.wp_user_id;
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
  const { context, nonce, api } = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);

  const login = loginName('picked');
  const created = await makeAccount(context.request, nonce, login);

  // Offered, because nobody in Forge holds the account yet.
  const before = await api.get('/accounts');
  expect(before.accounts.map((one) => one.id)).toContain(created.id);

  const added = await api.post('/users/from-account', { wp_user_id: created.id });
  expect(added.status(), await added.text()).toBe(200);

  const person = (await added.json()).user;
  expect(person.display_name).toBe(`Existing ${login}`);
  expect(person.email).toBe(`${login}@example.test`);
  expect(person.wp_user_id).toBe(created.id);

  // And they are gone from the list of accounts to pick, because they are
  // somebody now. Two people on one account would make whoever signed in
  // resolve to whichever row was found first.
  const after = await api.get('/accounts');
  expect(after.accounts.map((one) => one.id)).not.toContain(created.id);

  await context.close();
});

test('editing a person in Forge writes the change to their WordPress account', async ({
  browser,
  baseURL,
}) => {
  const { context, nonce, api } = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);

  const name = `Priya Raman ${RUN_ID}`;
  const person = await addNewPerson(api, name, `priya.${RUN_ID}@example.test`);

  const wpUserId = person.wp_user_id;
  const moved = `priya.moved.${RUN_ID}@example.test`;

  const edited = await api.patch(`/users/${person.id}`, {
    email: moved,
    record_version: person.record_version,
  });
  expect(edited.status(), await edited.text()).toBe(200);

  expect((await readPerson(api, person.id)).email).toBe(moved);

  const account = await readAccount(context.request, nonce, wpUserId);
  expect(account.email).toBe(moved);

  await context.close();
});

test('editing the WordPress account writes the change back to the person', async ({
  browser,
  baseURL,
}) => {
  const { context, api } = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const page = await context.newPage();

  const name = `Tomas Lindqvist ${RUN_ID}`;
  const person = await addNewPerson(api, name, `tomas.${RUN_ID}@example.test`);

  const wpUserId = person.wp_user_id;
  const moved = `tomas.moved.${RUN_ID}@example.test`;

  await page.goto(`/wp-admin/user-edit.php?user_id=${wpUserId}`);
  await page.fill('#email', moved);
  await page.click('#submit');

  // Read back where an administrator would look: the People screen in the app.
  await page.goto('/blueworx-forge/#screen=people');
  await expect(page.getByTestId('bwx-people')).toBeVisible({ timeout: 30_000 });
  // The address lives on the edit form now, not on the card.
  await cardFor(page, person.id).getByTestId('bwx-people-edit').click();
  await expect(page.getByTestId('bwx-people-edit-email')).toHaveValue(moved);

  await context.close();
});

test('deleting the WordPress user offboards the person and ends their access', async ({
  browser,
  baseURL,
}) => {
  const { context, api } = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);
  const page = await context.newPage();

  const name = `Grace Adeyemi ${RUN_ID}`;
  const email = `grace.${RUN_ID}@example.test`;
  const person = await addNewPerson(api, name, email);

  const wpUserId = person.wp_user_id;

  // Followed from the Users screen rather than built by hand: the link carries
  // WordPress's own nonce, and a deletion that skips it is not the one an
  // administrator performs. Searched for, because the instance is kept between
  // runs and the list is paged — on a week-old one they are nowhere near page one.
  await page.goto(`/wp-admin/users.php?s=${encodeURIComponent(email)}`);
  const deleteLink = await page
    .locator(`#user-${wpUserId} a.submitdelete`)
    .getAttribute('href');
  expect(deleteLink, 'no delete link for the account').toBeTruthy();

  await page.goto(new URL(deleteLink, page.url()).toString());
  await page.getByRole('button', { name: 'Confirm Deletion' }).click();

  // Gone from the active view entirely, and offboarded in the one that shows
  // everybody. Their history stays; their access does not.
  expect(await listed(api, person.id)).toBeNull();

  const card = await listed(api, person.id, true);
  expect(card).toBeTruthy();
  expect(card.status).toBe('inactive');

  // And the link is dropped, so an id WordPress hands out again later cannot
  // pick up somebody else's identity.
  expect(card.wp_user_id).toBe(0);

  await context.close();
});

test('somebody offboarded before they had an account can be brought back', async ({
  browser,
  baseURL,
}) => {
  const { context, nonce, api } = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);

  // Added the way people were before #292: a record with no account behind it.
  // Then the address gets an account anyway, as it would when they sign up on
  // the site themselves — and that account is theirs, not a clash.
  const login = loginName('returner');
  const email = `${login}@example.test`;
  const name = `Returner ${login}`;

  const created = await api.post('/users', { display_name: name, email });
  expect(created.status(), await created.text()).toBe(200);
  const person = (await created.json()).user;

  await makeAccount(context.request, nonce, login);

  const gone = await api.post(`/users/${person.id}/offboard`, { record_version: person.record_version });
  expect(gone.status(), await gone.text()).toBe(200);
  const offboarded = (await gone.json()).user;

  const back = await api.patch(`/users/${person.id}`, {
    status: 'active',
    record_version: offboarded.record_version,
  });
  expect(back.status(), await back.text()).toBe(200);
  expect((await back.json()).user.status).toBe('active');

  expect((await readPerson(api, person.id)).status).toBe('active');

  await context.close();
});

test('deleting an offboarded person removes them from Forge and keeps their WordPress account', async ({
  browser,
  baseURL,
}) => {
  const { context, nonce, api } = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);

  const name = `Gone Entirely ${RUN_ID}`;
  const person = await addNewPerson(api, name, `gone.${RUN_ID}@example.test`);

  const wpUserId = person.wp_user_id;

  // Nobody active can be deleted: offboarding is the step that ends access,
  // and deletion is only offered once that has happened.
  const early = await api.request.delete(`${BASE}/users/${person.id}`, { headers: api.headers });
  expect(early.status()).toBe(400);
  expect((await early.json()).code).toBe('bwx_forge_person_active');

  const gone = await api.post(`/users/${person.id}/offboard`, { record_version: person.record_version });
  expect(gone.status(), await gone.text()).toBe(200);

  const removed = await api.request.delete(`${BASE}/users/${person.id}`, { headers: api.headers });
  expect(removed.status(), await removed.text()).toBe(200);
  expect(await removed.json()).toEqual({ ok: true, deleted: person.id });

  expect(await listed(api, person.id, true)).toBeNull();

  // Forge let go of the record. WordPress did not: the account is theirs, and
  // everything they wrote is attributed to it.
  const account = await readAccount(context.request, nonce, wpUserId);
  expect(account.id).toBe(wpUserId);

  await context.close();
});

test('somebody with work attributed to them cannot be deleted, only offboarded', async ({
  browser,
  baseURL,
}) => {
  const { context, api } = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS);

  const name = `Kept On Record ${RUN_ID}`;
  const person = await addNewPerson(api, name, `kept.${RUN_ID}@example.test`);

  // Work with their name on it. Once that exists, removing the row would leave
  // the work pointing at nobody (NOTIF-5).
  const { client, site } = await makeSite(api, 'Record Co', RUN_ID);
  // Only somebody who reaches the client can hold a seat on its work (#393).
  const member = await api.post(`/clients/${client.id}/memberships`, { user_id: person.id, role: 'staff' });
  expect(member.status(), await member.text()).toBe(200);
  const made = await makeItem(api, site.id, { title: `Their work ${RUN_ID}` });
  expect(made.status(), await made.text()).toBe(200);
  const { item } = await made.json();

  const assigned = await api.patch(`/work-items/${item.id}`, {
    primary_user_id: person.id,
    record_version: item.record_version,
  });
  expect(assigned.status(), await assigned.text()).toBe(200);

  const gone = await api.post(`/users/${person.id}/offboard`, { record_version: person.record_version });
  expect(gone.status(), await gone.text()).toBe(200);
  expect((await gone.json()).user.status).toBe('inactive');

  const refused = await api.request.delete(`${BASE}/users/${person.id}`, { headers: api.headers });
  expect(refused.status()).toBe(400);
  expect((await refused.json()).code).toBe('bwx_forge_person_has_history');

  await context.close();
});
