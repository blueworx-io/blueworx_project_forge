import { test, expect } from '@playwright/test';
import { signIn } from '../helpers/sign-in.js';
import { signedIn, makeSite, makePerson, makeItem, PASSWORD } from './helpers/forge.js';

// #90 against a real WordPress. The unit tests prove the rules; these prove the
// thing the issue is actually about — that one person can work with two clients
// as two different things and still be one person.

// Nothing here is ever deleted and the instance is kept between runs, so every
// address and name carries the run with it. A suite that only passes against a
// freshly wiped database is a suite that will fail on its own leftovers — and
// an email address is unique, so a hardcoded one fails on the second run.
const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;

async function signedInContext(browser, baseURL) {
  const context = await browser.newContext({ baseURL });
  const page = await context.newPage();

  await signIn(page);

  await page.goto('/blueworx-forge/');
  const nonce = await page.evaluate(() => window.bwxForgeData?.nonce);
  expect(nonce, 'no REST nonce was localised for the signed-in user').toBeTruthy();

  await page.close();
  return { context, nonce };
}

async function addPerson(request, nonce, label, domains = null) {
  const response = await request.post('/wp-json/blueworx-forge/v1/users', {
    headers: { 'X-WP-Nonce': nonce },
    data: {
      display_name: `${label} ${RUN_ID}`,
      email: `${label.toLowerCase().replace(/\s+/g, '.')}.${RUN_ID}@${domains ?? 'example.test'}`,
    },
  });

  expect(response.status()).toBe(200);
  return (await response.json()).user;
}

async function addClient(request, nonce, label, domains = []) {
  const response = await request.post('/wp-json/blueworx-forge/v1/clients', {
    headers: { 'X-WP-Nonce': nonce },
    data: {
      display_name: `${label} ${RUN_ID}`,
      timezone: 'Europe/London',
      email_domains: domains,
    },
  });

  expect(response.status()).toBe(200);
  return (await response.json()).client;
}

async function addSite(request, nonce, clientId, label) {
  const response = await request.post(`/wp-json/blueworx-forge/v1/clients/${clientId}/sites`, {
    headers: { 'X-WP-Nonce': nonce },
    data: { name: `${label} ${RUN_ID}`, url: 'https://example.test' },
  });

  expect(response.status()).toBe(200);
  return (await response.json()).site;
}

function grant(request, nonce, clientId, data) {
  return request.post(`/wp-json/blueworx-forge/v1/clients/${clientId}/memberships`, {
    headers: { 'X-WP-Nonce': nonce },
    data,
  });
}

test('a stranger cannot read or create people', async ({ request }) => {
  expect([401, 403]).toContain((await request.get('/wp-json/blueworx-forge/v1/users')).status());

  const created = await request.post('/wp-json/blueworx-forge/v1/users', {
    data: { display_name: 'Trespass', email: 'trespass@example.test' },
  });
  expect([401, 403]).toContain(created.status());
});

test('one person holds two different roles on two clients', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const person = await addPerson(context.request, nonce, 'Sam Patel');
  const one = await addClient(context.request, nonce, 'First Client');
  const two = await addClient(context.request, nonce, 'Second Client');

  expect((await grant(context.request, nonce, one.id, { user_id: person.id, role: 'staff' })).status()).toBe(200);
  expect(
    (await grant(context.request, nonce, two.id, { user_id: person.id, role: 'internal_viewer' })).status(),
  ).toBe(200);

  const held = await (
    await context.request.get(`/wp-json/blueworx-forge/v1/users/${person.id}/memberships`, {
      headers: { 'X-WP-Nonce': nonce },
    })
  ).json();

  expect(held.memberships).toHaveLength(2);
  expect(held.memberships.map((m) => m.role).sort()).toEqual(['internal_viewer', 'staff']);

  // And still one person. This is the whole issue: a per-client account model
  // would have made two, and capacity would count them at half load each.
  const everyone = await (
    await context.request.get('/wp-json/blueworx-forge/v1/users', {
      headers: { 'X-WP-Nonce': nonce },
    })
  ).json();

  expect(everyone.users.filter((u) => u.email === person.email)).toHaveLength(1);

  await context.close();
});

test('a second person cannot be created at the same address', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const person = await addPerson(context.request, nonce, 'Twice Over');

  const again = await context.request.post('/wp-json/blueworx-forge/v1/users', {
    headers: { 'X-WP-Nonce': nonce },
    data: { display_name: 'Someone Else', email: person.email },
  });

  expect(again.status()).toBe(409);

  // The refusal names who already holds it, so the fix is obvious: give that
  // person a membership rather than making a second copy of them.
  const body = await again.json();
  expect(body.data.user.id).toBe(person.id);

  await context.close();
});

test('a membership cannot name another client\'s site', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const person = await addPerson(context.request, nonce, 'Wrong Scope');
  const mine = await addClient(context.request, nonce, 'Mine');
  const theirs = await addClient(context.request, nonce, 'Theirs');
  const theirSite = await addSite(context.request, nonce, theirs.id, 'Their site');

  const refused = await grant(context.request, nonce, mine.id, {
    user_id: person.id,
    role: 'client_admin',
    client_site_id: theirSite.id,
  });

  // This is the grant M2 exists to make impossible: every scoped query built on
  // top of it later would have honoured it faithfully.
  expect(refused.status()).toBe(404);

  const held = await (
    await context.request.get(`/wp-json/blueworx-forge/v1/users/${person.id}/memberships`, {
      headers: { 'X-WP-Nonce': nonce },
    })
  ).json();
  expect(held.memberships).toHaveLength(0);

  await context.close();
});

test("a client's own people must use one of its permitted domains", async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const client = await addClient(context.request, nonce, 'Fussy Co', ['permitted.test']);
  const outsider = await addPerson(context.request, nonce, 'Outsider');
  const insider = await addPerson(context.request, nonce, 'Insider', 'permitted.test');

  const refused = await grant(context.request, nonce, client.id, {
    user_id: outsider.id,
    role: 'client_admin',
  });
  expect(refused.status()).toBe(400);

  const allowed = await grant(context.request, nonce, client.id, {
    user_id: insider.id,
    role: 'client_admin',
  });
  expect(allowed.status()).toBe(200);

  // Our own people are not held to the client's list: it is about their people.
  const staff = await grant(context.request, nonce, client.id, {
    user_id: outsider.id,
    role: 'staff',
  });
  expect(staff.status()).toBe(200);

  await context.close();
});

test('an invented role is refused rather than stored', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const person = await addPerson(context.request, nonce, 'Made Up');
  const client = await addClient(context.request, nonce, 'Roleless');

  const refused = await grant(context.request, nonce, client.id, {
    user_id: person.id,
    role: 'superuser',
  });

  expect(refused.status()).toBe(400);

  await context.close();
});

test('one person holds one role in one place', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const person = await addPerson(context.request, nonce, 'Once Only');
  const client = await addClient(context.request, nonce, 'Single Role');

  expect((await grant(context.request, nonce, client.id, { user_id: person.id, role: 'staff' })).status()).toBe(200);

  const again = await grant(context.request, nonce, client.id, {
    user_id: person.id,
    role: 'internal_viewer',
  });

  // Two rows would be two answers to "what may they do here", and #91 would
  // have to pick one.
  expect(again.status()).toBe(409);

  await context.close();
});

test('offboarding somebody ends their access everywhere at once', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const person = await addPerson(context.request, nonce, 'Leaving Soon');
  const one = await addClient(context.request, nonce, 'Client One');
  const two = await addClient(context.request, nonce, 'Client Two');

  await grant(context.request, nonce, one.id, { user_id: person.id, role: 'staff' });
  await grant(context.request, nonce, two.id, { user_id: person.id, role: 'staff' });

  const offboarded = await context.request.patch(`/wp-json/blueworx-forge/v1/users/${person.id}`, {
    headers: { 'X-WP-Nonce': nonce },
    data: { status: 'inactive', record_version: person.record_version },
  });
  expect(offboarded.status()).toBe(200);

  const live = await (
    await context.request.get(`/wp-json/blueworx-forge/v1/users/${person.id}/memberships`, {
      headers: { 'X-WP-Nonce': nonce },
    })
  ).json();
  expect(live.memberships).toHaveLength(0);

  // Ended, not deleted: what they did while they held it still resolves.
  const all = await (
    await context.request.get(
      `/wp-json/blueworx-forge/v1/users/${person.id}/memberships?status=all`,
      { headers: { 'X-WP-Nonce': nonce } },
    )
  ).json();
  expect(all.memberships).toHaveLength(2);
  expect(all.memberships.every((m) => m.status === 'inactive')).toBe(true);

  await context.close();
});

test('closing a client ends access to it and nothing else', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const person = await addPerson(context.request, nonce, 'Stays Elsewhere');
  const closing = await addClient(context.request, nonce, 'Closing Co');
  const staying = await addClient(context.request, nonce, 'Staying Co');

  await grant(context.request, nonce, closing.id, { user_id: person.id, role: 'staff' });
  await grant(context.request, nonce, staying.id, { user_id: person.id, role: 'staff' });

  await context.request.patch(`/wp-json/blueworx-forge/v1/clients/${closing.id}`, {
    headers: { 'X-WP-Nonce': nonce },
    data: { status: 'inactive', record_version: closing.record_version },
  });

  const live = await (
    await context.request.get(`/wp-json/blueworx-forge/v1/users/${person.id}/memberships`, {
      headers: { 'X-WP-Nonce': nonce },
    })
  ).json();

  expect(live.memberships).toHaveLength(1);
  expect(live.memberships[0].client_id).toBe(staying.id);

  await context.close();
});

test('closing one site ends the access scoped to it, and leaves the client-wide access', async ({
  browser,
  baseURL,
}) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const scoped = await addPerson(context.request, nonce, 'One Site Only');
  const wide = await addPerson(context.request, nonce, 'Whole Client');
  const client = await addClient(context.request, nonce, 'Two Site Co');
  const site = await addSite(context.request, nonce, client.id, 'Closing site');

  await grant(context.request, nonce, client.id, {
    user_id: scoped.id,
    role: 'staff',
    client_site_id: site.id,
  });
  await grant(context.request, nonce, client.id, { user_id: wide.id, role: 'staff' });

  await context.request.patch(`/wp-json/blueworx-forge/v1/client-sites/${site.id}`, {
    headers: { 'X-WP-Nonce': nonce },
    data: { status: 'inactive', record_version: site.record_version },
  });

  const scopedHeld = await (
    await context.request.get(`/wp-json/blueworx-forge/v1/users/${scoped.id}/memberships`, {
      headers: { 'X-WP-Nonce': nonce },
    })
  ).json();
  expect(scopedHeld.memberships).toHaveLength(0);

  // Untouched: it was never about that site, and ending it would cut somebody
  // off from the client's other sites because one of them closed.
  const wideHeld = await (
    await context.request.get(`/wp-json/blueworx-forge/v1/users/${wide.id}/memberships`, {
      headers: { 'X-WP-Nonce': nonce },
    })
  ).json();
  expect(wideHeld.memberships).toHaveLength(1);

  await context.close();
});

test('an edit made against a version that has moved is refused', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const person = await addPerson(context.request, nonce, 'Raced Against');

  const first = await context.request.patch(`/wp-json/blueworx-forge/v1/users/${person.id}`, {
    headers: { 'X-WP-Nonce': nonce },
    data: { display_name: `First edit ${RUN_ID}`, record_version: person.record_version },
  });
  expect(first.status()).toBe(200);

  const stale = await context.request.patch(`/wp-json/blueworx-forge/v1/users/${person.id}`, {
    headers: { 'X-WP-Nonce': nonce },
    data: { display_name: `Second edit ${RUN_ID}`, record_version: person.record_version },
  });
  expect(stale.status()).toBe(409);

  await context.close();
});

test('an edit that offboards still saves everything else it named', async ({ browser, baseURL }) => {
  const { context, nonce } = await signedInContext(browser, baseURL);
  const person = await addPerson(context.request, nonce, 'Renamed On The Way Out');

  const updated = await (
    await context.request.patch(`/wp-json/blueworx-forge/v1/users/${person.id}`, {
      headers: { 'X-WP-Nonce': nonce },
      data: {
        display_name: `Left the company ${RUN_ID}`,
        status: 'inactive',
        record_version: person.record_version,
      },
    })
  ).json();

  expect(updated.user.status).toBe('inactive');
  expect(updated.user.display_name).toBe(`Left the company ${RUN_ID}`);

  await context.close();
});

// ---------------------------------------------------------------------------
// PR 3 of the move out of WordPress admin: everything the People screen does,
// over REST. These mirror the admin page's handlers check for check, so the
// screen and the page cannot disagree about what is refused.
//
// Serial and sharing one signed-in caller, because the tests build on each
// other's people: the person added from an account is the one a later link is
// refused for.
// ---------------------------------------------------------------------------

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'admin';
const BASE = '/wp-json/blueworx-forge/v1';
const STAMP = RUN_ID.replace('-', '');

test.describe('the people screen, over REST', () => {
  test.describe.configure({ mode: 'serial' });

  let context;
  let api;
  let where;
  let staff;
  let fromAccount;
  let made = 0;

  /** A WordPress account nobody in Forge holds, made the way makePerson makes one. */
  async function makeAccount(label) {
    const login = `${label}${STAMP}${made++}`;
    const email = `${login}@example.test`;

    const wp = await api.request.post('/wp-json/wp/v2/users', {
      headers: api.headers,
      data: { username: login, email, password: PASSWORD, roles: ['subscriber'] },
    });
    expect(wp.status(), await wp.text()).toBe(201);

    return { id: (await wp.json()).id, login, email };
  }

  function remove(path) {
    return api.request.delete(`${BASE}${path}`, { headers: api.headers });
  }

  async function wpAccount(id) {
    const read = await api.request.get(`/wp-json/wp/v2/users/${id}?context=edit`, { headers: api.headers });
    expect(read.status(), await read.text()).toBe(200);
    return read.json();
  }

  test.beforeAll(async ({ browser, baseURL }) => {
    ({ context, api } = await signedIn(browser, baseURL, ADMIN_USER, ADMIN_PASS));
    where = await makeSite(api, 'People REST', RUN_ID);
    staff = await makePerson(api, where.client.id, 'staff', `staff${STAMP}`);
  });

  test.afterAll(async () => {
    await context?.close();
  });

  // --- Task 1: accounts, add from an account, link, add with an account ----

  test('an account nobody holds is offered, and adding them from it takes it off the list', async () => {
    const account = await makeAccount('free');

    const before = await api.get('/accounts');
    expect(before.ok).toBe(true);
    const offered = before.accounts.find((one) => one.id === account.id);
    expect(offered).toBeTruthy();
    expect(offered.login).toBe(account.login);
    expect(offered.user_email).toBe(account.email);

    // Somebody already here is not offered: their account is theirs.
    expect(before.accounts.map((one) => one.id)).not.toContain(staff.user.wp_user_id);

    const added = await api.post('/users/from-account', { wp_user_id: account.id });
    expect(added.status(), await added.text()).toBe(200);

    const answer = await added.json();
    expect(answer.ok).toBe(true);
    expect(answer.user.wp_user_id).toBe(account.id);
    expect(answer.user.email).toBe(account.email);
    expect(answer.user.account).toEqual({ id: account.id, login: account.login });
    expect(answer.memberships).toEqual([]);
    fromAccount = answer.user;

    const after = await api.get('/accounts');
    expect(after.accounts.map((one) => one.id)).not.toContain(account.id);
  });

  test('adding somebody new makes them a WordPress account with their name and address', async () => {
    const email = `newbie.${STAMP}@example.test`;
    const added = await api.post('/users', { display_name: `Newbie ${RUN_ID}`, email });
    expect(added.status(), await added.text()).toBe(200);

    const answer = await added.json();
    expect(answer.user.wp_user_id).toBeGreaterThan(0);
    expect(answer.user.account.login).toBeTruthy();
    expect(answer.memberships).toEqual([]);

    const account = await wpAccount(answer.user.wp_user_id);
    expect(account.email).toBe(email);
    expect(account.name).toBe(`Newbie ${RUN_ID}`);
  });

  test('adding from an account twice, or from an account that does not exist, is refused', async () => {
    const again = await api.post('/users/from-account', { wp_user_id: fromAccount.wp_user_id });
    expect(again.status()).toBe(409);
    expect((await again.json()).code).toBe('bwx_forge_user_exists');

    const nobody = await api.post('/users/from-account', { wp_user_id: 987654321 });
    expect(nobody.status()).toBe(404);
    expect((await nobody.json()).code).toBe('bwx_forge_unknown_account');
  });

  test('somebody with no account can be joined to a free one, or given a new one, and never one somebody else holds', async () => {
    // wp_user_id sent as 0 is the one way over REST to make somebody with no
    // account, the shape everybody added before #292 has.
    const added = await api.post('/users', {
      display_name: `Unlinked ${RUN_ID}`,
      email: `unlinked.${STAMP}@example.test`,
      wp_user_id: 0,
    });
    expect(added.status(), await added.text()).toBe(200);
    const person = (await added.json()).user;
    expect(person.wp_user_id).toBe(0);
    expect(person.account).toBeNull();

    const taken = await api.post(`/users/${person.id}/account`, {
      wp_user_id: fromAccount.wp_user_id,
      record_version: person.record_version,
    });
    expect(taken.status()).toBe(409);
    expect((await taken.json()).code).toBe('bwx_forge_user_exists');

    const nobody = await api.post(`/users/${person.id}/account`, {
      wp_user_id: 987654321,
      record_version: person.record_version,
    });
    expect(nobody.status()).toBe(400);
    expect((await nobody.json()).code).toBe('bwx_forge_no_account');

    const account = await makeAccount('joinme');
    const linked = await api.post(`/users/${person.id}/account`, {
      wp_user_id: account.id,
      record_version: person.record_version,
    });
    expect(linked.status(), await linked.text()).toBe(200);

    // An account that was already there wins: the person takes its name and
    // address, not the other way round.
    const answer = await linked.json();
    expect(answer.user.account.login).toBe(account.login);
    expect(answer.user.email).toBe(account.email);

    const stale = await api.post(`/users/${person.id}/account`, {
      wp_user_id: 0,
      record_version: person.record_version,
    });
    expect(stale.status()).toBe(409);
    expect((await stale.json()).code).toBe('bwx_forge_stale_write');

    // And the other way: nobody chosen means one is made from the record.
    const second = (
      await (
        await api.post('/users', {
          display_name: `Needs One ${RUN_ID}`,
          email: `needs.one.${STAMP}@example.test`,
          wp_user_id: 0,
        })
      ).json()
    ).user;

    const given = await api.post(`/users/${second.id}/account`, { wp_user_id: 0, record_version: second.record_version });
    expect(given.status(), await given.text()).toBe(200);
    const withAccount = (await given.json()).user;
    expect(withAccount.wp_user_id).toBeGreaterThan(0);
    expect(withAccount.account.login).toBeTruthy();
    expect((await wpAccount(withAccount.wp_user_id)).email).toBe(second.email);
  });

  test('somebody who is not an administrator sees no accounts and adds nobody', async ({ browser, baseURL }) => {
    const other = await signedIn(browser, baseURL, staff.login, PASSWORD);

    const read = await other.api.request.get(`${BASE}/accounts`, { headers: other.api.headers });
    expect(read.status()).toBe(403);

    const wrote = await other.api.post('/users/from-account', { wp_user_id: staff.user.wp_user_id });
    expect(wrote.status()).toBe(403);

    await other.context.close();
  });
});
