import { test, expect } from '@playwright/test';
import { signedIn, makeSite, makePerson, makeItem, PASSWORD } from './helpers/forge.js';

// #94. Isolation proved by negative tests rather than by inspection.
//
// Every test here is written from the outside, as the attempt somebody would
// actually make: not "the filter function returns the right set" but "this
// person asked for that record and was told it does not exist". The six routes
// the issue names each get their own block, and each block says what it would
// mean if it failed.
//
// The shape they all share: two clients that have nothing to do with each
// other, and somebody who belongs to one of them. Nothing about the second
// client should ever reach them — not the record, not its id, not the fact that
// the id is real.

const RUN = `iso${Date.now()}`;

/** Two unrelated clients, each with work on it, and the people who hold them. */
async function estate(browser, baseURL) {
  // The administrator's own signed-in context, held open for the suite. A bare
  // request context carries the nonce but not the login cookie, and WordPress
  // needs both to know who is asking.
  const asAdmin = await signedIn(
    browser,
    baseURL,
    process.env.WP_ADMIN_USER ?? 'admin',
    process.env.WP_ADMIN_PASS ?? 'wptest-admin-pw'
  );

  const admin = asAdmin.api;

  const a = await makeSite(admin, `${RUN} A`, RUN);
  const b = await makeSite(admin, `${RUN} B`, RUN);

  // A second site under client A, for the half of ARCH-3 that is not about
  // clients at all: a membership on one site does not reach its neighbour.
  const aSecond = await (
    await admin.post(`/clients/${a.client.id}/sites`, {
      name: `${RUN} A second`,
      url: 'https://example.test',
    })
  ).json();

  const onA = await makePerson(admin, a.client.id, 'staff', `holdsa${RUN}`);
  const onB = await makePerson(admin, b.client.id, 'staff', `holdsb${RUN}`);

  const itemA = await (await makeItem(admin, a.site.id, { title: 'A work', problem: 'A work.' })).json();
  const itemB = await (await makeItem(admin, b.site.id, { title: 'B work', problem: 'B work.' })).json();

  return {
    admin,
    adminContext: asAdmin.context,
    a,
    b,
    aSecond: aSecond.site,
    onA,
    onB,
    itemA: itemA.item,
    itemB: itemB.item,
  };
}

test.describe('tenant isolation', () => {
  test.describe.configure({ mode: 'serial' });

  let world;

  test.beforeAll(async ({ browser, baseURL }) => {
    world = await estate(browser, baseURL);
  });

  test.afterAll(async () => {
    await world?.adminContext?.close();
  });

  // -------------------------------------------------------------------------
  // Enumeration.
  // -------------------------------------------------------------------------

  test('listing the sites shows only the ones you hold', async ({ browser, baseURL }) => {
    const { context, api } = await signedIn(browser, baseURL, world.onA.login, PASSWORD);

    const listed = await api.get('/client-sites');
    const ids = listed.sites.map((site) => site.id);

    expect(ids).toContain(world.a.site.id);
    expect(ids).not.toContain(world.b.site.id);

    await context.close();
  });

  test('listing the clients shows only the ones you hold', async ({ browser, baseURL }) => {
    const { context, api } = await signedIn(browser, baseURL, world.onA.login, PASSWORD);

    const listed = await api.get('/clients');
    const ids = listed.clients.map((client) => client.id);

    expect(ids).toContain(world.a.client.id);
    expect(ids).not.toContain(world.b.client.id);

    await context.close();
  });

  // -------------------------------------------------------------------------
  // Filter injection: naming somebody else's record in a parameter.
  // -------------------------------------------------------------------------

  test('asking for another client\'s work by naming their site is refused', async ({ browser, baseURL }) => {
    const { context, api } = await signedIn(browser, baseURL, world.onA.login, PASSWORD);

    const answer = await api.request.get(
      `/wp-json/blueworx-forge/v1/work-items?client_site_id=${world.b.site.id}`,
      { headers: api.headers }
    );

    // 404 rather than 403: a refusal that says "not yours" confirms the id is
    // real, which is the disclosure ARCH-3 exists to prevent (D-1, D-2).
    expect(answer.status()).toBe(404);

    await context.close();
  });

  test('a parent on another client cannot be named when creating work', async ({ browser, baseURL }) => {
    const { context, api } = await signedIn(browser, baseURL, world.onA.login, PASSWORD);

    const created = await makeItem(api, world.a.site.id, {
      level: 'sub-feature',
      parent_id: world.itemB.id,
      title: 'Hanging off their work',
      problem: 'Trying to hang this off their work.',
    });

    expect(created.status()).toBe(404);

    await context.close();
  });

  // -------------------------------------------------------------------------
  // Direct ID access.
  // -------------------------------------------------------------------------

  test('another client\'s work item does not exist as far as you are concerned', async ({ browser, baseURL }) => {
    const { context, api } = await signedIn(browser, baseURL, world.onA.login, PASSWORD);

    const answer = await api.request.get(`/wp-json/blueworx-forge/v1/work-items/${world.itemB.id}`, {
      headers: api.headers,
    });

    expect(answer.status()).toBe(404);

    // And the answer is the same one an id nobody has ever used gets, so the
    // two cannot be told apart by comparing them.
    const invented = await api.request.get('/wp-json/blueworx-forge/v1/work-items/wit_nosuchrecord', {
      headers: api.headers,
    });

    expect(await answer.text()).toBe(await invented.text());

    await context.close();
  });

  test('a configuration route tells you nothing about whether an id is real', async ({ browser, baseURL }) => {
    const { context, api } = await signedIn(browser, baseURL, world.onA.login, PASSWORD);

    // Reading one client site is administration (ARCH-7), so this person is
    // refused whatever they name. That is not a weaker answer than a 404: the
    // refusal happens before the record is looked at, so it is the same refusal
    // for an id that exists and one that never has — which is what "tells you
    // nothing" means. The route is scoped as well, for the administrators who
    // do get past this door.
    const real = await api.request.get(`/wp-json/blueworx-forge/v1/client-sites/${world.b.site.id}`, {
      headers: api.headers,
    });
    const invented = await api.request.get('/wp-json/blueworx-forge/v1/client-sites/csite_nosuchrecord', {
      headers: api.headers,
    });

    expect(real.status()).toBe(invented.status());
    expect(await real.text()).toBe(await invented.text());

    await context.close();
  });

  test('moving another client\'s work is refused before the gates are even asked', async ({ browser, baseURL }) => {
    const { context, api } = await signedIn(browser, baseURL, world.onA.login, PASSWORD);

    const moved = await api.post(`/work-items/${world.itemB.id}/transition`, { to: 'client-request' });

    expect(moved.status()).toBe(404);

    await context.close();
  });

  // -------------------------------------------------------------------------
  // A site membership, which is narrower than a client one (ARCH-3).
  // -------------------------------------------------------------------------

  test('a membership on one site does not reach the client\'s other site', async ({ browser, baseURL }) => {
    const narrow = await makePerson(world.admin, world.a.client.id, 'staff', `narrow${RUN}`);

    // Held on the second site alone, rather than on the client.
    const held = (await world.admin.get(`/users/${narrow.id}/memberships`)).memberships[0];
    const scoped = await world.admin.patch(`/memberships/${held.id}`, {
      client_site_id: world.aSecond.id,
      record_version: held.record_version,
    });
    expect(scoped.status(), await scoped.text()).toBe(200);

    const { context, api } = await signedIn(browser, baseURL, narrow.login, PASSWORD);

    const listed = await api.get('/client-sites');
    const ids = listed.sites.map((site) => site.id);

    expect(ids).toContain(world.aSecond.id);
    expect(ids).not.toContain(world.a.site.id);

    await context.close();
  });

  // -------------------------------------------------------------------------
  // Replayed requests: one tenant's retry key must not answer another's.
  // -------------------------------------------------------------------------

  test('a retry key used on one client does not answer on another', async () => {
    const key = `${RUN}-shared-key`;

    const first = await world.admin.request.post('/wp-json/blueworx-forge/v1/work-items', {
      headers: { ...world.admin.headers, 'Idempotency-Key': key },
      data: {
        client_site_id: world.a.site.id,
        level: 'sub-feature',
        work_type: 'feature',
        title: 'First on A',
        problem: 'First, on A.',
      },
    });
    expect(first.status(), await first.text()).toBe(200);

    const second = await world.admin.request.post('/wp-json/blueworx-forge/v1/work-items', {
      headers: { ...world.admin.headers, 'Idempotency-Key': key },
      data: {
        client_site_id: world.b.site.id,
        level: 'sub-feature',
        work_type: 'feature',
        title: 'Second on B',
        problem: 'Second, on B.',
      },
    });
    expect(second.status(), await second.text()).toBe(200);

    const onA = (await first.json()).item;
    const onB = (await second.json()).item;

    // Two records, on their own sites. The failure this guards against is the
    // second call being answered with the first one's response, which would
    // hand a caller on B a record belonging to A.
    expect(onB.id).not.toBe(onA.id);
    expect(onB.client_site_id).toBe(world.b.site.id);
  });

  // -------------------------------------------------------------------------
  // A deactivated person: access ends, attribution survives.
  // -------------------------------------------------------------------------

  test('somebody offboarded loses access while what they did stays', async ({ browser, baseURL }) => {
    const leaver = await makePerson(world.admin, world.a.client.id, 'staff', `leaver${RUN}`);

    const asLeaver = await signedIn(browser, baseURL, leaver.login, PASSWORD);

    const made = await makeItem(asLeaver.api, world.a.site.id, { title: 'Done before leaving', problem: 'Done before leaving.' });
    expect(made.status(), await made.text()).toBe(200);
    const theirs = (await made.json()).item;

    await asLeaver.context.close();

    const person = (await world.admin.get(`/users/${leaver.id}`)).user;
    const ended = await world.admin.patch(`/users/${leaver.id}`, {
      status: 'inactive',
      record_version: person.record_version,
    });
    expect(ended.status(), await ended.text()).toBe(200);

    // Access is gone.
    const after = await signedIn(browser, baseURL, leaver.login, PASSWORD);
    const listed = await after.api.get('/client-sites');
    expect(listed.sites).toHaveLength(0);
    await after.context.close();

    // The work they did is still there, still attributed to them.
    const still = await world.admin.get(`/work-items/${theirs.id}`);
    expect(still.item.id).toBe(theirs.id);
    expect(still.history.length).toBeGreaterThan(0);
  });

  // -------------------------------------------------------------------------
  // #93. The cross-client grant, and the default absence of it.
  // -------------------------------------------------------------------------

  test('a studio user without the grant is scoped exactly like a client user', async ({ browser, baseURL }) => {
    const staff = await signedIn(browser, baseURL, world.onA.login, PASSWORD);
    const client = await makePerson(world.admin, world.a.client.id, 'client_admin', `cadmin${RUN}`);
    const theirs = await signedIn(browser, baseURL, client.login, PASSWORD);

    const staffSites = (await staff.api.get('/client-sites')).sites.map((s) => s.id).sort();
    const clientSites = (await theirs.api.get('/client-sites')).sites.map((s) => s.id).sort();

    expect(staffSites).toEqual(clientSites);

    await staff.context.close();
    await theirs.context.close();
  });

  test('the cross-client grant reaches every client, and only when granted', async ({ browser, baseURL }) => {
    const roamer = await makePerson(world.admin, world.a.client.id, 'staff', `roamer${RUN}`);

    const before = await signedIn(browser, baseURL, roamer.login, PASSWORD);
    const beforeIds = (await before.api.get('/client-sites')).sites.map((s) => s.id);
    expect(beforeIds).not.toContain(world.b.site.id);
    await before.context.close();

    const person = (await world.admin.get(`/users/${roamer.id}`)).user;
    const granted = await world.admin.patch(`/users/${roamer.id}`, {
      grants: ['cross_client'],
      record_version: person.record_version,
    });
    expect(granted.status(), await granted.text()).toBe(200);

    const after = await signedIn(browser, baseURL, roamer.login, PASSWORD);
    const afterIds = (await after.api.get('/client-sites')).sites.map((s) => s.id);
    expect(afterIds).toContain(world.b.site.id);
    await after.context.close();
  });

  test('a client administrator cannot be given the cross-client grant', async ({ browser, baseURL }) => {
    const client = await makePerson(world.admin, world.a.client.id, 'client_admin', `noroam${RUN}`);

    const person = (await world.admin.get(`/users/${client.id}`)).user;
    const granted = await world.admin.patch(`/users/${client.id}`, {
      grants: ['cross_client'],
      record_version: person.record_version,
    });

    // The column can be written — it is a person's, not a membership's — but it
    // grants nothing to somebody whose only memberships are the client's own.
    expect(granted.status(), await granted.text()).toBe(200);

    const { context, api } = await signedIn(browser, baseURL, client.login, PASSWORD);
    const ids = (await api.get('/client-sites')).sites.map((s) => s.id);

    expect(ids).not.toContain(world.b.site.id);

    await context.close();
  });
});
