import { test, expect } from '@playwright/test';

// #90 against a real WordPress. The unit tests prove the rules; these prove the
// thing the issue is actually about — that one person can work with two clients
// as two different things and still be one person.

const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'wptest-admin-pw';

// Nothing here is ever deleted and the instance is kept between runs, so every
// address and name carries the run with it. A suite that only passes against a
// freshly wiped database is a suite that will fail on its own leftovers — and
// an email address is unique, so a hardcoded one fails on the second run.
const RUN_ID = `${Date.now()}-${Math.floor(Math.random() * 1e6)}`;

async function signedInContext(browser, baseURL) {
  const context = await browser.newContext({ baseURL });
  const page = await context.newPage();

  await page.goto('/wp-login.php');
  await page.fill('#user_login', ADMIN_USER);
  await page.fill('#user_pass', ADMIN_PASS);
  await page.click('#wp-submit');
  await page.waitForURL((url) => !url.pathname.endsWith('/wp-login.php'));

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
